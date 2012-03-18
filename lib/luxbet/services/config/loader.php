<?php
/**
 * Stub ini config file loader.
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Luxbet\Services\Config;

use Luxbet\Services\Config;

class Loader {

	/**
	 * @param string $filename
	 * @return array
	 */
	public function load($filename) {
		$config_file = Config::$config_path.$filename . '.ini';

		if (!file_exists($config_file)) {
			throw new \Exception('Config file not found, looking for: ' . $config_file);
		}

		return parse_ini_file($config_file, true);
	}
}