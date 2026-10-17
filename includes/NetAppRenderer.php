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
		if ($item && (int) ($item['status'] ?? 0) !== 0) {
			return (new \CSpan(_('Disabled')))
				->addClass('netapp-monitoring-state-warning')->setTitle((string) ($item['error'] ?? ''));
		}
		if (!$item || ($item['lastvalue'] ?? null) === null || !(int) ($item['lastclock'] ?? 0)) {
			if ($item && (int) ($item['state'] ?? 0) !== 0) {
				return (new \CSpan(_('Unsupported')))->addClass('netapp-monitoring-state-warning')
					->setTitle((string) ($item['error'] ?? ''));
			}
			return (new \CSpan(_('No data')))->addClass('netapp-monitoring-muted');
		}
		$text = NetAppFormatter::itemValue($item);
		$stale = ($item['stale'] ?? false) || (int) ($item['state'] ?? 0) !== 0;
		if ($stale) {
			$title = _('Last collected sample').': '.self::clock($item['lastclock']);
			if (trim($item['error'] ?? '') !== '') {
				$title .= "\n".$item['error'];
			}
			return (new \CSpan([$text, ' ', (new \CSpan(_('Stale')))->addClass('netapp-monitoring-stale-badge')]))
				->addClass('netapp-monitoring-stale-value')->setTitle($title);
		}
		$span = new \CSpan($text);
		if ($state) {
			$healthy = preg_match('/^(ok|online|up|present|started|running|healthy|true|member|normal)$/i', trim((string) $text));
			$span->addClass('netapp-monitoring-state')->addClass($healthy
				? 'netapp-monitoring-state-good' : 'netapp-monitoring-state-warning');
		}
		return $span->setTitle(_('Last update').': '.self::clock($item['lastclock']));
	}

	public static function collectionMarker(array $snapshot) {
		$status = $snapshot['collection']['status'] ?? 'ok';
		if ($status === 'ok') {
			return null;
		}
		$labels = ['failed' => _('HTTP collection failing'), 'unknown' => _('Collection status unavailable'),
			'disabled' => ($snapshot['collection']['host_disabled'] ?? true)
				? _('Monitoring disabled') : _('HTTP collection disabled')];
		$clock = $snapshot['collection']['lastclock'] ?? $snapshot['lastclock'] ?? 0;
		$title = _('Last collected sample').': '.self::clock($clock).'. '._('Inspect Latest data for details.');
		return (new \CDiv($labels[$status] ?? $labels['unknown']))->addClass('netapp-monitoring-collection-marker')
			->setTitle($title);
	}

	public static function collectionNotice(array $snapshot, string $hostid) {
		$collection = $snapshot['collection'] ?? [];
		$status = $collection['status'] ?? 'ok';
		if ($status === 'ok') {
			return null;
		}
		$clock = (int) ($collection['lastclock'] ?? $snapshot['lastclock'] ?? 0);
		$failed = $collection['failed'] ?? [];
		$count = (int) ($collection['failed_count'] ?? count($failed));
		$total = (int) ($collection['total'] ?? 0);
		if ($status === 'failed') {
			$partial = $total > $count;
			$title = $partial ? _('Some NetApp HTTP requests are failing') : _('NetApp HTTP collection is failing');
			$text = $clock > 0
				? _('Showing the last collected values. Data may be outdated until HTTP collection recovers.')
				: _('No collected values are available yet.');
			$detail = $total > 0 ? sprintf(_('%1$s of %2$s collection checks are failing.'), $count, $total)
				: sprintf(_('%1$s collection checks are failing.'), $count);
		}
		elseif ($status === 'disabled') {
			$title = ($collection['host_disabled'] ?? true)
				? _('Monitoring is disabled for this host') : _('NetApp HTTP collection is disabled');
			$text = ($collection['host_disabled'] ?? true)
				? _('Collection is paused. Enable host monitoring to collect new values.')
				: _('Collection is paused. Enable HTTP monitoring checks to collect new values.');
			$detail = '';
		}
		else {
			$title = _('NetApp HTTP collection status is unavailable');
			$text = $clock > 0 ? _('Showing stored values. Their freshness cannot be verified.')
				: _('No collected values are available yet.');
			$detail = _('Inspect Latest data to check collection status.');
		}
		$content = [new \CTag('strong', true, $title), new \CDiv($text),
			$detail !== '' ? new \CDiv($detail) : null,
			new \CDiv([_('Latest stored sample').': ', self::clock($clock)]),
			new \CLink(_('Latest data'), self::latest($hostid))];
		if ($failed) {
			$table = (new \CTableInfo())->setHeader([_('Collection check'), _('Type'), _('Key'), _('Error')]);
			foreach ($failed as $check) {
				$table->addRow([$check['name'], ($check['kind'] ?? 'item') === 'discovery' ? _('Discovery') : _('Item'),
					$check['key_'], $check['error'] ?: _('No diagnostic message available.')]);
			}
			$content[] = new \CTag('details', true, [new \CTag('summary', true, _('Collection error details')), $table]);
		}
		return (new \CDiv($content))->addClass('netapp-monitoring-collection-notice')->setAttribute('role', 'status');
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

	public static function capacity(array $capacity, ?array $size_item = null, ?array $used_item = null): \CDiv {
		$percent = $capacity['percent'] ?? null;
		$used = NetAppFormatter::bytes($capacity['used'] ?? null);
		$size = NetAppFormatter::bytes($capacity['size'] ?? null);
		$items = [new \CSpan([
			$used_item ? (new \CLink($used, self::historyUrl($used_item)))->setTitle(_('Used size history'))
				->addClass('netapp-monitoring-inline-value') : $used,
			' / ',
			$size_item ? (new \CLink($size, self::historyUrl($size_item)))->setTitle(_('Size history'))
				->addClass('netapp-monitoring-inline-value') : $size
		])];
		if ($percent !== null) {
			$items[] = new \CSpan(NetAppFormatter::percent($percent));
			$items[] = (new \CDiv((new \CDiv())->addClass('netapp-monitoring-progress-fill')
				->addClass($percent >= 90 ? 'netapp-monitoring-progress-critical' : '')
				->setAttribute('style', 'width: '.number_format(max(0, min(100, $percent)), 2, '.', '').'%')))
				->addClass('netapp-monitoring-progress');
		}
		$stale_items = array_filter([$size_item, $used_item], static function ($item) {
			return $item && (($item['stale'] ?? false) || (int) ($item['state'] ?? 0) !== 0);
		});
		if ($stale_items) {
			$items[] = (new \CSpan(_('Stale')))->addClass('netapp-monitoring-stale-badge')
				->setTitle(_('Capacity uses the last collected values. Inspect metric timestamps before interpreting usage.'));
		}
		return (new \CDiv($items))->addClass('netapp-monitoring-capacity')
			->addClass($stale_items ? 'netapp-monitoring-capacity-stale' : null);
	}

	public static function metricName(string $name, string $object): string {
		$prefix = $object.':';
		return $object !== '' && strncmp($name, $prefix, strlen($prefix)) === 0
			? ltrim(substr($name, strlen($prefix))) : $name;
	}

	public static function historyUrl(array $item): \CUrl {
		return (new \CUrl('history.php'))->setArgument('action',
			in_array((int) $item['value_type'], [0, 3], true) ? 'showgraph' : 'showvalues')
			->setArgument('itemids', [$item['itemid']]);
	}

	public static function inlineValue(?array $item, bool $state = false) {
		return $item ? (new \CLink(self::value($item, $state), self::historyUrl($item)))
			->setTitle(_('History'))->addClass('netapp-monitoring-inline-value') : self::value(null);
	}

	public static function metricTable(array $metrics, string $search = '', string $object = ''): \CTableInfo {
		$table = (new \CTableInfo())->setHeader([_('Metric'), _('Latest value'), _('Last update'), _('History')]);
		foreach ($metrics as $key => $item) {
			if (!$item) {
				continue;
			}
			if ($search !== '' && stripos($item['name'].' '.$key.' '.NetAppFormatter::itemValue($item), $search) === false) {
				continue;
			}
			$table->addRow([self::metricName($item['name'], $object), self::value($item, strpos($key, 'state') !== false || $key === 'status' || $key === 'controller.over_temperature'),
				self::clock($item['lastclock']), new \CLink(_('History'), self::historyUrl($item))]);
		}
		return $table;
	}

	public static function metricGroups(array $metrics, string $object = ''): \CDiv {
		$groups = [];
		foreach ($metrics as $key => $item) {
			if (strpos($key, '_raw.') !== false) {
				$label = _('Raw counters');
			}
			elseif (strpos($key, 'statistics.iops.') === 0) {
				$label = _('IOPS');
			}
			elseif (strpos($key, 'statistics.latency.') === 0) {
				$label = _('Latency');
			}
			elseif (strpos($key, 'statistics.throughput.') === 0) {
				$label = _('Throughput');
			}
			elseif (strpos($key, 'space') === 0) {
				$label = _('Capacity');
			}
			else {
				$label = _('Health and properties');
			}
			$groups[$label][$key] = $item;
		}
		$panels = [];
		foreach ($groups as $label => $items) {
			$panels[] = (new \CDiv([new \CTag('h5', true, $label), self::metricTable($items, '', $object)]))
				->addClass('netapp-monitoring-metric-group');
		}
		return (new \CDiv($panels))->addClass('netapp-monitoring-metric-grid');
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
		$form = (new \CForm('get'))->cleanItems()->setAttribute('data-netapp-search', '1')
			->addVar('action', $list ? 'netapp.monitoring.list' : 'netapp.monitoring.view');
		if (!$list) {
			$form->addVar('hostid', $data['host']['hostid'])->addVar('tab', $data['tab']);
		}
		$form->addVar('sort', $data['sort'] ?? 'name')->addVar('sortorder', $data['sortorder'] ?? 'ASC');
		$form->addItem((new \CDiv([
			new \CLabel($list ? _('Search hosts') : _('Search this tab'), 'netapp-search'),
			(new \CTextBox('search', $data['search'] ?? ''))->setId('netapp-search')->addClass('netapp-monitoring-search'),
			new \CLink(_('Reset'), $list ? (new \CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list')
				: self::url(['hostid' => $data['host']['hostid'], 'tab' => $data['tab']])),
			(new \CSpan())->setAttribute('data-netapp-search-status', '1')->setAttribute('role', 'status')
				->setAttribute('data-searching', _('Searching…'))
				->setAttribute('data-error', _('Search could not be updated. Please try again or reload the page.'))
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
		$table = (new \CTableInfo())->setHeader([$header(_('Name'), 'name'),
			(new \CColHeader(_('Parent / type')))->addClass(ZBX_STYLE_NOWRAP),
			$header(_('State'), 'state'), $capacity ? $header(_('Used / size'), 'used') : _('Properties'),
			_('Last update')])->setNoDataMessage(_('No discovered resources match this view.'));
		foreach (array_slice($rows, ($page - 1) * $per_page, $per_page) as $row) {
			$m = $row['metrics'];
			$properties = [];
			$property_keys = ['version', 'location', 'membership', 'uptime', 'controller.over_temperature', 'comment', 'description',
				'type', 'svm_name', 'status.container_state', 'space_available'];
			foreach ($property_keys as $key) {
				if (isset($m[$key])) {
					$properties[] = new \CDiv([self::metricName($m[$key]['name'], $row['name']).': ', self::inlineValue($m[$key],
						in_array($key, ['controller.over_temperature', 'status.container_state'], true))]);
				}
			}
			$shown_keys = array_merge($property_keys, ['state', 'status.state']);
			if ($capacity) {
				$shown_keys = array_merge($shown_keys, ['space_size', 'space_used', 'space.size', 'space.used']);
			}
			$expandable = (bool) array_diff(array_keys($m), $shown_keys);
			$clocks = array_column($m, 'lastclock');
			$panel_id = 'netapp-metrics-'.sha1($data['host']['hostid'].'/'.$row['_group'].'/'.$row['id']);
			$toggle = $expandable ? (new \CTag('button', true, _('Metrics').' ('.count($m).')'))
				->setAttribute('type', 'button')->setId($panel_id.'-toggle')
				->setAttribute('data-netapp-metrics-toggle', '1')
				->setAttribute('aria-expanded', 'false')->setAttribute('aria-controls', $panel_id)
				->setAttribute('aria-label', _('Metrics').': '.$row['name'])
				->addClass('netapp-monitoring-metrics-toggle') : null;
			$parent = trim((string) ($row['parent'] ?? ''));
			$table->addRow([(new \CDiv([new \CSpan($row['name']), $toggle]))
				->addClass('netapp-monitoring-resource-name'),
				(new \CSpan(($parent !== '' ? $parent.' / ' : '').str_replace('_', ' ', $row['_group'])))
					->addClass(ZBX_STYLE_NOWRAP),
				self::inlineValue($m['state'] ?? $m['status.state'] ?? null, true),
				$capacity ? new \CDiv([self::capacity($row['capacity'], $m['space_size'] ?? $m['space.size'] ?? null,
					$m['space_used'] ?? $m['space.used'] ?? null), $properties]) : ($properties ?: '-'),
				self::clock($clocks ? max($clocks) : 0)]);
			if (!$expandable) {
				continue;
			}
			$panel = (new \CDiv(self::metricGroups($m, $row['name'])))->setAttribute('role', 'region')
				->setAttribute('aria-labelledby', $panel_id.'-toggle');
			$table->addRow((new \CRow([(new \CCol($panel))->setColSpan(5)]))
				->setId($panel_id)->setAttribute('hidden', 'hidden')->addClass('netapp-monitoring-metrics-row'));
		}
		$table->setPageNavigation(NetAppPager::forTotal($page, $total, $per_page, self::url($args)));
		return new \CDiv([self::filter($data),
			(new \CDiv((new \CDiv($table))->addClass('netapp-monitoring-table-wrap')))->setId('netapp-search-results')]);
	}

	public static function overview(array $data): \CDiv {
		$snapshot = $data['snapshot'];
		$properties = array_filter($snapshot['cluster'], static function ($key) {
			return strpos($key, 'statistics.') !== 0;
		}, ARRAY_FILTER_USE_KEY);
		$search = '';
		$content = [self::section(_('Cluster information'), self::metricTable($properties, $search))];
		$performance = array_intersect_key($snapshot['cluster'], array_flip([
			'statistics.iops.total.rate', 'statistics.throughput.total.rate', 'statistics.latency.total'
		]));
		$content[] = self::section(_('Current cluster performance'), self::metricTable($performance, $search));
		$table = (new \CTableInfo())->setHeader([_('Resource'), _('Discovered'), _('Open inventory')]);
		foreach (['nodes' => _('Nodes'), 'svms' => _('SVMs'), 'volumes' => _('Volumes'), 'luns' => _('LUNs'),
			'ethernet_ports' => _('Ethernet ports'), 'fc_ports' => _('Fibre Channel ports'), 'disks' => _('Disks'),
			'chassis' => _('Chassis'), 'frus' => _('FRUs')] as $group => $label) {
			if ($search !== '' && stripos($label, $search) === false) {
				continue;
			}
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
		$table = (new \Widgets\Problems\Includes\WidgetProblems($data['problem_widget']))
			->setNoDataMessage(($data['search'] ?? '') !== ''
				? _('No problems match your search.') : _('No recent problems found for this host.'));
		$url = self::problemsUrl($data['host']['hostid']);
		if (($data['search'] ?? '') !== '') {
			$url->setArgument('name', $data['search']);
		}
		$widget = $data['problem_widget'];
		$pager = null;
		if (isset($widget['total'])) {
			$pager = NetAppPager::forTotal($widget['page'], $widget['total'], $widget['per_page'],
				self::url(['hostid' => $data['host']['hostid'], 'tab' => 'problems',
					'search' => $data['search'] ?? '']), $widget['has_more']);
		}
		$table->setPageNavigation($pager);
		return new \CDiv([
			(new \CDiv($table))->addClass('dashboard-widget-problems'),
			(new \CDiv(new \CLink(_('View all problems'), $url)))->addClass(ZBX_STYLE_RIGHT)
		]);
	}
}
