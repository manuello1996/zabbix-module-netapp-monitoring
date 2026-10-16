<?php
namespace Modules\NetAppMonitoring\Includes;

/** Server-rendered, permission-scoped NetApp monitoring components. */
class NetAppRenderer {
	public static function url(array $args = []): \CUrl {
		$url = (new \CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.view');
		foreach ($args as $key => $value) {
			$url->setArgument($key, $value);
		}
		return $url;
	}

	public static function latest(string $hostid): \CUrl {
		return (new \CUrl('zabbix.php'))->setArgument('action', 'latest.view')
			->setArgument('hostids', [$hostid])->setArgument('filter_set', 1);
	}

	public static function problemsUrl(string $hostid): \CUrl {
		return (new \CUrl('zabbix.php'))->setArgument('action', 'problem.view')
			->setArgument('hostids', [$hostid])->setArgument('filter_set', 1);
	}

	public static function clock($clock): string {
		return (int) $clock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $clock) : _('No data');
	}

	public static function value(?array $item, bool $state = false) {
		if ($item && ((int) ($item['state'] ?? 0) !== 0 || (int) ($item['status'] ?? 0) !== 0)) {
			return (new \CSpan((int) ($item['status'] ?? 0) !== 0 ? _('Disabled') : _('Unsupported')))
				->addClass('netapp-monitoring-state-warning')->setTitle((string) ($item['error'] ?? ''));
		}
		if (!$item || $item['lastvalue'] === null || !(int) $item['lastclock']) {
			return (new \CSpan(_('No data')))->addClass('netapp-monitoring-muted');
		}
		$text = NetAppFormatter::itemValue($item);
		$span = new \CSpan($text);
		if ($state) {
			$healthy = preg_match('/^(ok|online|up|present|started|running|healthy|true|member|normal)$/i', trim((string) $text));
			$span->addClass('netapp-monitoring-state')->addClass($healthy
				? 'netapp-monitoring-state-good' : 'netapp-monitoring-state-warning');
		}
		return $span->setTitle(_('Last update').': '.self::clock($item['lastclock']));
	}

	public static function stat(string $label, $value): \CDiv {
		return (new \CDiv([
			(new \CDiv($label))->addClass('netapp-monitoring-statseg-label'),
			(new \CDiv($value))->addClass('netapp-monitoring-card-value')
		]))->addClass('netapp-monitoring-statseg');
	}

	public static function section(string $title, $content): \CDiv {
		return (new \CDiv([(new \CTag('h4', true, $title))->addClass('netapp-monitoring-section-title'), $content]))
			->addClass('netapp-monitoring-section');
	}

	public static function capacity(array $capacity): \CDiv {
		$percent = $capacity['percent'] ?? null;
		$items = [new \CSpan(NetAppFormatter::bytes($capacity['used'] ?? null).' / '.
			NetAppFormatter::bytes($capacity['size'] ?? null))];
		if ($percent !== null) {
			$items[] = new \CSpan(NetAppFormatter::percent($percent));
			$items[] = (new \CDiv((new \CDiv())->addClass('netapp-monitoring-progress-fill')
				->addClass($percent >= 90 ? 'netapp-monitoring-progress-critical' : '')
				->setAttribute('style', 'width: '.number_format(max(0, min(100, $percent)), 2, '.', '').'%')))
				->addClass('netapp-monitoring-progress');
		}
		return (new \CDiv($items))->addClass('netapp-monitoring-capacity');
	}

	public static function metricTable(array $metrics): \CTableInfo {
		$table = (new \CTableInfo())->setHeader([_('Metric'), _('Latest value'), _('Last update'), _('History')]);
		foreach ($metrics as $key => $item) {
			if (!$item) {
				continue;
			}
			$history = (new \CUrl('history.php'))->setArgument('action',
				in_array((int) $item['value_type'], [0, 3], true) ? 'showgraph' : 'showvalues')
				->setArgument('itemids', [$item['itemid']]);
			$table->addRow([$item['name'], self::value($item, strpos($key, 'state') !== false || $key === 'status' || $key === 'controller.over_temperature'),
				self::clock($item['lastclock']), new \CLink(_('History'), $history)]);
		}
		return $table;
	}

	public static function tabs(array $data): \CTag {
		$tabs = [];
		foreach (['overview' => _('Overview'), 'nodes' => _('Nodes'), 'svms' => _('SVMs'),
			'volumes' => _('Volumes'), 'luns' => _('LUNs'), 'ethernet' => _('Ethernet'), 'fc' => _('Fibre Channel'),
			'hardware' => _('Hardware'), 'performance' => _('Performance'), 'problems' => _('Problems')] as $key => $label) {
			$active = $data['tab'] === $key;
			$tabs[] = (new \CLink($label, self::url(['hostid' => $data['host']['hostid'], 'tab' => $key])))
				->addClass('netapp-monitoring-tab')->addClass($active ? 'netapp-monitoring-tab-active' : '')
				->setAttribute('aria-current', $active ? 'page' : 'false');
		}
		return (new \CTag('nav', true, $tabs))->addClass('netapp-monitoring-tabs')
			->setAttribute('aria-label', _('NetApp sections'));
	}

	public static function filter(array $data, bool $list = false): \CForm {
		$form = (new \CForm('get'))->cleanItems()->addVar('action', $list ? 'netapp.monitoring.list' : 'netapp.monitoring.view');
		if (!$list) {
			$form->addVar('hostid', $data['host']['hostid'])->addVar('tab', $data['tab']);
		}
		$form->addVar('sort', $data['sort'] ?? 'name')->addVar('sortorder', $data['sortorder'] ?? 'ASC');
		$form->addItem((new \CDiv([
			new \CLabel($list ? _('Search hosts') : _('Search inventory'), 'netapp-search'),
			(new \CTextBox('search', $data['search'] ?? ''))->setId('netapp-search')->addClass('netapp-monitoring-search'),
			new \CSubmit('filter_set', _('Apply')),
			new \CLink(_('Reset'), $list ? (new \CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list')
				: self::url(['hostid' => $data['host']['hostid'], 'tab' => $data['tab']]))
		]))->addClass('netapp-monitoring-filterbar'));
		return $form;
	}

	public static function inventory(array $data, array $groups): \CDiv {
		$rows = [];
		foreach ($groups as $group) {
			foreach ($data['snapshot']['entities'][$group] ?? [] as $row) {
				$row['_group'] = $group;
				$rows[] = $row;
			}
		}
		$search = trim($data['search'] ?? '');
		if ($search !== '') {
			$rows = array_values(array_filter($rows, static function ($row) use ($search) {
				$text = $row['name'].' '.$row['parent'];
				foreach ($row['metrics'] as $item) {
					$text .= ' '.NetAppFormatter::itemValue($item);
				}
				return stripos($text, $search) !== false;
			}));
		}
		$sort = $data['sort'] ?? 'name';
		$order = $data['sortorder'] ?? 'ASC';
		usort($rows, static function ($a, $b) use ($sort, $order) {
			if ($sort === 'used') {
				$left = $a['capacity']['percent'] ?? -1;
				$right = $b['capacity']['percent'] ?? -1;
				$result = $left <=> $right;
			}
			elseif ($sort === 'state') {
				$result = strnatcasecmp(NetAppFormatter::itemValue($a['metrics']['state'] ?? $a['metrics']['status.state'] ?? null),
					NetAppFormatter::itemValue($b['metrics']['state'] ?? $b['metrics']['status.state'] ?? null));
			}
			else {
				$result = strnatcasecmp($a['name'], $b['name']);
			}
			$result = $result ?: strnatcasecmp($a['parent'].' '.$a['id'], $b['parent'].' '.$b['id']);
			return $order === 'DESC' ? -$result : $result;
		});
		$total = count($rows);
		$per_page = max(1, (int) ($data['per_page'] ?? 50));
		$page = min(max(1, (int) $data['page']), max(1, (int) ceil($total / $per_page)));
		$args = ['hostid' => $data['host']['hostid'], 'tab' => $data['tab'], 'search' => $search,
			'sort' => $sort, 'sortorder' => $order];
		$header = static function ($label, $field) use ($args, $sort, $order) {
			return new \CLink($label.($sort === $field ? ($order === 'ASC' ? ' ↑' : ' ↓') : ''),
				self::url(array_merge($args, ['sort' => $field, 'sortorder' => $sort === $field && $order === 'ASC' ? 'DESC' : 'ASC'])));
		};
		$capacity = in_array($data['tab'], ['volumes', 'luns'], true);
		$table = (new \CTableInfo())->setHeader([$header(_('Name'), 'name'), _('Parent / type'),
			$header(_('State'), 'state'), $capacity ? $header(_('Used / size'), 'used') : _('Properties'),
			_('Last update'), _('All metrics')])->setNoDataMessage(_('No discovered resources match this view.'));
		foreach (array_slice($rows, ($page - 1) * $per_page, $per_page) as $row) {
			$m = $row['metrics'];
			$properties = [];
			foreach (['version', 'location', 'membership', 'uptime', 'controller.over_temperature', 'comment', 'description',
				'type', 'svm_name', 'status.container_state'] as $key) {
				if (isset($m[$key])) {
					$properties[] = new \CDiv([$m[$key]['name'].': ', self::value($m[$key],
						in_array($key, ['controller.over_temperature', 'status.container_state'], true))]);
				}
			}
			$clocks = array_column($m, 'lastclock');
			$details = new \CTag('details', true, [new \CTag('summary', true, _('Metrics').' ('.count($m).')'), self::metricTable($m)]);
			$table->addRow([$row['name'], new \CDiv([$row['parent'] ?: '-',
				(new \CDiv(str_replace('_', ' ', $row['_group'])))->addClass('netapp-monitoring-muted')]),
				self::value($m['state'] ?? $m['status.state'] ?? null, true),
				$capacity ? new \CDiv([self::capacity($row['capacity']), $properties]) : ($properties ?: '-'),
				self::clock($clocks ? max($clocks) : 0), $details]);
		}
		$pager = [(new \CSpan(sprintf(_('%1$s resources; page %2$s of %3$s'), $total, $page,
			max(1, (int) ceil($total / $per_page)))))->addClass('netapp-monitoring-muted')];
		if ($page > 1) {
			$pager[] = new \CLink(_('Previous'), self::url($args + ['page' => $page - 1]));
		}
		if ($page * $per_page < $total) {
			$pager[] = new \CLink(_('Next'), self::url($args + ['page' => $page + 1]));
		}
		return new \CDiv([self::filter($data), (new \CDiv($table))->addClass('netapp-monitoring-table-wrap'),
			(new \CDiv($pager))->addClass('netapp-monitoring-pager')]);
	}

	public static function overview(array $data): \CDiv {
		$snapshot = $data['snapshot'];
		$properties = array_filter($snapshot['cluster'], static function ($key) {
			return strpos($key, 'statistics.') !== 0;
		}, ARRAY_FILTER_USE_KEY);
		$content = [self::section(_('Cluster information'), self::metricTable($properties))];
		$performance = array_intersect_key($snapshot['cluster'], array_flip([
			'statistics.iops.total.rate', 'statistics.throughput.total.rate', 'statistics.latency.total'
		]));
		$content[] = self::section(_('Current cluster performance'), self::metricTable($performance));
		$table = (new \CTableInfo())->setHeader([_('Resource'), _('Discovered'), _('Open inventory')]);
		foreach (['nodes' => _('Nodes'), 'svms' => _('SVMs'), 'volumes' => _('Volumes'), 'luns' => _('LUNs'),
			'ethernet_ports' => _('Ethernet ports'), 'fc_ports' => _('Fibre Channel ports'), 'disks' => _('Disks'),
			'chassis' => _('Chassis'), 'frus' => _('FRUs')] as $group => $label) {
			$tab = in_array($group, ['disks', 'chassis', 'frus'], true) ? 'hardware'
				: ($group === 'ethernet_ports' ? 'ethernet' : ($group === 'fc_ports' ? 'fc' : $group));
			$table->addRow([$label, count($snapshot['entities'][$group] ?? []),
				new \CLink(_('View'), self::url(['hostid' => $data['host']['hostid'], 'tab' => $tab]))]);
		}
		$content[] = self::section(_('Discovered environment'), $table);
		$content[] = (new \CDiv(_('Counts describe discovered resources. Missing values mean no retained sample is available. Capacity is shown per volume and per LUN; these layers overlap and are not added together.')))
			->addClass('netapp-monitoring-note');
		return new \CDiv($content);
	}

	public static function problems(array $data): \CDiv {
		if (!($data['snapshot']['problems_available'] ?? true)) {
			return new \CDiv([(new \CDiv(_('Problem information is unavailable for this host.')))
				->addClass('netapp-monitoring-note'),
				new \CLink(_('Open problems'), self::problemsUrl($data['host']['hostid']))]);
		}
		$table = (new \CTableInfo())->setHeader([_('Time'), _('Severity'), _('Problem'), _('Acknowledged')])
			->setNoDataMessage(_('No active problems found for this host.'));
		foreach ($data['snapshot']['problems'] as $problem) {
			$severity = (int) ($problem['severity'] ?? 0);
			$table->addRow([self::clock($problem['clock']),
				(new \CSpan(\CSeverityHelper::getName($severity)))->addClass(\CSeverityHelper::getStatusStyle($severity)),
				new \CLink($problem['name'], (new \CUrl('tr_events.php'))->setArgument('triggerid', $problem['objectid'])
					->setArgument('eventid', $problem['eventid'])),
				(int) ($problem['acknowledged'] ?? 0) ? _('Yes') : _('No')]);
		}
		return new \CDiv([$table, new \CLink(_('View all problems'), self::problemsUrl($data['host']['hostid']))]);
	}
}
