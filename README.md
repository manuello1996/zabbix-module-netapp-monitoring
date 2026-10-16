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
- Search, sorting, pagination using the user's rows-per-page setting, and blue/dark theme support.

The interface uses a context bar, summary strip, tabs, and native Zabbix tables.
All implementation files and assets live in this directory.

## Interpreting the data

Object counts reflect discovered item groups visible to the current user, rather than a live
NetApp inventory query. Missing discoveries, disabled items, and permission filters can affect
counts. The template does not expose aggregate or disk capacity, disk models, snapshots, or
SnapMirror details, so the module does not invent these metrics.

Volume capacity and LUN capacity are separate views of storage; do not add them together.
Volume available space is the value reported by the template, rather than an assumed size-minus-used
value. Performance metrics are already calculated by the template; the frontend does not derive
rates from cumulative counters. Calculated latency values in the supplied template are already
expressed in milliseconds.

Items without a collected value display an unavailable state. Unsupported and disabled items
retain their diagnostic status; their last numeric values should not be treated as current health.
Check the displayed collection time and use native Latest data to investigate stale readings.
The Refresh action reloads stored Zabbix data; it does not execute HTTP checks or trigger discovery.

## Development verification

Use a PHP runtime compatible with your Zabbix 7.0 frontend:

```powershell
Get-ChildItem Module.php,actions,includes,views,tests -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }
php tests/collector.php
php tests/controllers.php
php tests/views-smoke.php /path/to/zabbix/ui
```

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
