# NetApp Monitoring for Zabbix 7.0

A standalone, read-only frontend module for monitoring NetApp AFF A700 clusters using data
already collected by `template_san_netapp_aff_a700_http.yaml`. No direct NetApp connection,
credentials, build step, database changes, or other frontend module is required.

## Installation

1. Copy this directory into the Zabbix frontend's `modules/netapp_monitoring` directory
   (commonly `/usr/share/zabbix/modules/netapp_monitoring`). Exclude `.git`, `.tools`, and tests.
2. Give the web server read access to the module files.
3. Open **Administration → General → Modules**, choose **Scan directory**, then enable
   **NetApp Monitoring**.
4. Open **Monitoring → NetApp**.

Configure the supplied monitoring template separately and allow its discovery rules and items
to collect values before using the module. The YAML is reference monitoring configuration;
the frontend module does not import or modify it.

## Host eligibility and permissions

Only hosts linked to a template whose visible or technical name contains
`NetApp AFF A700 by HTTP` are eligible. For example, both `NetApp AFF A700 by HTTP` and
`stp - NetApp AFF A700 by HTTP` qualify. Item keys alone never qualify a host.
Template inheritance is followed to include hosts using a wrapper template.

All data is read using the signed-in user's Zabbix API permissions. The user must have access
to **Monitoring → Latest data**, permission to use the enabled module, and read access to the
host and its matching template (including any wrapper templates in the inheritance chain).
Zabbix template-group permissions control template visibility independently of host access;
hosts whose template eligibility cannot be verified are excluded.
An arbitrary host ID in a detail-page URL cannot bypass the template restriction.
Native problem and history pages enforce their own permissions.

## Views

- Cluster list with health, software version, discovered object counts, and problems.
- Cluster overview with identity, collection timestamps, and navigation to native Zabbix data.
- Nodes, SVMs, volumes, LUNs, Ethernet ports, FC ports, and hardware inventories.
- Cluster and volume performance values with links to Zabbix history and graphs.
- Active, unsuppressed problems linked to native Zabbix problem details.
- The Problems tab uses Zabbix's native Problems widget with recent/recovered events, timeline,
  operational data, the signed-in user's permitted problem actions, and pagination following
  the user's **Rows per page** setting. Zabbix's global search limit caps the result set.
  Matching symptoms remain visible. When a cause and its symptoms share a page, the symptoms
  can be expanded beneath that cause; symptoms whose cause is on another page stay standalone.
- Search, sorting, pagination using the user's rows-per-page setting, and blue/dark theme support.
- Host inventory notes in the host list and detail pages, with notes included in host search.
- Full-width expandable resource metrics, grouped into responsive panels.
- Metric labels omit the resource name inside a resource's panel. Resources whose metrics are
  already visible in the row have no redundant expander; clicking a latest value opens Zabbix's native item menu, with Item, Graph (numeric items),
  Values, and 500 latest values, subject to the user's permissions and history retention.
- Automatic search as you type and when host groups change; the native main filter also
  provides optional Apply and Reset controls.
- Main Hosts and Problems tabs share the Name and host-group filters. Name matches hosts,
  technical host names and inventory notes. Problems aggregate the complete matching host
  scope, including hosts beyond the current host-list page.
- Host names open their detail page normally; right-click opens Zabbix's native host menu.
- Paginated tables use Zabbix's native `CTableInfo` page-navigation support and `CPagerHelper`
  controls throughout the module, preserving filters and the user's rows-per-page preference.

The interface uses a context bar, summary strip, tabs, and native Zabbix tables.
All implementation files and assets live in this directory.

Set the host-level text macro `{$NETAPP.LOCATION}` to a comma-separated hierarchy, such as
`Switzerland, Zurich, Rack 2`, to group the main Hosts table. Each ancestor shows the count
of all matching hosts below it, including later pages. Groups can be collapsed; hosts without
this macro appear after grouped hosts. Secret and vault macros are never used as labels.
The host-group filter includes descendants and respects the user's accessible groups.
Location rows also show severity badges in the Problems column. Counts include every active,
unsuppressed event across the full matching subtree, including hosts on other pages. Shared
events count once per ancestor, and clicking a badge opens native Problems for that subtree.
Unavailable problem metadata displays **Unavailable** instead of a healthy zero count.

## Interpreting the data

Object counts reflect discovered item groups visible to the current user, rather than a live
NetApp inventory query. Missing discoveries, disabled items, and permission filters can affect
counts. The template does not expose aggregate or disk capacity, disk models, snapshots, or
SnapMirror details, so the module does not invent these metrics.

Volume capacity and LUN capacity are separate views of storage; do not add them together.
Inventory tables group volumes and LUNs by SVM, and disks, Ethernet ports and FC ports by
node using the existing template values and item keys. Missing ownership appears as
Unavailable; retained SVM ownership is marked Stale when collection fails. Groups remain
together when sorting, and repeat their heading when continued on another page. Resource
counts reflect the current search across all pages; group headings do not consume page rows.
SVM rows list their related volume and LUN names. Each name opens the appropriate inventory
tab on the resource's page, clears the destination search, scrolls to the selected row and
highlights it for five seconds. SVM search also matches those related resource names.
Tab searches include a live Clear button and an Escape shortcut. Inventory searches match
resource names, ownership, metric names/keys and latest values, report matching resource counts,
and open metrics panels when a match would otherwise be hidden. Matching names are highlighted.
Detail searches use a small JSON response containing the native results and host-status HTML,
with an 80 ms typing delay. A private temporary cache reuses the signed-in user's collected
snapshot for up to 10 seconds; current host access is checked on every request. Browser memory
keeps up to eight recent query results for five seconds, allowing repeated queries and Clear
to respond immediately. A normal page load or Refresh always collects current monitoring data.
The server cache is optional: unavailable private temporary storage or oversized snapshots fall
back to normal collection. Snapshots are never stored in Zabbix session cookies.
Volume available space is the value reported by the template, rather than an assumed size-minus-used
value. Performance metrics are already calculated by the template; the frontend does not derive
rates from cumulative counters. Calculated latency values in the supplied template are already
expressed in milliseconds.

When NetApp HTTP collection fails, the module retains the last collected values and discovered
resources. A prominent notice on every host tab explains the outage, shows failed check details
and the latest stored sample time; the Hosts list also marks affected hosts. Retained values,
including capacity, carry a **Stale** label and should not be interpreted as current health.
Enabled unsupported items keep their last sample when history exists. Items with no collected
sample remain unavailable, and disabled items do not expose their old values.

Collection status comes from the template's HTTP master items and HTTP discovery rules.
It does not infer failure from old scalar timestamps: unchanged values may be intentionally
discarded by template heartbeat preprocessing. Use native Latest data to investigate the
reported diagnostics. Collection warnings clear when HTTP checks recover.
The Refresh action reloads stored Zabbix data; it does not execute HTTP checks or trigger discovery.
Live search updates the detail status bar, collection warning, counters, and results from the same
Zabbix snapshot. Failed or incomplete searches preserve the last consistent display.

## Development verification

### Test without a NetApp appliance

Run `python dev/mock-netapp/demo.py up` to start a containerized fake NetApp HTTP API and install
an isolated demo host/template. Use `python dev/mock-netapp/demo.py down` to remove the fixture.
Healthy, degraded, and empty discovery scenarios exercise real Zabbix collection and triggers.
See [the mock setup guide](dev/mock-netapp/README.md) for commands, endpoint addresses, and cleanup behavior.

Use a PHP runtime compatible with your Zabbix 7.0 frontend:

```powershell
Get-ChildItem Module.php,actions,includes,views,tests -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }
php tests/collector.php
php tests/controllers.php
php tests/location-groups.php
php tests/problem-scope.php /path/to/zabbix/ui
php tests/views-smoke.php /path/to/zabbix/ui
```

With the isolated demo already populated and its mock service intentionally stopped,
`tests/live-collection.php` provides a read-only outage regression against the running frontend.
Pass the frontend UI directory and provide `ZABBIX_USER`/`ZABBIX_PASSWORD` through the test
process environment. It checks 18 failed HTTP checks and retained demo identity, capacity and
latency, and never changes the mock or Zabbix configuration.

`tests/live-group-problems.php` checks group severity counts against the live API using
an in-memory location tree. It uses the same UI-directory argument and credentials, requires
an eligible NetApp host, and works regardless of mock availability without changing host macros.

For the local development container, the module directory is already bind-mounted. Run checks
using its installed PHP runtime; no downloaded local PHP runtime is required:

```powershell
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-netapp-monitoring/tests/collector.php
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-netapp-monitoring/tests/controllers.php
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/zabbix-module-netapp-monitoring/tests/views-smoke.php /usr/share/zabbix
```

The tests use isolated API/controller fixtures. Deployment acceptance should also cover an
authorized viewer, a restricted viewer, renamed and inherited templates, empty discoveries,
unsupported items, and both frontend themes on a running Zabbix 7.0 instance.

Frontend action registration follows the [Zabbix 7.0 module documentation](https://www.zabbix.com/documentation/7.0/en/devel/modules/file_structure/actions).
