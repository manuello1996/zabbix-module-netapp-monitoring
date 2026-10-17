#!/usr/bin/env python3
"""Install/remove an isolated NetApp demonstration using only Python's standard library."""
import argparse
import base64
import getpass
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
STATE = HERE / '.demo-state.json'
HOST = 'netapp-module-demo'
TEMPLATE = 'DEMO - NetApp AFF A700 by HTTP'
HOST_GROUP = 'NetApp module demo'
TEMPLATE_GROUP = 'Templates/NetApp module demo'
OWNER = 'netapp-module-mock-v1'
MOCK_URL = 'http://netapp-module-mock:8080'
DEMO_NOTES = 'Synthetic NetApp AFF A700 SAN cluster'
UUID_NAMESPACE = uuid.UUID('785b5146-e85d-4db8-8c2e-fb128950604a')


class Zabbix:
    def __init__(self, url, username, password=None, token=None):
        self.url = url.rstrip('/')
        self.url += '' if self.url.endswith('api_jsonrpc.php') else '/api_jsonrpc.php'
        self.token = token
        self.owns_session = not token
        version = self.call('apiinfo.version', {})
        if not version.startswith('7.0.'):
            raise RuntimeError(f'This demo targets Zabbix 7.0; endpoint reports {version}.')
        if not token:
            self.token = self.call('user.login', {'username': username, 'password': password})

    def call(self, method, params):
        payload = {'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}
        if self.token and method != 'apiinfo.version':
            payload['auth'] = self.token
        request = urllib.request.Request(self.url, json.dumps(payload).encode(),
                                         {'Content-Type': 'application/json-rpc'})
        result = json.load(urllib.request.urlopen(request, timeout=45))
        if 'error' in result:
            raise RuntimeError(f"{method}: {result['error'].get('data', result['error']['message'])}")
        return result['result']

    def close(self):
        if self.owns_session and self.token:
            self.call('user.logout', [])
            self.token = None


def compose(*args, mock_user=None, mock_password=None):
    environment = os.environ.copy()
    if mock_user is not None:
        environment['NETAPP_MOCK_USERNAME'] = mock_user
    if mock_password is not None:
        environment['NETAPP_MOCK_PASSWORD'] = mock_password
    subprocess.run(['docker', 'compose', '--project-name', 'netapp-module-mock',
                    '--file', str(HERE / 'compose.yaml'), *args], check=True, env=environment)


def mock_request(args, path, data=None):
    auth = base64.b64encode(f'{args.mock_user}:{args.mock_password}'.encode()).decode()
    request = urllib.request.Request(args.mock_url.rstrip('/') + path,
        None if data is None else json.dumps(data).encode(),
        {'Authorization': 'Basic ' + auth, 'Content-Type': 'application/json'})
    return json.load(urllib.request.urlopen(request, timeout=5))


def demo_template():
    """Transform the shipped YAML, never the real template installed in Zabbix.

    This is deliberately a narrow text transform of our known reference file,
    rather than a general YAML parser. Zabbix validates the resulting YAML on import.
    """
    source = (ROOT / 'template_san_netapp_aff_a700_http.yaml').read_text(encoding='utf-8')
    source = source.replace('NetApp AFF A700 by HTTP', TEMPLATE)
    source = source.replace('Templates/SAN', TEMPLATE_GROUP)
    source = re.sub(r'(?m)(\buuid: )([a-f0-9]{32})',
                    lambda m: m[1] + uuid.UUID(bytes=uuid.uuid5(UUID_NAMESPACE, m[2]).bytes, version=4).hex,
                    source)
    source = source.replace('      description: |\n',
        '      description: |\n        ' + OWNER + ': disposable synthetic monitoring fixture.\n', 1)
    lines = source.splitlines()
    for index in range(len(lines) - 1, -1, -1):
        match = re.fullmatch(r'(\s*)type: (HTTP_AGENT|CALCULATED)', lines[index])
        if not match:
            continue
        indent = match[1]
        cursor = index + 1
        found = False
        while cursor < len(lines) and (not lines[cursor].strip()
                or len(lines[cursor]) - len(lines[cursor].lstrip()) >= len(indent)):
            if lines[cursor].startswith(indent + 'delay:'):
                lines[cursor] = indent + 'delay: 15s'
                found = True
                break
            cursor += 1
        if not found:
            lines.insert(index + 1, indent + 'delay: 15s')
    return '\n'.join(lines) + '\n'


def load_state(api):
    state = json.loads(STATE.read_text()) if STATE.exists() else {'owner': OWNER, 'api': api.url}
    if state.get('owner') != OWNER or state.get('api') != api.url:
        raise RuntimeError('Demo state belongs to another owner or Zabbix endpoint; refusing to change it.')
    return state


def save_state(state):
    temporary = STATE.with_suffix('.tmp')
    temporary.write_text(json.dumps(state, indent=2) + '\n', encoding='utf-8')
    temporary.replace(STATE)


def fixture_objects(api):
    hosts = api.call('host.get', {'output': ['hostid', 'host', 'name'],
        'filter': {'host': HOST}, 'selectTags': ['tag', 'value'], 'selectParentTemplates': ['templateid']})
    templates = api.call('template.get', {'output': ['templateid', 'host', 'description'],
        'filter': {'host': TEMPLATE}})
    if hosts and not any(t['tag'] == 'fixture' and t['value'] == OWNER for t in hosts[0]['tags']):
        raise RuntimeError(f'Host {HOST} exists without our ownership tag; refusing to change it.')
    if templates and OWNER not in templates[0]['description']:
        raise RuntimeError(f'Template {TEMPLATE} exists without our ownership marker; refusing to change it.')
    return (hosts[0] if hosts else None, templates[0] if templates else None)


def install(api, args):
    state = load_state(api)
    host, template = fixture_objects(api)
    if host and (not template or {t['templateid'] for t in host['parentTemplates']}
                 != {template['templateid']}):
        raise RuntimeError('Existing demo host has different template links; remove the fixture before setup.')
    compose('up', '-d', '--build', mock_user=args.mock_user, mock_password=args.mock_password)
    for attempt in range(30):
        try:
            mock_request(args, '/healthz')
            break
        except (OSError, ValueError):
            if attempt == 29:
                raise RuntimeError('Mock did not become reachable at ' + args.mock_url)
            time.sleep(1)
    if not template:
        groups = api.call('templategroup.get', {'output': ['groupid'], 'filter': {'name': TEMPLATE_GROUP}})
        if not groups:
            groupid = api.call('templategroup.create', {'name': TEMPLATE_GROUP})['groupids'][0]
            state['created_template_groupid'] = groupid
            save_state(state)
        rules = {kind: {'createMissing': True, 'updateExisting': False} for kind in
                 ['template_groups', 'templates', 'items', 'discoveryRules', 'triggers', 'graphs', 'valueMaps']}
        api.call('configuration.import', {'format': 'yaml', 'source': demo_template(), 'rules': rules})
        _, template = fixture_objects(api)
        if not template:
            raise RuntimeError('Demo template was not created by import.')
    state['templateid'] = template['templateid']
    save_state(state)
    if not host:
        groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': HOST_GROUP}})
        if groups:
            groupid = groups[0]['groupid']
        else:
            groupid = api.call('hostgroup.create', {'name': HOST_GROUP})['groupids'][0]
            state['created_host_groupid'] = groupid
            save_state(state)
        hostid = api.call('host.create', {'host': HOST, 'name': 'DEMO - NetApp AFF A700 cluster',
            'description': OWNER + ': synthetic data; remove using dev/mock-netapp/demo.py down.',
            'inventory_mode': 0, 'inventory': {'notes': DEMO_NOTES},
            'groups': [{'groupid': groupid}], 'templates': [{'templateid': template['templateid']}],
            'tags': [{'tag': 'fixture', 'value': OWNER}, {'tag': 'environment', 'value': 'demo'}],
            'macros': [{'macro': '{$NETAPP.URL}', 'value': args.netapp_url},
                       {'macro': '{$NETAPP.USERNAME}', 'value': args.mock_user},
                       {'macro': '{$NETAPP.PASSWORD}', 'value': args.mock_password, 'type': 1}]})['hostids'][0]
        state['hostid'] = hostid
        save_state(state)
    else:
        api.call('host.update', {'hostid': host['hostid'], 'inventory_mode': 0,
                                'inventory': {'notes': DEMO_NOTES}})
        # Change only our three host macros; preserve any other host-level macros.
        existing = api.call('usermacro.get', {'output': ['hostmacroid', 'macro'], 'hostids': [host['hostid']]})
        macroids = {m['macro']: m['hostmacroid'] for m in existing}
        for name, value, kind in [('{$NETAPP.URL}', args.netapp_url, 0),
                                  ('{$NETAPP.USERNAME}', args.mock_user, 0),
                                  ('{$NETAPP.PASSWORD}', args.mock_password, 1)]:
            if name in macroids:
                api.call('usermacro.update', {'hostmacroid': macroids[name], 'value': value, 'type': kind})
            else:
                api.call('usermacro.create', {'hostid': host['hostid'], 'macro': name, 'value': value, 'type': kind})
        state['hostid'] = host['hostid']
        save_state(state)
    mock_request(args, '/_mock/scenario', {'scenario': args.scenario})
    print('Demo ready; allow 1-3 minutes for discovery and two performance samples.')
    print(args.zabbix_url.rstrip('/') + '/zabbix.php?action=netapp.monitoring.view&hostid=' + state['hostid'])
    print('NetApp URL used by the Zabbix server: ' + args.netapp_url)


def status(api, args):
    host, template = fixture_objects(api)
    print('Demo host:', host['hostid'] if host else 'absent')
    print('Demo template:', template['templateid'] if template else 'absent')
    try:
        print('Mock:', json.dumps(mock_request(args, '/_mock/status')))
    except OSError as error:
        print('Mock unavailable:', error)
    if host:
        items = api.call('item.get', {'output': ['itemid', 'key_', 'state', 'error', 'lastclock'],
                                     'hostids': [host['hostid']]})
        rules = api.call('discoveryrule.get', {'output': ['key_', 'state', 'error'],
                                             'hostids': [host['hostid']]})
        print('Items:', len(items), '| with samples:', sum(int(i['lastclock']) > 0 for i in items))
        errors = [i for i in items + rules if i.get('state') == '1']
        print('Unsupported items/discovery rules:', len(errors))
        for item in errors[:10]:
            print(' ', item['key_'] + ': ' + item['error'])
        problems = api.call('problem.get', {'output': ['name', 'severity'], 'hostids': [host['hostid']],
                                          'recent': False})
        print('Active problems:', len(problems))
        for problem in problems[:10]:
            print(' ', problem['name'])


def uninstall(api, args):
    state = load_state(api)
    host, template = fixture_objects(api)
    # Refuse a template deletion that could cascade to objects added by a user.
    if template:
        linked = api.call('host.get', {'output': ['hostid'], 'templateids': [template['templateid']]})
        children = api.call('template.get', {'output': ['templateid'],
                                             'parentTemplateids': [template['templateid']]})
        if children or any(h['hostid'] != (host or {}).get('hostid') for h in linked):
            raise RuntimeError('Demo template has other consumers; unlink them before removing the demo.')
    if host:
        api.call('host.delete', [host['hostid']])
        print('Removed demo host and its discovered objects/history.')
    if template:
        api.call('template.delete', [template['templateid']])
        print('Removed isolated demo template.')
    groupid = state.get('created_host_groupid')
    if groupid:
        remaining = api.call('host.get', {'output': ['hostid'], 'groupids': [groupid]})
        exists = api.call('hostgroup.get', {'output': ['groupid'], 'groupids': [groupid]})
        if exists and not remaining:
            api.call('hostgroup.delete', [groupid])
    groupid = state.get('created_template_groupid')
    if groupid:
        remaining = api.call('template.get', {'output': ['templateid'], 'groupids': [groupid]})
        exists = api.call('templategroup.get', {'output': ['groupid'], 'groupids': [groupid]})
        if exists and not remaining:
            api.call('templategroup.delete', [groupid])
    compose('down')
    if STATE.exists():
        STATE.unlink()
    print('Demo removed. Zabbix may remove stored history asynchronously through housekeeping.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['up', 'down', 'status', 'scenario'])
    parser.add_argument('--scenario', choices=['healthy', 'degraded', 'empty', 'unresponsive'], default='healthy')
    parser.add_argument('--zabbix-url', default=os.getenv('ZABBIX_URL', 'http://127.0.0.1'))
    parser.add_argument('--user', default=os.getenv('ZABBIX_USER', 'Admin'))
    parser.add_argument('--mock-url', default='http://127.0.0.1:18080')
    parser.add_argument('--netapp-url', default=MOCK_URL)
    parser.add_argument('--mock-user', default=os.getenv('NETAPP_MOCK_USERNAME', 'mock'))
    parser.add_argument('--mock-password', default=os.getenv('NETAPP_MOCK_PASSWORD', 'mock'))
    args = parser.parse_args()
    if args.command == 'scenario':
        print(json.dumps(mock_request(args, '/_mock/scenario', {'scenario': args.scenario}), indent=2))
        return
    token = os.getenv('ZABBIX_API_TOKEN')
    password = None if token else os.getenv('ZABBIX_PASSWORD') or getpass.getpass('Zabbix password: ')
    api = Zabbix(args.zabbix_url, args.user, password, token)
    try:
        {'up': install, 'down': uninstall, 'status': status}[args.command](api, args)
    finally:
        api.close()


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        print('Error:', error, file=sys.stderr)
        sys.exit(1)
