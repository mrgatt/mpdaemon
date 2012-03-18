<?php
/**
 * Demo Daemon
 *
 * @copyright (c) 2012, Luxbet Pty Ltd. All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
namespace Demo\Daemon;

use Luxbet\Services\Daemon,
	Luxbet\Services\Worker;

class DemoDaemon extends Daemon {

	/**
	 * Service group that this service belongs to.
	 * This will determine the name of the config file we load for this group.
	 * @var string
	 */
	protected $service_group = 'demo';

	/**
	 * Name of this service
	 * @var string
	 */
	protected $service_name = 'demodaemon';

	protected $service_description = 'Demo daemon';

	/**
	 * @param Worker $worker
	 */
	public function __construct(Worker $worker = null) {
		if (!$worker instanceOf Worker) {
			$worker = new DemoWorker;
		}

		parent::__construct($worker);
	}
}











