// Native Zabbix menu generation plus the module adapter; no browser required.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const native = readFileSync(new URL('../../../zabbix-src-7.0/ui/js/menupopup.js', import.meta.url), 'utf8');
const start = native.indexOf('function getMenuPopupItem(options)');
const end = native.indexOf('function getMenuPopupItemPrototype(options)');
const source = readFileSync(new URL('../assets/js/menus.js', import.meta.url), 'utf8');
class Curl {
 constructor(path) { this.url = new URL(path, 'http://127.0.0.1/'); }
 setArgument(name, value) { this.url.searchParams.set(name, value); }
 getUrl() { return this.url.toString(); }
}
const calls = [];
const context = {URL, URLSearchParams, Curl, t: label => label,
 document: {readyState: 'complete', addEventListener() {}},
 location: {href: 'http://127.0.0.1/zabbix.php?action=netapp.monitoring.view&hostid=20'},
 view: {editItem(target, data) { calls.push(data); }}};
context.window = context;
vm.createContext(context);
vm.runInContext(native.slice(start, end), context);
vm.runInContext(source, context);
const options = {itemid: '1001', hostid: '20', name: 'Latency', key: 'netapp.cluster.statistics.latency.read',
 backurl: 'zabbix.php?action=netapp.monitoring.view&hostid=20', context: 'host',
 showGraph: true, history: true, trends: true, allowed_ui_latest_data: true,
 allowed_ui_conf_hosts: true, isWriteable: true, triggers: [], isExecutable: true};
const entries = opts => context.getMenuPopupItem(opts).flatMap(section => section.items);
let menu = entries(options);
assert.equal(menu.map(item => item.label).join('|'), 'Item|Graph|Values|500 latest values');
menu[0].clickCallback();
assert.equal(calls[0].itemid, '1001');
assert.equal(new URL(menu[1].url).searchParams.get('action'), 'showgraph');
assert.equal(new URL(menu[2].url).searchParams.get('action'), 'showvalues');
assert.equal(new URL(menu[3].url).searchParams.get('action'), 'showlatest');
assert.equal(entries({...options, showGraph: false}).map(item => item.label).join('|'), 'Item|Values|500 latest values');
assert.equal(entries({...options, isWriteable: false, allowed_ui_conf_hosts: false}).map(item => item.label).join('|'),
 'Graph|Values|500 latest values');
menu = entries({...options, history: false, trends: false});
assert.equal(menu.find(item => item.label === 'Values').disabled, true);
assert.equal(menu.find(item => item.label === '500 latest values').disabled, true);
assert.ok(entries({...options, backurl: 'zabbix.php?action=latest.view'}).some(item => item.label === 'Execute now'),
 'Menus outside NetApp keep their native actions');
console.log('Latest-value menu checks passed: native actions, numeric graphs, permissions, retention, item edit, page isolation.');
