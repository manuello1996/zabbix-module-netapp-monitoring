"""Run: python -m unittest discover -s tests -p test_mock_netapp.py -v"""
import base64
import importlib.util
import json
from pathlib import Path
import re
import socket
import threading
import unittest
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlsplit
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("netapp_fixture", ROOT / "dev/mock-netapp/server.py")
MOCK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MOCK)


class MockNetAppTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.clock = [100000.0]
        cls.fixture = MOCK.NetAppFixture(clock=lambda: cls.clock[0])
        cls.server = MOCK.make_server("127.0.0.1", 0, fixture=cls.fixture)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = "http://127.0.0.1:" + str(cls.server.server_address[1])

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=3)

    def setUp(self):
        self.fixture.set_scenario("healthy")

    def request(self, path, payload=None, auth="mock:mock"):
        headers = {"Content-Type": "application/json"}
        if auth is not None:
            headers["Authorization"] = "Basic " + base64.b64encode(auth.encode()).decode()
        data = json.dumps(payload).encode() if payload is not None else None
        request = Request(self.base + path, data=data, headers=headers)
        with urlopen(request, timeout=3) as response:
            return json.load(response)

    def test_authentication_and_health(self):
        self.assertEqual(self.request("/healthz", auth=None)["status"], "ok")
        for path, payload in (("/api/cluster", None), ("/_mock/status", None),
                              ("/_mock/scenario", {"scenario": "degraded"})):
            for auth in (None, "mock:incorrect"):
                with self.assertRaises(HTTPError) as error:
                    self.request(path, payload, auth)
                self.assertEqual(error.exception.code, 401)

    def test_every_template_endpoint_and_requested_field(self):
        template = (ROOT / "template_san_netapp_aff_a700_http.yaml").read_text(encoding="utf-8")
        urls = re.findall(r"url: '\{\$NETAPP.URL\}([^']+)'", template)
        self.assertGreaterEqual(len(urls), 17)

        def assert_field(record, parts):
            if not parts:
                return
            if isinstance(record, list):
                for child in record:
                    assert_field(child, parts)
            else:
                self.assertIn(parts[0], record)
                assert_field(record[parts[0]], parts[1:])

        endpoints = set()
        for url in urls:
            endpoints.add(urlsplit(url).path)
            payload = self.request(url)
            records = payload.get("records", [payload])
            if "num_records" in payload:
                self.assertEqual(payload["num_records"], len(records))
            fields = parse_qs(urlsplit(url).query).get("fields", [""])[0]
            for field in fields.split(","):
                if field and field != "*":
                    for record in records:
                        assert_field(record, field.split("."))
        self.assertEqual(len(endpoints), 9)
        nodes = self.request("/api/cluster/nodes")["records"]
        for node in nodes:
            for field in ("name", "state", "membership", "location", "version", "controller", "uptime"):
                self.assertIn(field, node)
            self.assertEqual(node["controller"]["over_temperature"], "normal")
            self.assertIn("full", node["version"])
        for path in ("/api/network/ethernet/ports", "/api/network/fc/ports"):
            names = [record["name"] for record in self.request(path)["records"]]
            self.assertEqual(len(names), len(set(names)), "Port JSONPaths require globally unique names")

    def test_inventory_counts_and_nested_frus(self):
        expected = {"/api/cluster/nodes": 2, "/api/svm/svms": 2, "/api/storage/volumes": 4,
                    "/api/storage/luns": 4, "/api/storage/disks": 8, "/api/cluster/chassis": 1,
                    "/api/network/ethernet/ports": 4, "/api/network/fc/ports": 4}
        self.assertEqual(self.request("/_mock/status")["counts"], expected)
        chassis = self.request("/api/cluster/chassis")["records"][0]
        self.assertEqual(len(chassis["frus"]), 4)
        self.assertTrue(all(fru["state"] == "ok" for fru in chassis["frus"]))
        for volume in self.request("/api/storage/volumes")["records"]:
            self.assertEqual(volume["space"]["used"] + volume["space"]["available"], volume["space"]["size"])

    def test_scenario_degradation_and_recovery(self):
        result = self.request("/_mock/scenario", {"scenario": "degraded"})
        self.assertEqual(result["scenario"], "degraded")
        self.assertEqual(self.request("/api/cluster")["statistics"]["status"], "partial_no_response")
        self.assertEqual(self.request("/api/cluster/nodes")["records"][1]["state"], "down")
        self.assertEqual(self.request("/api/cluster/chassis")["records"][0]["frus"][1]["state"], "error")
        self.assertEqual(self.request("/api/storage/luns")["records"][1]["status"]["container_state"], "volume_offline")
        self.request("/_mock/scenario", {"scenario": "healthy"})
        self.assertEqual(self.request("/api/cluster")["statistics"]["status"], "ok")
        self.assertTrue(all(record["state"] == "up" for record in self.request("/api/cluster/nodes")["records"]))

    def test_empty_is_reversible(self):
        self.request("/_mock/scenario", {"scenario": "empty"})
        for path, count in self.request("/_mock/status")["counts"].items():
            self.assertEqual(count, 0)
            self.assertEqual(self.request(path), {"records": [], "num_records": 0})
        self.assertEqual(self.request("/api/cluster")["statistics"]["status"], "ok")
        self.request("/_mock/scenario", {"scenario": "healthy"})
        self.assertEqual(self.request("/api/storage/volumes")["num_records"], 4)

    def test_unresponsive_api_times_out_but_controls_allow_recovery(self):
        before = self.request("/api/cluster")["statistics"]["iops_raw"]["total"]
        self.request("/_mock/scenario", {"scenario": "unresponsive"})
        auth = "Basic " + base64.b64encode(b"mock:mock").decode()
        try:
            for path in ("/api/cluster", "/api/storage/volumes?fields=name", "/api/cluster/nodes"):
                with self.assertRaises((TimeoutError, socket.timeout)):
                    with urlopen(Request(self.base + path, headers={"Authorization": auth}), timeout=0.15):
                        self.fail("Unresponsive API sent a response")
            self.assertEqual(self.request("/_mock/status")["scenario"], "unresponsive")
            self.assertEqual(self.request("/healthz", auth=None)["status"], "ok")
            with self.assertRaises(HTTPError) as error:
                self.request("/_mock/scenario", {"scenario": "healthy"}, auth="mock:wrong")
            self.assertEqual(error.exception.code, 401)
            self.assertEqual(self.request("/_mock/status")["scenario"], "unresponsive")
            self.clock[0] += 60
        finally:
            self.request("/_mock/scenario", {"scenario": "healthy"})
        self.assertGreater(self.request("/api/cluster")["statistics"]["iops_raw"]["total"], before)
        self.assertEqual(self.request("/api/storage/volumes")["num_records"], 4)

    def test_continuous_counters_and_template_latency_units(self):
        before = self.request("/api/cluster")["statistics"]
        self.request("/_mock/scenario", {"scenario": "degraded"})
        self.clock[0] += 60
        after = self.request("/api/cluster")["statistics"]
        delta_iops = after["iops_raw"]["read"] - before["iops_raw"]["read"]
        delta_latency = after["latency_raw"]["read"] - before["latency_raw"]["read"]
        self.assertEqual(delta_iops / 60, 12000)
        self.assertEqual(delta_latency / delta_iops * 0.001, 0.8)
        self.assertEqual((after["throughput_raw"]["read"] - before["throughput_raw"]["read"]) / delta_iops, 16384)
        self.request("/_mock/scenario", {"scenario": "empty"})
        self.clock[0] += 60
        self.request("/_mock/scenario", {"scenario": "healthy"})
        resumed = self.request("/api/cluster")["statistics"]
        self.assertGreater(resumed["iops_raw"]["total"], after["iops_raw"]["total"])
        self.clock[0] -= 30
        self.assertEqual(self.request("/api/cluster")["statistics"]["iops_raw"], resumed["iops_raw"])
        self.clock[0] += 30

    def test_invalid_request_does_not_change_scenario(self):
        for payload in ({"scenario": "invalid"}, {}, [], {"scenario": 2}):
            with self.assertRaises(HTTPError) as error:
                self.request("/_mock/scenario", payload)
            self.assertEqual(error.exception.code, 400)
            self.assertEqual(self.request("/_mock/status")["scenario"], "healthy")
        with self.assertRaises(HTTPError) as error:
            self.request("/api/not-a-real-resource")
        self.assertEqual(error.exception.code, 404)


if __name__ == "__main__":
    unittest.main()
