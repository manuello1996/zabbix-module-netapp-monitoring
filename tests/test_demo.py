"""Run: python -m unittest discover -s tests -p test_demo.py -v.

All API, Docker, HTTP and state operations are isolated from real installations.
"""
import contextlib
import importlib.util
import io
from pathlib import Path
import re
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import uuid

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("netapp_demo", ROOT / "dev/mock-netapp/demo.py")
DEMO = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEMO)
REAL_COMPOSE = DEMO.compose


class FixtureAPI:
    """Fail closed for unexpected methods; no network transport exists."""
    url = "https://example.invalid/api_jsonrpc.php"

    def __init__(self, populated=True):
        self.calls = []
        self.host = {"hostid": "41", "host": DEMO.HOST, "name": "Demo",
                     "tags": [{"tag": "fixture", "value": DEMO.OWNER}],
                     "parentTemplates": [{"templateid": "42"}]} if populated else None
        self.template = {"templateid": "42", "host": DEMO.TEMPLATE,
                         "description": DEMO.OWNER + ": fixture"} if populated else None
        self.other_hosts = []
        self.children = []
        self.hostgroup = None
        self.templategroup = None
        self.group_hosts = []
        self.group_templates = []
        self.fail_delete = None
        self.macros = [{"hostmacroid": str(index), "macro": name} for index, name in enumerate(
            ["{$NETAPP.URL}", "{$NETAPP.USERNAME}", "{$NETAPP.PASSWORD}", "{$KEEP.ME}"], 1)]

    def call(self, method, params):
        self.calls.append((method, params))
        if method == self.fail_delete:
            raise RuntimeError("simulated interrupted cleanup")
        if method == "host.get":
            if "templateids" in params:
                return ([{"hostid": self.host["hostid"]}] if self.host else []) + self.other_hosts
            if "groupids" in params:
                return self.group_hosts
            assert params["filter"] == {"host": DEMO.HOST}
            return [self.host] if self.host else []
        if method == "template.get":
            if "parentTemplateids" in params:
                return self.children
            if "groupids" in params:
                return self.group_templates
            assert params["filter"] == {"host": DEMO.TEMPLATE}
            return [self.template] if self.template else []
        if method in ("hostgroup.get", "templategroup.get"):
            group = self.hostgroup if method == "hostgroup.get" else self.templategroup
            return [{"groupid": group}] if group else []
        if method == "usermacro.get":
            return self.macros
        if method == "usermacro.update":
            assert params["hostmacroid"] in ("1", "2", "3")
            return {"hostmacroids": [params["hostmacroid"]]}
        if method == "usermacro.create":
            assert params["hostid"] == "41"
            assert params["macro"] in ("{$NETAPP.URL}", "{$NETAPP.USERNAME}", "{$NETAPP.PASSWORD}")
            self.macros.append({"hostmacroid": "99", "macro": params["macro"]})
            return {"hostmacroids": ["99"]}
        if method in ("hostgroup.create", "templategroup.create"):
            key = "hostgroup" if method == "hostgroup.create" else "templategroup"
            setattr(self, key, "51" if key == "hostgroup" else "52")
            return {"groupids": [getattr(self, key)]}
        if method == "configuration.import":
            self.template = {"templateid": "42", "host": DEMO.TEMPLATE, "description": DEMO.OWNER}
            return True
        if method == "host.create":
            self.host = {"hostid": "41", **params, "parentTemplates": params["templates"]}
            return {"hostids": ["41"]}
        if method == "host.update":
            assert params["hostid"] == "41"
            assert params["inventory_mode"] == 0
            assert params["inventory"] == {"notes": DEMO.DEMO_NOTES}
            self.host.update(params)
            return {"hostids": ["41"]}
        if method in ("host.delete", "template.delete"):
            key = "host" if method == "host.delete" else "template"
            expected = "41" if key == "host" else "42"
            assert params == [expected]
            setattr(self, key, None)
            return {key + "ids": params}
        if method in ("hostgroup.delete", "templategroup.delete"):
            key = "hostgroup" if method == "hostgroup.delete" else "templategroup"
            assert params == [getattr(self, key)]
            setattr(self, key, None)
            return {"groupids": params}
        raise AssertionError("Unexpected API mutation/query: " + method)


class DemoTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        state = patch.object(DEMO, "STATE", Path(self.temp.name) / "state.json")
        state.start()
        self.addCleanup(state.stop)
        for name in ("compose", "mock_request"):
            patcher = patch.object(DEMO, name, return_value={"status": "ok"})
            setattr(self, name, patcher.start())
            self.addCleanup(patcher.stop)
        patcher = patch.object(DEMO.time, "sleep")
        self.sleep = patcher.start()
        self.addCleanup(patcher.stop)
        self.output = contextlib.redirect_stdout(io.StringIO())
        self.output.__enter__()
        self.addCleanup(self.output.__exit__, None, None, None)
        self.args = SimpleNamespace(mock_user="fixture-user", mock_password="fixture-password",
                                    mock_url="http://example.invalid:18080", netapp_url=DEMO.MOCK_URL,
                                    scenario="degraded", zabbix_url="https://example.invalid")

    def state(self, endpoint, **extra):
        data = {"owner": DEMO.OWNER, "api": endpoint.url, **extra}
        DEMO.save_state(data)
        return data

    def deletes(self, api):
        return [(method, params) for method, params in api.calls if method.endswith(".delete")]

    def test_fixed_isolated_names(self):
        self.assertEqual(DEMO.HOST, "netapp-module-demo")
        self.assertEqual(DEMO.TEMPLATE, "DEMO - NetApp AFF A700 by HTTP")
        self.assertEqual(DEMO.OWNER, "netapp-module-mock-v1")
        self.assertIn("NetApp AFF A700 by HTTP", DEMO.TEMPLATE)

    def test_template_is_deterministic_isolated_uuid4_and_source_unchanged(self):
        path = ROOT / "template_san_netapp_aff_a700_http.yaml"
        before = path.read_bytes()
        transformed = DEMO.demo_template()
        self.assertEqual(transformed, DEMO.demo_template())
        self.assertEqual(path.read_bytes(), before)
        self.assertIn("template: '" + DEMO.TEMPLATE + "'", transformed)
        self.assertIn(DEMO.TEMPLATE_GROUP, transformed)
        self.assertIn(DEMO.OWNER + ": disposable synthetic monitoring fixture.", transformed)
        original_ids = re.findall(r"\buuid: ([a-f0-9]{32})", before.decode())
        demo_ids = re.findall(r"\buuid: ([a-f0-9]{32})", transformed)
        self.assertEqual(len(original_ids), len(demo_ids))
        self.assertGreater(len(demo_ids), 100)
        self.assertFalse(set(original_ids) & set(demo_ids))
        self.assertEqual(len(set(demo_ids)), len(demo_ids))
        self.assertTrue(all(uuid.UUID(value).version == 4 for value in demo_ids))

    def test_only_polling_item_delays_change(self):
        source = (ROOT / "template_san_netapp_aff_a700_http.yaml").read_text(encoding="utf-8")
        transformed = DEMO.demo_template()
        # Locate delay within the same object: fields are at the same indent as type.
        def delays(text):
            result = []
            lines = text.splitlines()
            for index, line in enumerate(lines):
                match = re.fullmatch(r"(\s*)type: (HTTP_AGENT|CALCULATED|DEPENDENT)", line)
                if not match:
                    continue
                indent, kind = match.groups()
                delay = None
                for candidate in lines[index + 1:]:
                    if candidate.strip() and len(candidate) - len(candidate.lstrip()) < len(indent):
                        break
                    if candidate.startswith(indent + "delay:"):
                        delay = candidate.split(":", 1)[1].strip()
                        break
                result.append((kind, delay))
            return result
        original, updated = delays(source), delays(transformed)
        self.assertEqual(len(original), len(updated))
        for (kind, old), (new_kind, new) in zip(original, updated):
            self.assertEqual(kind, new_kind)
            self.assertEqual(new, old if kind == "DEPENDENT" else "15s")

    def test_state_mismatch_refuses_before_docker_or_api(self):
        for fields in ({"owner": "another-owner"}, {"api": "https://other.invalid/api_jsonrpc.php"}):
            for command in (DEMO.install, DEMO.uninstall):
                api = FixtureAPI()
                self.state(api, **fields)
                with self.assertRaisesRegex(RuntimeError, "another owner or Zabbix endpoint"):
                    command(api, self.args)
                self.assertEqual(api.calls, [])
        self.compose.assert_not_called()

    def test_unowned_names_refuse_changes(self):
        for kind in ("host", "template"):
            for command in (DEMO.install, DEMO.uninstall):
                api = FixtureAPI()
                if kind == "host":
                    api.host["tags"] = [{"tag": "environment", "value": "production"}]
                else:
                    api.template["description"] = "Existing production template"
                with self.assertRaisesRegex(RuntimeError, "ownership"):
                    command(api, self.args)
                self.assertEqual(self.deletes(api), [])
        self.compose.assert_not_called()

    def test_install_creates_only_fixture_and_never_updates_real_template(self):
        api = FixtureAPI(populated=False)
        DEMO.install(api, self.args)
        imports = [params for method, params in api.calls if method == "configuration.import"]
        self.assertEqual(len(imports), 1)
        self.assertEqual(imports[0]["source"], DEMO.demo_template())
        self.assertTrue(all(not rule["updateExisting"] for rule in imports[0]["rules"].values()))
        create = next(params for method, params in api.calls if method == "host.create")
        self.assertEqual(create["host"], DEMO.HOST)
        self.assertEqual(create["inventory_mode"], 0)
        self.assertEqual(create["inventory"]["notes"], DEMO.DEMO_NOTES)
        self.assertEqual(create["templates"], [{"templateid": "42"}])
        self.assertIn({"tag": "fixture", "value": DEMO.OWNER}, create["tags"])
        self.assertIn({"tag": "environment", "value": "demo"}, create["tags"])
        self.assertEqual(next(m for m in create["macros"] if m["macro"] == "{$NETAPP.PASSWORD}")["type"], 1)
        self.assertFalse(any(method in ("template.update", "template.delete") for method, _ in api.calls))
        self.assertEqual(DEMO.load_state(api)["created_template_groupid"], "52")
        self.assertEqual(DEMO.load_state(api)["created_host_groupid"], "51")
        self.mock_request.assert_called_with(self.args, "/_mock/scenario", {"scenario": "degraded"})

    def test_repeated_up_does_not_reimport_or_duplicate_objects(self):
        api = FixtureAPI()
        DEMO.install(api, self.args)
        DEMO.install(api, self.args)
        self.assertFalse(any(method in ("host.create", "template.create", "hostgroup.create", "templategroup.create",
                                      "configuration.import") for method, _ in api.calls))
        self.assertEqual(DEMO.load_state(api)["hostid"], "41")
        self.assertEqual(DEMO.load_state(api)["templateid"], "42")
        updates = [params for method, params in api.calls if method == "usermacro.update"]
        self.assertEqual(len(updates), 6)
        self.assertEqual({params["hostmacroid"] for params in updates}, {"1", "2", "3"})
        self.assertEqual(api.host['inventory']['notes'], DEMO.DEMO_NOTES)
        self.assertTrue(all(params["type"] == 1 for params in updates if params["hostmacroid"] == "3"))

    def test_other_consumers_refused_before_any_deletion(self):
        for consumer in ("host", "template", "missing_fixture_host"):
            api = FixtureAPI()
            self.state(api, created_host_groupid="51", created_template_groupid="52")
            if consumer == "template":
                api.children = [{"templateid": "REAL_TEMPLATE"}]
            else:
                api.other_hosts = [{"hostid": "REAL_HOST"}]
                if consumer == "missing_fixture_host":
                    api.host = None
            with self.assertRaisesRegex(RuntimeError, "other consumers"):
                DEMO.uninstall(api, self.args)
            self.assertEqual(self.deletes(api), [])
            self.assertTrue(DEMO.STATE.exists())
        self.compose.assert_not_called()

    def test_existing_fixture_with_changed_template_links_refuses_before_compose(self):
        for links in ([], [{"templateid": "REAL_TEMPLATE"}],
                      [{"templateid": "42"}, {"templateid": "REAL_TEMPLATE"}]):
            api = FixtureAPI()
            api.host["parentTemplates"] = links
            with self.assertRaisesRegex(RuntimeError, "different template links"):
                DEMO.install(api, self.args)
            self.assertEqual(self.deletes(api), [])
            self.assertFalse(any(method.startswith("usermacro.") for method, _ in api.calls))
        self.compose.assert_not_called()

    def test_missing_fixture_macro_is_created_preserving_other_macros(self):
        api = FixtureAPI()
        api.macros = [macro for macro in api.macros if macro["macro"] != "{$NETAPP.PASSWORD}"]
        DEMO.install(api, self.args)
        creates = [params for method, params in api.calls if method == "usermacro.create"]
        self.assertEqual(creates, [{"hostid": "41", "macro": "{$NETAPP.PASSWORD}",
                                   "value": self.args.mock_password, "type": 1}])
        self.assertIn({"hostmacroid": "4", "macro": "{$KEEP.ME}"}, api.macros)

    def test_cleanup_is_idempotent(self):
        api = FixtureAPI()
        DEMO.uninstall(api, self.args)
        DEMO.uninstall(api, self.args)
        self.assertEqual(self.deletes(api), [("host.delete", ["41"]), ("template.delete", ["42"])])
        self.assertFalse(DEMO.STATE.exists())
        self.assertEqual(self.compose.call_count, 2)

    def test_partial_cleanup_resumes_after_template_delete_failure(self):
        api = FixtureAPI()
        self.state(api, hostid="41", templateid="42")
        api.fail_delete = "template.delete"
        with self.assertRaisesRegex(RuntimeError, "interrupted cleanup"):
            DEMO.uninstall(api, self.args)
        self.assertIsNone(api.host)
        self.assertTrue(DEMO.STATE.exists())
        self.compose.assert_not_called()
        api.fail_delete = None
        DEMO.uninstall(api, self.args)
        self.assertFalse(DEMO.STATE.exists())
        self.assertEqual(sum(method == "host.delete" for method, _ in api.calls), 1)

    def test_only_created_empty_groups_are_deleted(self):
        for occupied in (False, True):
            api = FixtureAPI()
            api.hostgroup, api.templategroup = "51", "52"
            self.state(api, created_host_groupid="51", created_template_groupid="52")
            if occupied:
                api.group_hosts = [{"hostid": "REAL_HOST"}]
                api.group_templates = [{"templateid": "REAL_TEMPLATE"}]
            DEMO.uninstall(api, self.args)
            groups = [method for method, _ in self.deletes(api) if "group" in method]
            self.assertEqual(groups, [] if occupied else ["hostgroup.delete", "templategroup.delete"])

    def test_untracked_preexisting_groups_are_preserved(self):
        api = FixtureAPI()
        api.hostgroup, api.templategroup = "51", "52"
        DEMO.uninstall(api, self.args)
        self.assertEqual((api.hostgroup, api.templategroup), ("51", "52"))

    def test_state_preserved_when_compose_down_fails(self):
        api = FixtureAPI()
        self.state(api)
        self.compose.side_effect = RuntimeError("Docker failure")
        with self.assertRaisesRegex(RuntimeError, "Docker failure"):
            DEMO.uninstall(api, self.args)
        self.assertTrue(DEMO.STATE.exists())
        self.compose.side_effect = None
        DEMO.uninstall(api, self.args)
        self.assertFalse(DEMO.STATE.exists())

    def test_health_failure_does_not_import_or_create_objects(self):
        api = FixtureAPI(populated=False)
        self.mock_request.side_effect = OSError("offline")
        with self.assertRaisesRegex(RuntimeError, "did not become reachable"):
            DEMO.install(api, self.args)
        self.assertEqual(self.mock_request.call_count, 30)
        self.assertEqual(self.sleep.call_count, 29)
        self.assertFalse(any(method.endswith(".create") or method == "configuration.import" for method, _ in api.calls))

    def test_compose_credentials_are_environment_only(self):
        with patch.object(DEMO.subprocess, "run") as run:
            REAL_COMPOSE("up", "-d", mock_user="secret-user", mock_password="secret-password")
            command = run.call_args.args[0]
            self.assertNotIn("secret-password", " ".join(command))
            self.assertEqual(run.call_args.kwargs["env"]["NETAPP_MOCK_PASSWORD"], "secret-password")
            self.assertIn("netapp-module-mock", command)


if __name__ == "__main__":
    unittest.main()
