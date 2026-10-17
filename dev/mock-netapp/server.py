#!/usr/bin/env python3
"""Small, deterministic ONTAP REST fixture for the supplied Zabbix template.

This is development data, not a general ONTAP emulator. All records are returned
in one page; the template's fields query is accepted without hiding extra fields.
"""
import base64
import hmac
import json
import os
import select
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit


class NetAppFixture:
    scenarios = ("healthy", "degraded", "empty", "unresponsive")
    identity = "netapp-module-mock"

    def __init__(self, clock=time.time):
        self.clock = clock
        self.started = clock()
        self.last_elapsed = 0.0
        self.scenario = "healthy"
        self.lock = threading.RLock()
        self.changed = threading.Condition(self.lock)

    def elapsed(self):
        # Wall clock integration continues across all scenario changes. A clock
        # adjustment backwards pauses counters rather than resetting them.
        self.last_elapsed = max(self.last_elapsed, self.clock() - self.started)
        return self.last_elapsed

    def statistics(self, scale, elapsed):
        seconds = elapsed + 86400
        rates = {"other": 100, "read": 1200, "write": 700}
        micros = {"other": 500, "read": 800, "write": 1200}
        block_bytes = {"other": 4096, "read": 16384, "write": 8192}
        result = {"status": "ok", "iops_raw": {}, "latency_raw": {}, "throughput_raw": {}}
        for operation, rate in rates.items():
            operations = int(seconds * rate * scale)
            result["iops_raw"][operation] = operations
            result["latency_raw"][operation] = operations * micros[operation]
            result["throughput_raw"][operation] = operations * block_bytes[operation]
        for metric in ("iops_raw", "latency_raw", "throughput_raw"):
            result[metric]["total"] = sum(result[metric].values())
        return result

    def snapshot(self):
        with self.lock:
            elapsed = self.elapsed()
            degraded = self.scenario == "degraded"
            nodes = [{
                "uuid": f"mock-node-{n}", "name": f"aff-demo-0{n}",
                "state": "down" if degraded and n == 2 else "up",
                "membership": "member", "location": "Demo rack A / Zurich",
                "version": {"full": "NetApp Release 9.14.1P10: Demo fixture"},
                "controller": {"over_temperature": "over" if degraded and n == 1 else "normal"},
                "uptime": 864000 + int(elapsed)
            } for n in (1, 2)]
            svms = [{"uuid": f"mock-svm-{n}", "name": f"svm-san-0{n}",
                     "state": "stopped" if degraded and n == 2 else "running",
                     "comment": "SAN demonstration storage virtual machine"} for n in (1, 2)]
            volumes = []
            luns = []
            for index, name in enumerate(("vol_database_01", "vol_database_02", "vol_vmware_01", "vol_backup_01"), 1):
                size = (index + 1) * 1024 ** 4
                used = int(size * (0.96 if degraded and index == 2 else (0.30 + index * 0.1)))
                svm = {"name": svms[(index - 1) % 2]["name"]}
                volumes.append({"uuid": f"mock-volume-{index}", "name": name,
                                "comment": "Module demonstration volume", "svm": svm,
                                "state": "offline" if degraded and index == 2 else "online",
                                "type": "dp" if index == 4 else "rw",
                                "space": {"size": size, "used": used, "available": size - used},
                                "statistics": self.statistics(index, elapsed)})
                luns.append({"uuid": f"mock-lun-{index}", "name": f"/vol/{name}/lun0", "svm": svm,
                             "space": {"size": size // 2, "used": used // 2},
                             "status": {"state": "space_error" if degraded and index == 2 else "online",
                                        "container_state": "volume_offline" if degraded and index == 2 else "online"}})
            chassis = [{"id": "AFF-A700-DEMO-01", "state": "error" if degraded else "ok",
                        "frus": [{"id": name, "state": "error" if degraded and name == "PSU-02" else "ok"}
                                 for name in ("PSU-01", "PSU-02", "FAN-01", "FAN-02")]}]
            disks = [{"name": f"1.{(index - 1) // 4}.{index:02d}",
                      "node": {"name": nodes[(index - 1) // 4]["name"]},
                      "state": "broken" if degraded and index == 6 else "present"} for index in range(1, 9)]
            ethernet = []
            fc = []
            for node_index, node in enumerate(nodes, 1):
                for port_index in range(2):
                    # Names are globally unique: supplied port JSONPaths filter
                    # on port name only, without the node name.
                    ethernet.append({"name": f"e{node_index}{chr(97 + port_index)}", "node": {"name": node["name"]},
                                     "type": "physical", "enabled": True,
                                     "state": "down" if degraded and node_index == 2 and port_index == 0 else "up",
                                     "broadcast_domain": {"name": "SAN-data"}, "mtu": 9000, "speed": 25000})
                    fc.append({"name": f"fc{node_index}{chr(97 + port_index)}", "node": {"name": node["name"]},
                               "description": "32 Gb FC target adapter", "enabled": True,
                               "fabric": {"switch_port": f"SAN-SW-0{node_index}:{port_index + 1}"},
                               "state": "link_disconnected" if degraded and node_index == 2 and port_index == 0 else "online"})
            cluster_statistics = self.statistics(10, elapsed)
            if degraded:
                cluster_statistics["status"] = "partial_no_response"
            cluster = {"uuid": "mock-cluster-aff-a700", "name": "AFF-A700-DEMO",
                       "location": "Zurich / demonstration only",
                       "version": {"full": "NetApp Release 9.14.1P10: Demo fixture"},
                       "statistics": cluster_statistics}
            records = {"/api/cluster/nodes": nodes, "/api/cluster/chassis": chassis,
                       "/api/storage/disks": disks, "/api/storage/luns": luns,
                       "/api/storage/volumes": volumes, "/api/svm/svms": svms,
                       "/api/network/ethernet/ports": ethernet, "/api/network/fc/ports": fc}
            if self.scenario == "empty":
                records = {path: [] for path in records}
            payloads = {path: {"records": values, "num_records": len(values)} for path, values in records.items()}
            payloads["/api/cluster"] = cluster
            return payloads

    def status(self):
        with self.lock:
            snapshot = self.snapshot()
            return {"fixture": self.identity, "scenario": self.scenario, "scenarios": list(self.scenarios),
                    "uptime_seconds": int(self.elapsed()),
                    "counts": {path: payload["num_records"] for path, payload in snapshot.items()
                               if "num_records" in payload}}

    def set_scenario(self, scenario):
        with self.lock:
            if scenario not in self.scenarios:
                raise ValueError("scenario must be one of: " + ", ".join(self.scenarios))
            self.scenario = scenario
            self.changed.notify_all()
            return self.status()


def make_server(host="0.0.0.0", port=8080, username="mock", password="mock", fixture=None):
    model = fixture if fixture is not None else NetAppFixture()
    expected_auth = "Basic " + base64.b64encode(f"{username}:{password}".encode()).decode()

    class Handler(BaseHTTPRequestHandler):
        server_version = "NetAppModuleMock/1.0"

        def log_message(self, format, *args):
            pass

        def reply(self, code, payload, authenticate=False):
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            try:
                self.send_response(code)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Cache-Control", "no-store")
                if authenticate:
                    self.send_header("WWW-Authenticate", 'Basic realm="NetApp module demo"')
                self.end_headers()
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                pass  # A timeout client may have disconnected during recovery.

        def authenticated(self):
            provided = self.headers.get("Authorization", "")
            if hmac.compare_digest(provided.encode(), expected_auth.encode()):
                return True
            self.reply(401, {"error": {"message": "Basic authentication required"}}, authenticate=True)
            return False

        def do_GET(self):
            path = urlsplit(self.path).path
            if path == "/healthz":
                self.reply(200, {"status": "ok", "fixture": model.identity})
                return
            if not self.authenticated():
                return
            if path == "/_mock/status":
                self.reply(200, model.status())
                return
            if path.startswith("/api/"):
                # Send no headers or body: the HTTP agent must hit its own timeout.
                # Keep the control endpoints available and release abandoned workers.
                with model.changed:
                    while model.scenario == "unresponsive":
                        model.changed.wait(timeout=0.25)
                        try:
                            readable, _, _ = select.select([self.connection], [], [], 0)
                            if readable and not self.connection.recv(1, socket.MSG_PEEK):
                                return
                        except OSError:
                            return
            payload = model.snapshot().get(path)
            self.reply(200, payload) if payload is not None else self.reply(404, {"error": {"message": "Unknown fixture endpoint"}})

        def do_POST(self):
            if not self.authenticated():
                return
            if urlsplit(self.path).path != "/_mock/scenario":
                self.reply(404, {"error": {"message": "Unknown fixture endpoint"}})
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                if not 0 < length <= 4096:
                    raise ValueError("JSON body must contain between 1 and 4096 bytes")
                payload = json.loads(self.rfile.read(length).decode("utf-8"))
                if not isinstance(payload, dict) or not isinstance(payload.get("scenario"), str):
                    raise ValueError("Expected JSON object with a scenario string")
                self.reply(200, model.set_scenario(payload["scenario"]))
            except (ValueError, UnicodeError) as error:
                self.reply(400, {"error": {"message": str(error)}})

    server = ThreadingHTTPServer((host, port), Handler)
    server.daemon_threads = True
    return server


if __name__ == "__main__":
    httpd = make_server(port=int(os.environ.get("NETAPP_MOCK_PORT", "8080")),
                        username=os.environ.get("NETAPP_MOCK_USERNAME", "mock"),
                        password=os.environ.get("NETAPP_MOCK_PASSWORD", "mock"))
    print(f"NetApp fixture listening on {httpd.server_address[0]}:{httpd.server_address[1]}", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
