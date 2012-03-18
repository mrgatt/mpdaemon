<?php
/**
 * Base class that all Daemon processes will be based on.
 * Bootstraps a multi-process daemon and provides the CLI option parsing.
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet\Services;

use \System_Daemon as SD;

abstract class Daemon {

	/**
	 * The worker object that will be doing our work in our forked children
	 * @var Worker
	 */
	protected $worker;

	/**
	 * The name of this service. Determines log filenames amongst other things.
	 * @var string
	 */
	protected $service_name;

	/**
	 * Overview description of what this service does
	 * @var string
	 */
	protected $service_description;

	/**
	 * Service group that this service belongs to. Configuration for daemons in
	 * a service group file is kept in the config/SERVICEGROUP.ini file
	 * @var string
	 */
	protected $service_group;

	/**
	 * Low level configuration for all daemon processes
	 * @var Config
	 */
	protected $daemon_config;

	/**
	 * UNIX timestamp of when this daemon started
	 * @var int
	 */
	protected $start_time;

	/**
	 * Configuration for this service daemon
	 * @var Config
	 */
	protected $service_config;

	/**
	 * The path and filename we should use for logging
	 * @var string
	 */
	protected $logfile;

	/**
	 * Option flags that can change the behaviour of this daemon.
	 * @var array
	 */
	protected $runtime_options = array();

	/**
	 * The Linux user ID the daemon will be running as
	 * @var int
	 */
	protected $run_as_user_id;

	/**
	 * The command line arguments supported by this Daemon.
	 * All callbacks get 2 arguments : name of the option & the value provided (if any)
	 * @var array
	 */
	protected $cli_options = array(
		'help' => array(
			'short_option' => 'h',
			'description' => 'This help',
			'callback' => 'display_help',
		),
		'write-init' => array(
			'short_option' => 'w',
			'description' => 'Generate and install an init.d autorun script.',
			'callback' => 'default_options_callback',
		),
		'no-daemon' => array(
			'short_option' => 'n',
			'description' => 'Do not daemonize the parent process, stay in the foreground',
			'callback' => 'default_options_callback',
		),
		'loglevel' => array(
			'short_option' => '',
			'description' => 'The log verbosity level. Overrides the ini file. 1-7 with 7 being DEBUG',
			'callback' => 'default_options_callback',
			'value_required' => true,
		),
	);

	/**
	 * @param Worker $worker
	 */
	public function __construct(Worker $worker = null) {
		if ($worker instanceOf Worker) {
			$this->worker = $worker;
		}
	}

	/**
	 * Display the available command line arguments and help notice
	 */
	protected function display_help() {
		global $argv;

		echo $this->service_description."\nUsage: $argv[0] [options]\nAvailable Options:\n";

		$items = array();
		foreach ($this->cli_options as $long_option => $option) {
			$item = array(
				'short_option' => ($option['short_option']) ? '-'.$option['short_option'] : '',
				'long_option' => '--'.$long_option,
				'value' => index_set($option, 'value_required') ? '[value]' : '',
				'description' => $option['description']
			);
			$items[] = $item;
		}

		foreach ($items as $item) {
			echo $item['short_option'];
			echo "\t";
			echo $item['long_option'].' ';
			echo ($item['value']) ?: "\t";
			if (strlen($item['long_option']) <= 6) {
				echo "\t";
			}
			echo "\t";
			echo $item['description'];
			echo "\n";
		}

		exit;
	}

	/**
	 * Simple callback for command line arguments
	 * @param string $option_name Name of the option flag
	 * @param mixed $value The value passed in or true if it is a flag based option with no default specified
	 */
	protected function default_options_callback($option_name, $value) {
		$this->runtime_options[$option_name] = $value;
	}

	/**
	 * Process any command line arguments
	 */
	protected function parse_command_line() {
		$valid_long_opts = array();
		$valid_short_opts = '';
		$short_options_lookup = array();

		foreach ($this->cli_options as $long_option => $option) {
			$valid_long_opts[] = (isset($option['value_required'])) ? $long_option.':' : $long_option;
			$valid_short_opts .= (isset($option['value_required']) && $option['short_option']) ? $option['short_option'].':' : $option['short_option'];
			$short_options_lookup[$option['short_option']] = $long_option;
		}

		foreach (getopt($valid_short_opts, $valid_long_opts) as $option => $value) {
			$option = isset($short_options_lookup[$option]) ? $short_options_lookup[$option] : $option;
			// We can't send a boolean false on the CLI. What we are really identifying here is that NO value was specified.
			// If we got no value then assume we are setting a flag so make the value true
			$value = ($value === false) ? true : $value;
			call_user_func_array(array($this, $this->cli_options[$option]['callback']), array($option, $value));
		}
	}

	/**
	 * @return array
	 */
	protected function daemon_config() {
		if (!$this->daemon_config) {
			$this->daemon_config = Config::instance('daemons')->section('global');
		}

		return $this->daemon_config;
	}

	/**
	 * @return Config
	 */
	protected function service_config() {
		if (!$this->service_config) {
			$this->service_config = new Config($this->service_group);
		}

		return $this->service_config;
	}

	/**
	 * Start up our main Daemon process
	 */
	public function execute() {
		$daemon_config = $this->daemon_config();
		$service_config = $this->service_config();

		$this->parse_command_line();

		try {
			$this->setup_system_daemon($service_config, $daemon_config);

			if (index_set($this->runtime_options, 'write-init')) {
				$this->install_init();
			} else {
				$this->start_time = time();

				// Fork and start our new 'parent' process.
				if (index_set($this->runtime_options, 'no-daemon')) {
					SD::info('Running in the foreground');
				} else {
					SD::start();
				}

				SD::info("Daemon: %s started.", $this->service_name);

				// We are now in our new 'parent', time to start managing our worker children.
				// Start our worker pool:
				$this->run($this->worker);

				SD::stop();
			}
		} catch (\Exception $e) {
			SD::crit($e->getMessage());
			echo 'Error: '.$e->getMessage();
		}
	}

	/**
	 * Setup System_Daemon to prepare our daemon process
	 * @param Config $service_config
	 * @param array $daemon_config
	 */
	protected function setup_system_daemon(Config $service_config, array $daemon_config) {
		$this->logfile = index_set($service_config->section('daemon'), 'logfile', $this->default_log_name());
		$this->run_as_user_id = $daemon_config['run_as_user_id'] ?: getmyuid();

		$default_log_level = index_set($service_config->section('daemon'), 'loglevel', SD::LOG_INFO);

		SD::setOptions(array(
			'appName' => $this->service_name,
			'usePEAR' => false,
			'authorName' => index_set($service_config->section('daemon'), 'author_name', ''),
			'appDescription' => $this->service_description,
			'authorEmail' => index_set($service_config->section('daemon'), 'author_email', ''),
			'appRunAsUID' => $this->run_as_user_id,
			'logLocation' => $this->logfile,
			'logPhpErrors' => true,
			'logFilePosition' => true,
			'logVerbosity' =>  index_set($this->runtime_options, 'loglevel', $default_log_level),
		));
	}

	/**
	 * Write out the init.d script as well as a logrotation script.
	 * ->setup_system_daemon() needs to have been called prior to this.
	 */
	protected function install_init() {
		SD::info('Just writing init file');
		if (SD::writeAutoRun()) {
			$this->write_logrotate_config();
		}
		exit(0);
	}

	/**
	 * Generate and write a logrotate configuration script.
	 */
	protected function write_logrotate_config() {
		// Rotate the log daily, keep 30 days worth of files.
		// Really, the daemons should not be logging too much unless left in debug mode.
		$config = <<< EOT
$this->logfile {
	daily
	rotate 30
	copytruncate
	delaycompress
	compress
	notifempty
	missingok
	dateext
}
EOT;
		$default_path = '/etc/logrotate.d';

		if (file_exists($default_path)) {
			$conf_file_name = $default_path.'/'.$this->service_name;
			if (file_exists($conf_file_name)) {
				SD::notice('Log rotation configuration not installed. Logrotate configuration already exists');
			} else {
				if (file_put_contents($conf_file_name, $config)) {
					SD::notice('Logrotate configuration written to %s', $conf_file_name);
				} else {
					SD::warning('Unable to write logrotate configuration. Check file permissions.');
				}
			}
		} else {
			SD::warning('Unable to write logrotate configuraton. %s does not exist', $default_path);
		}
	}

	/**
	 * Get the default logfile name for ths service
	 * @return string
	 */
	protected function default_log_name() {
		return '/tmp/'.$this->service_name.'.log';
	}

	/**
	 * Start our endless loop to bring up and maintain our children processes.
	 * This method is run within the newly forked parent process.
	 * @param Worker $worker The child worker instance we will be forking in further children
	 */
	public function run(Worker $worker) {
		$worker_pool = new WorkerProcessPool($worker, $this->service_config());
		$still_running = true;
		declare(ticks = 1);

		while ($still_running && !SD::isDying()) {
			$still_running = $worker_pool->run();
			SD::iterate(1);
		}
	}

}
