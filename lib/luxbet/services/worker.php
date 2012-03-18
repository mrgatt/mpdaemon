<?php
/**
 * Base class for worker processes;
 * Worker processes are typically forked by WorkerProcessPool however they can be used independently as well.
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet\Services;

use \System_Daemon as SD;

// signal handlers require this
declare(ticks=1);

abstract class Worker {

	/**
	 * A flag to indicate if our worker should continue processing or not
	 * @var boolean
	 */
	protected $keep_running = true;

	/**
	 * The PID of the process that forked this worker (the parent)
	 * @var integer
	 */
	protected $parent_pid;

	/**
	 * The amount of time (seconds) we will sleep between iterations in the execute() method.
	 * This can be expressed as a fraction of a second too, for example 0.5 for half a second
	 * @var float
	 */
	protected $iteration_delay_seconds = 1;

	/**
	 * Memory limit in MB that this worker is allowed to consume
	 * @var float
	 */
	protected $memory_limit = 10;

	/**
	 * UNIX timestamp of when this worker was started
	 * @var integer
	 */
	protected $start_time;

	/**
	 * Custom title to show in process listings
	 * @var string
	 */
	protected $process_title = '';

	/**
	 * How often in minutes we will log memory usage, beyond the normal debug logging
	 */
	const LOG_MEMORY_USAGE_DURATION = 60;

	/**
	 * Main loop for our child worker process.
	 * Calls the abstract function run() that does the real work of this child.
	 *
	 * @return bool
	 */
	public function execute() {
		// Install signal handlers for things directed at our child process itself
		foreach (array(SIGTERM, SIGHUP) as $signal) {
			pcntl_signal($signal, array($this, 'signal_handler'));
		}

		$this->start_time = time();

		$this->init();

		// Main loop for the worker
		while ($this->keep_running && !SD::isDying()) {
			if (!$this->is_parent_alive()) {
				$this->warning('Child appears to be orphaned, exiting');
				$this->stop();
			} else {
				$memory_usage = $this->check_memory_usage();
				$this->update_process_title($memory_usage, $this->process_title);
				$this->run();

				SD::iterate($this->iteration_delay_seconds);
			}
		}

		$this->cleanup();

		return true;
	}

	/**
	 * If we can, give our process a nicer name and update with the memory usage and when it was started.
	 * This only works if the 'proctitle' PHP extension is installed and it updates it every 5 minutes.
	 * @param float $memory_usage Memory usage in bytes
	 */
	protected function update_process_title($memory_usage) {
		static $base_process_name = null;
		static $last_update_time = null;
		// Update the title every 2 minutes
		static $update_frequency = 120;

		if (function_exists('setproctitle')) {
			if ($base_process_name === null) {
				// initialise this function
				$last_update_time = time() - $update_frequency;
				$title = ($this->process_title) ?: get_class($this);
				$base_process_name = basename($_SERVER['argv'][0]).' '.$title.' [%d KB used, started '.date('Y-m-d G:i:s', $this->start_time).']';
			}

			if (time() - $last_update_time >= $update_frequency) {
				$last_update_time = time();
				setproctitle(sprintf($base_process_name, $memory_usage / 1024));
			}
		}
	}

	/**
	 * Set the maximim amount of memory (in MB) this worker is allowed to use
	 * @param float $limit
	 */
	public function set_memory_limit($limit) {
		$this->debug('The memory limit has been set to '.$limit);
		$this->memory_limit = (float)$limit;
	}

	/**
	 * Check if our worker has exceeded the amount of memory we have allowed for it to use.
	 * If it has we will flag this worker for termination.
	 * @return float Current memory usage in bytes
	 */
	protected function check_memory_usage() {
		static $last_check_time = null;

		if (!$last_check_time) {
			$last_check_time = time();
		}

		$current_mem_usage = memory_get_usage();
		$limit_bytes = $this->memory_limit * 1024 * 1024;

		$message = round($current_mem_usage / 1024, 2).'KB memory in use, limit is '.($this->memory_limit * 1024);

		// if it has been LOG_MEMORY_USAGE_DURATION minutes or more, issue an info message
		if ((time() - $last_check_time) / 60 >= self::LOG_MEMORY_USAGE_DURATION) {
			$last_check_time = time();
			$this->info($message);
		} else {
			$this->debug($message);
		}

		if ($current_mem_usage >= $limit_bytes) {
			$this->warning('Memory limit of '.$this->memory_limit.'MB exceeded, exiting');
			$this->stop();
		}

		return $current_mem_usage;
	}

	/**
	 * Keep track of the PID of our parent process
	 * @param integer $parent_pid
	 */
	public function set_parent($parent_pid) {
		$this->parent_pid = $parent_pid;
	}

	/**
	 * See if our parent process is still alive.
	 * Note: this will not work under all circumstances.
	 * In theory enough new processes could be started in the mean time that they could reclaim the parent PID.
	 * This is unlikely in practice though.
	 * @return boolean
	 */
	protected function is_parent_alive() {
		$this->debug('Checking parent pid:'.$this->parent_pid);
		// Send a fake signal to our parent to probe it for life.
		// See the kill(2) man page if you don't believe me.
		return posix_kill($this->parent_pid, 0);
	}

	/**
	 * Called immediately before the execute() method for setup purposes
	 */
	abstract public function init();

	/**
	 * The actual work being done by this child on each iteration.
	 * If the implemented run() method has its own loop then it needs to be sure to respect the $this->keep_running flag
	 */
	abstract public function run();

	/**
	 * Gracefully stop the worker
	 */
	public function stop() {
		$this->keep_running = false;
	}

	/**
	 * Called when the worker process is exiting, to attempt graceful shutdown.
	 */
	abstract public function cleanup();

	/**
	 * Log a message of particular severity to the parent processes log
	 * @param integer $severity See System_Daemon::LOG_*
	 * @param string $message
	 */
	private function log($severity, $message) {
		SD::log($severity, 'Child '.getmypid().' '.$message);
	}

	/**
	 * Log a message of severity 'info' to the parent processes log
	 * @param string $message
	 */
	public function info($message) {
		$this->log(SD::LOG_INFO, $message);
	}

	/**
	 * Log a message of severity 'notice' to the parent processes log
	 * @param string $message
	 */
	public function notice($message) {
		$this->log(SD::LOG_NOTICE, $message);
	}

	/**
	 * Log a message of severity 'warning' to the parent processes log
	 * @param string $message
	 */
	public function warning($message) {
		$this->log(SD::LOG_WARNING, $message);
	}

	/**
	 * Log a message of severity 'err' to the parent processes log
	 * @param string $message
	 */
	public function err($message) {
		$this->log(SD::LOG_ERR, $message);
	}

	/**
	 * Log a message of severity 'debug' to the parent processes log
	 * @param string $message
	 */
	public function debug($message) {
		if ($this->loglevel() == 'DEBUG') {
			$this->log(SD::LOG_DEBUG, $message);
		}
	}

	/**
	 * Get the current log level
	 * Translates the System_Daemon log level integers
	 * @return string Log level
	 */
	public function loglevel() {
		// in theory this could change at runtime, but in practice we never do that
		static $log_level = null;

		if ($log_level === null) {
			switch (SD::getOption('logVerbosity')) {
				case SD::LOG_DEBUG:
					$log_level = 'DEBUG';
					break;
				case SD::LOG_ALERT:
					$log_level = 'ALERT';
					break;
			    case SD::LOG_EMERG:
					$log_level = 'EMERG';
					break;
				case SD::LOG_CRIT:
					$log_level = 'CRIT';
					break;
				case SD::LOG_ERR:
					$log_level = 'ERR';
					break;
				case SD::LOG_WARNING:
					$log_level = 'WARNING';
					break;
				case SD::LOG_NOTICE:
					$log_level = 'NOTICE';
					break;
				case SD::LOG_INFO:
					$log_level = 'INFO';
					break;
				default:
					$log_level = 'UNKNOWN';
			}
		}

		return $log_level;
	}

	/**
	 * Signal handler for this child process
	 * @param int $signal_num
	 */
	public function signal_handler($signal_num) {
		switch ($signal_num) {
			case SIGHUP: // Fall through, die off if we are told to restart as our parent is going to restart the worker
			case SIGTERM:
				$this->stop();
				$this->info('Received exit signal');
				break;

		}
	}
}
