<?php declare(strict_types = 0);

namespace Modules\NetAppMonitoring\Actions;

/** Same validation and permission checks as the full page; only the layout differs. */
class CControllerNetAppSearch extends CControllerNetAppView {
	protected function doAction(): void {
		$this->respond(true);
	}
}
