<?php
/**
 * Bare bones autoloader
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet;

class Autoloader {
	private $base_dir;

	/**
	 * @param string $base_directory Base directory where the source files are located.
	 */
	public function __construct($base_directory = null) {
		$this->base_dir = $base_directory ?: dirname(__FILE__);
	}

	/**
	 * Registers the autoloader class with the PHP SPL autoloader.
	 * @param string $base_directory Base directory where the source files are located.
	 */
	public static function register($base_directory) {
		spl_autoload_register(array(new self($base_directory), 'autoload'));
	}

	/**
 	 * Loads a class from a file using its fully qualified name.
	 *
	 * @param string $class_name Fully qualified name of a class.
	 * @return bool
	 */
	public function autoload($class_name) {
		$class_name_parts = explode('\\', $class_name);

		$path = $this->base_dir .
			DIRECTORY_SEPARATOR .
			implode(DIRECTORY_SEPARATOR, $class_name_parts) .
			'.php';

		$path = strtolower($path);
		if (file_exists($path)) {
			include($path);
			return true;
		}

		return false;
	}
}
