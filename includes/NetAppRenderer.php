<?php
namespace Modules\NetAppMonitoring\Includes;

use Modules\NetAppMonitoring\Includes\Common\RowDetails;
use Modules\NetAppMonitoring\Includes\Common\Summary;
use Modules\NetAppMonitoring\Includes\Common\CapacityBar;
use Modules\NetAppMonitoring\Includes\Common\SortableHeader;
use Modules\NetAppMonitoring\Includes\Common\ItemActions;
use Modules\NetAppMonitoring\Includes\Common\Pager;

use Modules\NetAppMonitoring\Includes\Common\SearchControl;

require_once __DIR__.'/common/bootstrap.php';

/** Server-rendered, permission-scoped NetApp monitoring components. */
class NetAppRenderer {
	public static function listUrl(array $data, array $args = []): \CUrl {
		$url = (new \CUrl('zabbix.php'))->setArgument('action', 'netapp.monitoring.list');
		foreach (array_merge(['search' => $data['search'], 'filter_groupids' => $data['groupids'] ?? [],
			'tab' => $data['tab'], 'sort' => 'name', 'sortorder' => $data['sortorder'], 'page' => $data['page']], $args) as $key => $value) {
			$url->setArgument($key, $value);
		}
		return $url;
	}

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
		return Summary::card($label, $value);
	}

	public static function listSummary(array $totals): \CDiv {
		$stats = [];
		foreach (['clusters' => _('NetApp clusters'), 'nodes' => _('NetApp nodes'),
			'svms' => _('SVMs'), 'volumes' => _('Volumes')] as $key => $label) {
			$stats[] = self::stat($label, isset($totals[$key]) ? (string) $totals[$key] : _('Unavailable'));
		}
		return Summary::strip($stats);
	}

	public static function section(string $title, $content): \CDiv {
		return (new \CDiv([(new \CTag('h4', true, $title))->addClass('netapp-monitoring-section-title'), $content]))
			->addClass('netapp-monitoring-section');
	}

	public static function capacity(array $capacity, ?array $size_item = null, ?array $used_item = null): \CDiv {
		$percent = $capacity['percent'] ?? null;
		$used = NetAppFormatter::bytes($capacity['used'] ?? null);
		$size = NetAppFormatter::bytes($capacity['size'] ?? null);
		$stale_items = array_filter([$size_item, $used_item], static function ($item) {
			return $item && (($item['stale'] ?? false) || (int) ($item['state'] ?? 0) !== 0);
		});
		$items = [new \CSpan([
			$used_item ? self::itemMenu($used, $used_item) : $used,
			' / ',
			$size_item ? self::itemMenu($size, $size_item) : $size
		])];
		if ($percent !== null) {
			$items[] = new \CSpan(NetAppFormatter::percent($percent));
			$items[] = CapacityBar::render($percent, ['stale' => (bool) $stale_items]);
		}

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

	/** Use Zabbix's permission-aware item menu for every latest value. */
	public static function itemMenu($label, array $item) {
		$backurl = (new \CUrl('zabbix.php'))->formatGetArguments()->getUrl();
		$backurl = (new \CUrl($backurl));
		parse_str(parse_url($backurl->getUrl(), PHP_URL_QUERY) ?? '', $arguments);
		if (($arguments['action'] ?? '') === 'netapp.monitoring.search') {
			$backurl->setArgument('action', 'netapp.monitoring.view');
		}
		elseif (($arguments['action'] ?? '') === 'netapp.monitoring.list.data') {
			$backurl->setArgument('action', 'netapp.monitoring.list');
		}
		return ItemActions::link($label, isset($item['itemid']) ? (string) $item['itemid'] : null,
			$backurl->getUrl(), ['class' => 'netapp-monitoring-inline-value']);
	}

	public static function inlineValue(?array $item, bool $state = false) {
		return $item ? self::itemMenu(self::value($item, $state), $item) : self::value(null);
	}

	public static function metricTable(array $metrics, string $search = '', string $object = '', string $highlight = ''): \CTableInfo {
		$table = (new \CTableInfo())->setHeader([_('Metric'), _('Latest value'), _('Last update'), _('History')])
			->setNoDataMessage($search !== '' ? _('No metrics match your search.') : _('No metrics are available.'));
		foreach ($metrics as $key => $item) {
			if (!$item) {
				continue;
			}
			if ($search !== '' && stripos($item['name'].' '.$key.' '.NetAppFormatter::itemValue($item), $search) === false) {
				continue;
			}
			$table->addRow([self::highlight(self::metricName($item['name'], $object), $highlight !== '' ? $highlight : $search), self::inlineValue($item, strpos($key, 'state') !== false || $key === 'status' || $key === 'controller.over_temperature'),
				self::clock($item['lastclock']), new \CLink(_('History'), self::historyUrl($item))]);
		}
		return $table;
	}

	public static function metricGroups(array $metrics, string $object = '', string $highlight = ''): \CDiv {
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
			$panels[] = RowDetails::group($label, self::metricTable($items, '', $object, $highlight));
		}
		return RowDetails::grid($panels);
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
		$hint = _('Names, parents, properties and metrics');
		if (($data['tab'] ?? '') === 'svms') { $hint = _('SVM names, properties, volumes and LUNs'); }
		elseif (($data['tab'] ?? '') === 'problems') { $hint = _('Problem names'); }
		elseif (($data['tab'] ?? '') === 'performance') { $hint = _('Metric names and latest values'); }
		elseif ($list) { $hint = _('Host names'); }
		$form = (new \CForm('get'))->cleanItems()->setAttribute('data-netapp-search', '1')
			->addVar('action', $list ? 'netapp.monitoring.list' : 'netapp.monitoring.view');
		if (!$list) {
			$form->addVar('hostid', $data['host']['hostid'])->addVar('tab', $data['tab']);
		}
		$form->addVar('sort', $data['sort'] ?? 'name')->addVar('sortorder', $data['sortorder'] ?? 'ASC');
		$form->addItem(SearchControl::bar($hint, $data['search'] ?? '', ['id' => 'netapp-search', 'name' => 'search'], [
			'label' => $list ? _('Search hosts') : _('Search this tab'), 'message' => $data['_search_message'] ?? '',
			'clear_attributes' => ['data-netapp-search-clear' => '1'],
			'status_attributes' => ['data-netapp-search-status' => '1']
		]));
		return $form;
	}

	public static function inventory(array $data, array $groups): \CDiv {
		$related = [];
		if (in_array('svms', $groups, true)) {
			foreach (['volumes', 'luns'] as $type) {
				foreach ($data['snapshot']['entities'][$type] ?? [] as $resource) {
					if (($resource['parent'] ?? '') !== '') {
						$related[$resource['parent']][$type][] = $resource;
					}
				}
			}
		}
		$rows = [];
		foreach ($groups as $group) {
			foreach ($data['snapshot']['entities'][$group] ?? [] as $row) {
				$row['_group'] = $group;
				$row['_parent_kind'] = in_array($group, ['volumes', 'luns'], true) ? 'svm'
					: (in_array($group, ['disks', 'ethernet_ports', 'fc_ports'], true) ? 'node' : '');
				$rows[] = $row;
			}
		}
		$search = trim($data['search'] ?? '');
		if ($search !== '') {
			$rows = array_values(array_filter($rows, static function ($row) use ($search, $related) {
				$text = $row['name'].' '.$row['parent'];
				if ($row['_group'] === 'svms') {
					foreach ($related[$row['name']] ?? [] as $resources) {
						$text .= ' '.implode(' ', array_column($resources, 'name'));
					}
				}
				foreach ($row['metrics'] as $key => $item) {
					$text .= ' '.$key.' '.$item['name'].' '.NetAppFormatter::itemValue($item);
				}
				return stripos($text, $search) !== false;
			}));
		}
		$sort = $data['sort'] ?? 'name';
		$order = $data['sortorder'] ?? 'ASC';
		usort($rows, static function ($a, $b) use ($sort, $order) {
			// Keep each relationship together; requested sorting applies within it.
			$group_order = strcmp($a['_parent_kind'], $b['_parent_kind']);
			if ($group_order === 0 && $a['_parent_kind'] !== '') {
				$group_order = ((int) ($a['parent'] === '') <=> (int) ($b['parent'] === ''))
					?: strnatcasecmp($a['parent'], $b['parent']) ?: strcmp($a['parent'], $b['parent']);
			}
			if ($group_order !== 0) { return $group_order; }
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
		$relationships = [];
		foreach ($rows as $row) {
			$key = json_encode([$row['_parent_kind'], $row['parent']]);
			$relationships[$key]['count'] = ($relationships[$key]['count'] ?? 0) + 1;
			$relationships[$key]['stale'] = !empty($relationships[$key]['stale']) || !empty($row['parent_stale']);
		}
		$per_page = max(1, (int) ($data['per_page'] ?? 50));
		$page = min(max(1, (int) $data['page']), max(1, (int) ceil($total / $per_page)));
		$focus = $data['focus'] ?? '';
		if ($focus !== '') {
			foreach ($rows as $index => $row) {
				if ($focus === self::resourceAnchor($data['host']['hostid'], $row['_group'], $row['id'])) {
					$page = intdiv($index, $per_page) + 1;
					break;
				}
			}
		}
		$args = ['hostid' => $data['host']['hostid'], 'tab' => $data['tab'], 'search' => $search,
			'sort' => $sort, 'sortorder' => $order];
		$paging = Pager::paginate($page, $rows, ZBX_SORT_UP, self::url($args));
		$header = static function ($label, $field) use ($args, $sort, $order) {
			return SortableHeader::native($label, $field, $sort, $order, self::url($args)->getUrl());
		};
		$capacity = in_array($data['tab'], ['volumes', 'luns'], true);
		$table = (new \CTableInfo())->setHeader([$header(_('Name'), 'name'),
			(new \CColHeader(_('Parent / type')))->addClass(ZBX_STYLE_NOWRAP),
			$header(_('State'), 'state'), $capacity ? $header(_('Used / size'), 'used') : _('Properties'),
			_('Last update')])->setNoDataMessage(_('No discovered resources match this view.'));
		$previous_relationship = null;
		foreach ($rows as $row) {
			$key = json_encode([$row['_parent_kind'], $row['parent']]);
			if ($row['_parent_kind'] !== '' && $key !== $previous_relationship) {
				$label = $row['_parent_kind'] === 'svm' ? _('SVM') : _('Node');
				$label .= ': '.($row['parent'] !== '' ? $row['parent'] : _('Unavailable'));
				$group = $relationships[$key];
				$content = [new \CSpan($label), (new \CSpan(sprintf(_('%1$s resources'), $group['count'])))
					->addClass('netapp-monitoring-location-count')];
				if ($group['stale']) {
					$content[] = (new \CSpan(_('Stale')))->addClass('netapp-monitoring-stale-badge')
						->setAttribute('title', _('SVM ownership uses the last collected value.'));
				}
				$table->addRow((new \CRow([(new \CCol((new \CDiv($content))
					->addClass('netapp-monitoring-location-group')))->setColSpan(5)]))
					->addClass('netapp-monitoring-inventory-group-row')->setAttribute('data-netapp-inventory-group', $row['_parent_kind']));
			}
			$previous_relationship = $key;
			$m = $row['metrics'];
			$properties = [];
			$property_keys = ['version', 'location', 'membership', 'uptime', 'controller.over_temperature', 'comment', 'description',
				'type', 'svm_name', 'status.container_state', 'space_available'];
			foreach ($property_keys as $key) {
				if (isset($m[$key])) {
					$properties[] = new \CDiv([self::highlight(self::metricName($m[$key]['name'], $row['name']), $search), ': ', self::inlineValue($m[$key],
						in_array($key, ['controller.over_temperature', 'status.container_state'], true))]);
				}
			}
			$shown_keys = array_merge($property_keys, ['state', 'status.state']);
			if ($capacity) {
				$shown_keys = array_merge($shown_keys, ['space_size', 'space_used', 'space.size', 'space.used']);
			}
			$expandable = (bool) array_diff(array_keys($m), $shown_keys);
			$expanded = false;
			if ($search !== '' && $expandable) {
				foreach (array_diff_key($m, array_flip($shown_keys)) as $key => $item) {
					if (stripos($key.' '.$item['name'].' '.NetAppFormatter::itemValue($item), $search) !== false) {
						$expanded = true;
						break;
					}
				}
			}
			$clocks = array_column($m, 'lastclock');
			$panel_id = 'netapp-metrics-'.sha1($data['host']['hostid'].'/'.$row['_group'].'/'.$row['id']);
			$toggle = $expandable ? RowDetails::toggle(_('Metrics').' ('.count($m).')', $panel_id, $expanded, [
				'data-netapp-metrics-toggle' => '1', 'aria-label' => _('Metrics').': '.$row['name']
			]) : null;
			$parent = trim((string) ($row['parent'] ?? ''));
			$name = [RowDetails::name(self::highlight($row['name'], $search), $toggle)];
			if ($row['_group'] === 'svms') {
				$sections = [];
				foreach (['volumes' => _('Volumes'), 'luns' => _('LUNs')] as $type => $label) {
					$resources = $related[$row['name']][$type] ?? [];
					if (!$resources) { continue; }
					usort($resources, static function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
					$heading = (new \CDiv([new \CSpan($label),
						(new \CSpan((string) count($resources)))->addClass('netapp-monitoring-related-count')]))
						->addClass('netapp-monitoring-related-label');
					$links = [];
					foreach ($resources as $resource) {
						$anchor = self::resourceAnchor($data['host']['hostid'], $type, $resource['id']);
						$url = self::url(['hostid' => $data['host']['hostid'], 'tab' => $type, 'focus' => $anchor]);
						$url->setArgument('search', '');
						$links[] = (new \CLink(self::highlight($resource['name'], $search), $url->getUrl().'#'.$anchor))
							->addClass('netapp-monitoring-related-link');
					}
					$sections[] = (new \CDiv([$heading,
						(new \CDiv($links))->addClass('netapp-monitoring-related-links')]))
						->addClass('netapp-monitoring-related-resources');
				}
				if ($sections) { $name[] = (new \CDiv($sections))->addClass('netapp-monitoring-svm-resources'); }
			}
			$anchor = self::resourceAnchor($data['host']['hostid'], $row['_group'], $row['id']);
			$name_cell = new \CCol(new \CDiv($name));
			if ($row['_group'] === 'svms') { $name_cell->addClass('netapp-monitoring-svm-name-cell'); }
			$resource_row = (new \CRow([$name_cell,
				self::highlight(($parent !== '' ? $parent.' / ' : '').str_replace('_', ' ', $row['_group']), $search)
					->addClass(ZBX_STYLE_NOWRAP),
				self::inlineValue($m['state'] ?? $m['status.state'] ?? null, true),
				$capacity ? new \CDiv([self::capacity($row['capacity'], $m['space_size'] ?? $m['space.size'] ?? null,
					$m['space_used'] ?? $m['space.used'] ?? null), $properties]) : ($properties ?: '-'),
				self::clock($clocks ? max($clocks) : 0)]))->setId($anchor)
				->setAttribute('data-netapp-resource-focus', $focus === $anchor ? '1' : '0');
			if ($expanded) { $resource_row->addClass('netapp-monitoring-resource-expanded'); }
			$table->addRow($resource_row);
			if (!$expandable) {
				continue;
			}
			$panel_row = RowDetails::row($panel_id, 5, self::metricGroups($m, $row['name'], $search), $expanded);
			$table->addRow($panel_row);
		}
		$table->setPageNavigation($paging);
		$message = $search !== '' ? sprintf(_('%1$s of %2$s resources match'), $total,
			array_sum(array_map(static function ($group) use ($data) {
				return count($data['snapshot']['entities'][$group] ?? []);
			}, $groups))) : '';
		return new \CDiv([self::filter(array_replace($data, ['_search_message' => $message])),
			(new \CDiv(RowDetails::table($table)))->setId('netapp-search-results')
				->setAttribute('data-netapp-search-message', $message)]);
	}

	/** Highlight text through escaped UI elements, including untrusted resource names. */
	public static function highlight(string $text, string $search): \CSpan {
		$parts = [];
		$offset = 0;
		if ($search !== '') {
			while (($position = stripos($text, $search, $offset)) !== false) {
				$parts[] = substr($text, $offset, $position - $offset);
				$parts[] = (new \CTag('mark', true, substr($text, $position, strlen($search))))
					->addClass('netapp-monitoring-search-match');
				$offset = $position + strlen($search);
			}
		}
		$parts[] = substr($text, $offset);
		return new \CSpan($parts);
	}

	public static function resourceAnchor(string $hostid, string $group, string $id): string {
		return 'netapp-resource-'.sha1($hostid.'/'.$group.'/'.$id);
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
			(new \CDiv($table))->addClass('dashboard-widget-problems')->setAttribute('data-netapp-problems', '1'),
			($widget['info'] ?? '') !== '' ? (new \CDiv($widget['info']))->addClass('netapp-monitoring-note') : null,
			(new \CDiv(new \CLink(_('View all problems'), $url)))->addClass(ZBX_STYLE_RIGHT)
		]);
	}
}
