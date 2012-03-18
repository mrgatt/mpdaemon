<?php
/**
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
require_once(__DIR__ . '/luxbet/autoloader.php');
include_once(__DIR__ . '/external/system_daemon/System/Daemon.php');

Luxbet\Autoloader::register(__DIR__);
Luxbet\Autoloader::register(__DIR__.'/../');

Luxbet\Services\Config::$config_path = __DIR__.'/../config/';

/**
 * Tests to see if the given array has the given index set.
 * If not it returns the $default value
 *
 * @param array $array
 * @param integer $index
 * @param mixed $default value of variable or false
 * @return mixed|$default Returns true on success or false on failure if array is passed else the default value is returned
 */
function index_set($array, $index, $default = false) {
	if (is_array($array)) {
		return isset($array[$index]) ? $array[$index] : $default;
	}

	return $default;
}