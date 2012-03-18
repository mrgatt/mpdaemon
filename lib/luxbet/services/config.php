<?php
/**
 * Simple .ini based config system.
 *
 * Example:
 *
 * $daemon_config = Config::instance('daemon');
 *
 * $daemon_config['section']['variable']
 *
 * $user_id = $daemon_config['global']['run_as_user_id'];
 *
 * $daemon_global_config = $daemon_config['global'];
 * OR
 * $daemon_global_config = $daemon_config->section('global');
 *
 * $user_id = $daemon_global_config['run_as_user_id'];
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet\Services;

class Config implements \ArrayAccess, \Iterator {

	/**
	 * Where our ini files can be found
	 * @var string
	 */
	public static $config_path;

	/**
	 * The group of processes that this process belongs to
	 * @var string
	 */
	protected $service_group;

	/**
	 * @var Config\Loader
	 */
	protected $loader;

	/**
	 * The parsed contents of the config file
	 * @var array
	 */
	protected $config = array();

	/**
	 * @var bool
	 */
	protected $loaded = false;

	/**
	 * Array of config instances. One per service group/ini file
	 * @var array
	 */
	protected static $instances = array();

	protected $sections = array();

	protected $postion = 0;

	/**
	 * @param string $service_group
	 */
	public function __construct($service_group) {
		$this->service_group = $service_group;
		$this->position = 0;
	}

	/**
	 * @param Config\Loader $loader
	 * @return Config\Loader $loader
	 */
	public function loader(Config\Loader $loader = null) {
		if ($loader) {
			$this->loader = $loader;
		}

		if (!$this->loader) {
			$this->loader = new Config\Loader;
		}

		return $this->loader;
	}

	/**
	 * Returns an instance of a config object for a service group. The service
	 * group name should be the same as the config file.
	 * @param string $service_group
	 * @return Config
	 */
	public static function instance($service_group) {
		if (!index_set(self::$instances, $service_group)) {
			self::$instances[$service_group] = new self($service_group);
		}
		return self::$instances[$service_group];
	}

	/**
	 * Read and parse the config file
	 */
	protected function load() {
		$this->config = $this->loader()->load($this->service_group);
		$this->loaded = true;
		$this->sections = array_keys($this->config);
	}

	/**
	 * Returns the configuration for a section within an ini file
	 * @param string $section_name
	 * @return array
	 */
	public function section($section_name) {
		if (!$this->loaded) {
			$this->load();
		}

		return index_set($this->config, $section_name, array());
	}

	/**
	 * @param string $section_name
	 * @return bool
	 */
	public function offsetExists($section_name) {
		if (!$this->loaded) {
			$this->load();
		}

		return isset($this->config[$section_name]);
	}

	/**
	 * @param string $section_name
	 * @return mixed
	 */
	public function offsetGet($section_name) {
		return $this->section($section_name);
	}

	public function offsetSet($key, $value) {

	}

	/**
	 * @param string $section_name
	 */
	public function offsetUnset($section_name) {
		unset($this->config[$section_name]);
	}

	public function rewind() {
		$this->position = 0;
	}

	public function current() {
		return $this->config[$this->sections[$this->position]];
	}

	public function next() {
		++$this->position;
	}

	public function key() {
		return $this->sections[$this->position];
	}

	public function valid() {
		return isset($this->sections[$this->position]);
	}
}
