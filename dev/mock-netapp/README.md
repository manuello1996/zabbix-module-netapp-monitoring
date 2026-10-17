# Disposable NetApp monitoring demo

This fake ONTAP HTTP API supplies every endpoint and field read by the repository's
`template_san_netapp_aff_a700_http.yaml`. Zabbix collects the fixture using real HTTP checks,
low-level discovery, dependent items, calculated items, and triggers. The frontend module
uses the resulting Zabbix data normally.

Requirements: Python 3.10+, Docker Compose, the running Zabbix 7.0 test environment,
and permission to create/delete hosts, templates, and their groups. Python dependencies
are all from the standard library. The Compose project joins the existing external
`zabbix-network` network; it does not alter your Zabbix Compose file.

## Drop in

Run from the repository root:

```powershell
python dev/mock-netapp/demo.py up
```

Enter the Zabbix Admin password when prompted (`zabbix` in your current test environment).
The command builds/starts the mock and creates:

- Host: technical name `netapp-module-demo`, visible name `DEMO - NetApp AFF A700 cluster`.
- Template: `DEMO - NetApp AFF A700 by HTTP`, cloned from the supplied YAML with separate UUIDs.
- Host group: `NetApp module demo`.
- Template group: `Templates/NetApp module demo`.

Only the cloned template uses 15-second HTTP/discovery/calculated intervals. The original
template and any real monitored hosts are untouched. Repeating `up` reuses the marked fixture
and refreshes its three NetApp host macros and manual inventory notes (`{INVENTORY.NOTES}`).
Notes are exactly `Synthetic NetApp AFF A700 SAN cluster` and appear in the module's host list
and detail pages. The command prints a direct link to the module.

Allow **1–3 minutes** for the Zabbix configuration cache, discovery, and at least two performance
samples. Calculated latency may briefly report “not enough data” during this initial period.
HTTP master items intentionally retain no history in the supplied template; they can show
no last value even while their dependent metrics collect successfully.

```powershell
python dev/mock-netapp/demo.py status
```

`status` reports sampled items, discovery errors, active problems, and the current scenario.
The fixture contains 2 nodes, 2 SVMs, 4 volumes, 4 LUNs, 8 disks, 1 chassis, 4 FRUs,
4 Ethernet ports, and 4 FC ports. Cumulative counters advance with time so real Zabbix
rates and latency calculations have usable history. Names of ports are unique across nodes
because the supplied template's port JSONPaths filter by port name alone.

## Change the dataset

```powershell
python dev/mock-netapp/demo.py scenario --scenario degraded
python dev/mock-netapp/demo.py scenario --scenario healthy
python dev/mock-netapp/demo.py scenario --scenario empty
python dev/mock-netapp/demo.py scenario --scenario unresponsive
```

- `healthy`: operational cluster, nodes, SVMs, storage, and hardware.
- `degraded`: failed node, over-temperature, stopped SVM, offline volume/LUN, broken disk,
  hardware faults, disconnected ports, and an abnormal cluster statistics status. These states
  exercise the actual template triggers. One volume also reaches 96% usage for capacity rendering;
  the supplied template does not define a capacity trigger for that condition.
- `empty`: cluster identity/performance remain, but every discovery endpoint returns no records.
- `unresponsive`: authenticated `/api/*` connections receive no response headers or body, so
  Zabbix's HTTP agents reach their configured `{$NETAPP.HTTP.AGENT.TIMEOUT}`. Discovery and
  dependent metrics stop receiving new samples; existing values remain in Zabbix. This does
  not manufacture an appliance fault or a problem event: alerts depend on the template's
  existing triggers (the supplied template has no `nodata()` trigger).
  `/_mock/status`, `/_mock/scenario`, and `/healthz` stay available, allowing recovery without
  restarting the container. Return to `healthy` with the command above to resume collection.

After updating these files, rebuild the running mock once before using the new scenario:

```powershell
docker compose --project-name netapp-module-mock --file dev/mock-netapp/compose.yaml up -d --build
```

Rebuilding resets the scenario to healthy and preserves the Zabbix demo host and its history.

Scenario changes do not reset performance counters. Zabbix sees changes at its next poll and
resolves problems when `healthy` is restored. Some prototype discovery data can retain its previous
state until a discovery cycle runs.

**Empty discovery follows the template's normal lost-resource retention.** It does not instantly
erase objects Zabbix discovered earlier. For a genuinely empty module inventory, run `down`, then
`up --scenario empty`, and wait for collection. This deletes only the demo host and its history.
When switching an existing populated host to empty, retained dependent items can also report
JSONPath errors because their records are no longer present; returning to healthy restores them.

## Drop out

```powershell
python dev/mock-netapp/demo.py down
```

This deletes the owned demo host, its discovered objects, and the cloned template, removes groups
created by the helper if they are still empty, and stops/removes the demo container. Zabbix can
remove old history asynchronously through housekeeping. The shared Docker network and Zabbix
containers remain running. The local image is cached for the next `up`.

Ownership tags/description markers protect unrelated objects with the same name. Removal refuses
to delete the demo template if other hosts or templates have started using it. The ignored
`.demo-state.json` tracks which groups the helper created and contains no credentials; keep it
until cleanup so those empty groups can also be removed. Setup failures leave this state available
for retry or `down`.

## Endpoint addresses

| Caller | Base URL |
|---|---|
| Zabbix server / other containers on `zabbix-network` | `http://netapp-module-mock:8080` |
| Windows host | `http://127.0.0.1:18080` |

NetApp Basic authentication is `mock` / `mock`. A container's `127.0.0.1` points to that container,
so use the Docker DNS name in the Zabbix host's `{$NETAPP.URL}` macro.

Example host-side inspection:

```powershell
curl.exe --user mock:mock http://127.0.0.1:18080/api/cluster
curl.exe --user mock:mock http://127.0.0.1:18080/_mock/status
```

Supported ONTAP paths: `/api/cluster`, `/api/cluster/nodes`, `/api/cluster/chassis`,
`/api/storage/disks`, `/api/storage/luns`, `/api/storage/volumes`, `/api/svm/svms`,
`/api/network/ethernet/ports`, and `/api/network/fc/ports`.
The mock accepts the template's `fields` queries and returns complete records in one page.
It is a fixture for this template, not a full ONTAP implementation. `/healthz` is unauthenticated;
the API and `/_mock/*` controls require Basic authentication.

The published port binds only to localhost. Containers on the test network can also access
the authenticated scenario control. Scenario state is in memory and resets to healthy on restart.

## Configuration and standalone use

The helper accepts `--zabbix-url`, `--user`, `--mock-url`, and `--netapp-url`.
For scripting, set `ZABBIX_URL`, `ZABBIX_USER`, and `ZABBIX_PASSWORD`, or supply
`ZABBIX_API_TOKEN` instead of a password. Credentials are never written into demo state.
`NETAPP_MOCK_USERNAME` and `NETAPP_MOCK_PASSWORD` override the mock credentials in both the
container and host macros when running `up`.

To start only the mock without installing anything in Zabbix:

```powershell
docker compose --project-name netapp-module-mock --file dev/mock-netapp/compose.yaml up -d --build
```

Link the supplied template to your own test host and set `{$NETAPP.URL}` to
`http://netapp-module-mock:8080`, `{$NETAPP.USERNAME}` to `mock`, and `{$NETAPP.PASSWORD}` to `mock`.
The helper's `down` does not delete manually created hosts.

To run without Docker, start `python dev/mock-netapp/server.py` on the Windows host (port 8080)
and use `http://host.docker.internal:8080` from Docker Desktop containers. Docker Compose is the
recommended path because it needs no host firewall changes and keeps the fixture on the test network.

## Tests

```powershell
python -m unittest discover -s tests -p "test_*.py" -v
```

The HTTP tests validate every endpoint/field requested by the supplied YAML, Basic authentication,
scenario recovery, counter continuity, and latency units. Helper tests use mocked APIs and never
delete objects from a running Zabbix instance.
