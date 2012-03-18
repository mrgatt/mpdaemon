#!/usr/bin/env php
<?php
/**
 * Demo Daemon
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
require_once(__DIR__ . '/../../lib/bootstrap.php');

$daemon = new Demo\Daemon\DemoDaemon();
$daemon->execute();
