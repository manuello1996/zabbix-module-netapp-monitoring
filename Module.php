<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))->getSubmenu()
			->insertAfter(_('Latest data'),
				(new CMenuItem(_('NetApp')))
					->setId('main-menu-netapp')
					->setAction('netapp.monitoring.list')
					->setAliases(['netapp.monitoring.view'])
			);
	}
}
