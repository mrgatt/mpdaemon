<?php
/**
 * Demo Daemon worker.
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Demo\Daemon;

use Luxbet\Services\Worker;

class DemoWorker extends Worker {

	/**
	 * Run our event loop every 2 seconds
	 * @var float
	 */
	protected $iteration_delay_seconds = 2;

	/**
	 * Our worker data
	 * @var integer
	 */
	private $counter;

	public function init() {
		// Include additional php files and create all
		// of our required objects and datastructures here
		$this->info('Init called');

		$this->counter = 0;
	}

	public function run() {
		// Perform whatever work we want done on each worker iteration
		$this->info('Iterating '.++$this->counter);
	}

	public function cleanup() {
		// Close connections to other systems and any other cleanup
		$this->info('Cleanup called');
	}

}

