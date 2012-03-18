<?php
/**
 * Starts and maintains our children worker processes
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet\Services;

use \System_Daemon as SD;

// signal handlers require this
declare(ticks=1);

class WorkerProcessPool {

	/**
	 * List of our current children PID's
	 * @var array
	 */
	protected $children = array();

	/**
	 * Our current PID
	 * @var integer
	 */
	private $parent_pid;

	/**
	 * Instance of the worker object that we will be running in each of our children
	 * @var Worker
	 */
	private $child_worker;

	/**
	 * Config instance for the current service
	 * @var Config
	 */
	private $config;

	/**
	 * A flag used to determine the return value of the run() method.
	 * so we can tell our main process controller if we are finished or not.
	 * @var boolean
	 */
	private $is_exiting = false;

	/**
	 * Keep track of how many children have died within a second of starting
	 * @var integer
	 */
	private $quick_terminations = 0;

	const CHILD_FORK_ERROR = -1;

	/**
	 * If we detect more than this many children dying quickly we will exit this daemon
	 * as we will assume this is an error.
	 */
	const RUNAWAY_FORKING_LIMIT = 10;

	/**
	 * How long (seconds) we will wait for children to finish what they are doing before SIGKILL'ing them
	 * @var int
	 */
	protected $child_exit_wait = 10;

	/**
	 * @param Worker $child_worker
	 * @param Config $config
	 */
	public function __construct(Worker $child_worker, Config $config) {
		posix_setsid();

		$this->parent_pid = posix_getpid();

		if (!($child_worker instanceOf Worker)) {
			throw new \Exception('The child task must be a Services\Worker');
		}

		$this->child_worker = $child_worker;
		$this->child_worker->set_parent($this->parent_pid);
		$this->config = $config;

		$this->register_signals();
	}

	/**
	 * Register handlers for the signals we want to trap in this process.
	 */
	protected function register_signals() {
		// Signal handler for dealing with signals emitted from our children
		pcntl_signal(SIGCHLD, array($this, 'child_signal_handler'));

		// Install signal handlers for things directed at our parent process itself
		foreach (array(SIGTERM, SIGHUP) as $signal) {
			pcntl_signal($signal, array($this, 'signal_handler'));
		}
	}

	/**
	 * Keep watch on our children.
	 * This method is called every iteration of our parent process.
	 * @return boolean If false indicates we are terminating this worker pool
	 */
	public function run() {
		if ($this->is_exiting) {
			// returning false tells our process controller that we are finishing
			$return = false;
		} else {
			// if we haven't defined the number of children to run, just run one
			// this mirrors an old school single-process service
			$max_children = index_set($this->config->section('daemon'), 'num_workers', 1);

			// check for any exited children
			$this->child_signal_handler();

			$children_count = count($this->children);

			// check we have all our children
			if ($children_count < $max_children) {

				$this->check_runaway_forking();

				for ($i = $children_count; $i < $max_children; $i++) {
					if (!$this->launch_child($this->child_worker)) {
						SD::err('Could not launch new child, exiting');
						$this->signal_handler(SIGTERM);
						break;
					}
				}
			} else if ($children_count > $max_children) {
				// We have too many children running, we need to cull some
				$diff = $children_count - $max_children;
				SD::notice('We have %d children, only require %d. Killing %d children', $children_count, $max_children, $diff);
				$children = array_combine(range(0, $children_count - 1), array_keys($this->children));
				for ($i = 0; $i < $diff; $i++) {
					SD::info('Killing redundant child %d', $children[$i]);
					$this->kill_child($children[$i]);
				}
			}

			$return = true;
		}

		return $return;
	}

	/**
	 * Check if we have hit our threshold for quickly exiting child processes.
	 * If the threshold is met we will shutdown this daemon.
	 */
	protected function check_runaway_forking() {
		if ($this->quick_terminations >= self::RUNAWAY_FORKING_LIMIT) {
			SD::crit('Runaway forking detected, exiting');
			$this->signal_handler(SIGTERM);
		}
	}

	/**
	 * Fork a new child process and add it to our list of children.
	 * @param Worker $worker
	 * @return int|boolean Child PID or false on error
	 */
	protected function launch_child(Worker $worker) {
		$child_pid = pcntl_fork();

		if ($child_pid == self::CHILD_FORK_ERROR) {
			return false;
		} else if ($child_pid) {
			$this->children[$child_pid] = time();
			SD::info('New child with PID %d launched', $child_pid);
			return $child_pid;
		} else {
			// We are now the child process, lets get our child task going
			$status = $worker->execute();
			// exit code of 0 means OK, not 0 means error.
			exit(($status) ? 0 : 1);
		}
	}

	/**
	 * Kill the child process that has the given PID
	 * @param int $child_pid The PID of the child we want to kill
	 * @param int $signal What POSIX kill signal to send
	 */
	protected function kill_child($child_pid, $signal = SIGTERM) {
		SD::info('Killing child %d with signal %d', $child_pid, $signal);
		posix_kill($child_pid, $signal);
		// We don't need to do anything else as killing the child will emit a signal handled by child_signal_handler()
	}

	/**
	 * Kill all of our registered child processes
	 */
	public function kill_all_children() {
		foreach (array_keys($this->children) as $child_pid) {
			// When a child dies it will call the child_signal_handler() callback which will remove it from the
			// list of children running
			$this->kill_child($child_pid, SIGTERM);
		}

		// Give the children a little bit of time to go away
		$check_iterations = $this->child_exit_wait;
		while (count($this->children) && --$check_iterations != 0) {
			SD::info('Waiting for children to die');
			sleep(1);
			$this->child_signal_handler();
		}

		// We still have children? Kill them for good then
		if (count($this->children)) {
			SD::warning('%d did not die gracefully, killing them.', count($this->children));
			foreach (array_keys($this->children) as $child_pid) {
				$this->kill_child($child_pid, SIGKILL);
			}
		}
	}

	/**
	 * Signal handler for signals directed at this particular parent process
	 * @param int $signal_num
	 */
	public function signal_handler($signal_num) {
		switch ($signal_num) {
			case SIGHUP: // don't exit the parent process, we just want to kill all our children and start over
				SD::info("Parent restarting all children");
				$this->kill_all_children();
				break;

			case SIGTERM:
				SD::info("Parent exiting");
				$this->is_exiting = true;
				$this->kill_all_children();
				break;
		}
	}

	/**
	 * Signal handler for signals emitted by our child processes.
	 * We also call this as necessary to pick up signals emitted within loops and the like.
	 */
	public function child_signal_handler() {
		$child_pid = pcntl_wait($status, WNOHANG);
		while ($child_pid > 0) {
			SD::debug('child_signal_handler called for child %d', $child_pid);
			if (isset($this->children[$child_pid])) {
				// Make sure our children are not all dying off quickly
				if (!$this->is_exiting) {
					$child_time = $this->children[$child_pid];
					if (time() - $child_time <= 1) {
						// keep track of how many children we have had die on us very quickly
						$this->quick_terminations++;
					} else {
						// If the child has died after running a long time then there must be no real problem with child processes
						$this->quick_terminations = 0;
					}
				}

				$exit_code = pcntl_wexitstatus($status);
				$message = "$child_pid exited with status $exit_code";
				// change the type of log message based on the exit code
				// 0 means the process exited normally
				// non-0 means the process exited with an error
				if ($exit_code == 0) {
					SD::info($message);
				} else {
					SD::warning($message);
				}

				unset($this->children[$child_pid]);
			}

			// make sure we have no more signals to process
			$child_pid = pcntl_wait($status, WNOHANG);
		}
    }

	/**
	 * Flag that this process is to stop
	 */
	protected function stop() {
		$this->is_exiting = true;
	}

	/**
	 * Is this process in the midst of stopping?
	 * @return boolean
	 */
	protected function stopping() {
		return $this->is_exiting;
	}
}