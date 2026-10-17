// Run: node tests/native-menus.mjs. Tests page handlers without opening a browser.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../assets/js/menus.js', import.meta.url), 'utf8');
const setup = (action = 'netapp.monitoring.view', existing, loading = false) => {
	const calls = [], listeners = new Map();
	const window = {location: {href: `http://127.0.0.1/zabbix.php?action=${action}&hostid=10715&tab=problems&page=2`,
		assign(url) { calls.push(['navigate', url]); },
		reload() { calls.push(['reload']); }},
		history: {replaceState(_state, _title, url) { calls.push(['url', url]); }}};
	if (existing) window.view = existing;
	const context = {window, URL, MouseEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
		document: {readyState: loading ? 'loading' : 'complete', addEventListener(name, fn) { listeners.set(name, fn); }},
		PopUp(action, data, options) {
			const events = new Map();
			calls.push({action, data, options, events});
			return {$dialogue: [{addEventListener(name, fn) { events.set(name, fn); }}]};
		},
		clearMessages() {}, postMessageOk(title) { calls.push(['success', title]); }
	};
	if (existing) context.view = existing;
	vm.runInNewContext(source, context);
	return {window, calls, listeners};
};
const env = setup();
assert.equal(typeof env.window.view.editTrigger, 'function');
env.window.view.editTrigger({triggerid: '12', context: 'host'});
assert.equal(env.calls[0].action, 'trigger.edit');
assert.equal(env.calls[0].data.triggerid, '12');
assert.equal(env.calls[0].options.prevent_navigation, true);
env.calls[0].events.get('dialogue.close')();
assert.equal(env.calls.at(-1)[1], env.window.location.href, 'Cancel restores the current HTTP page and query');
assert.equal(env.calls.some(call => call[0] === 'reload'), false, 'Cancel does not reload');
env.calls[0].events.get('dialogue.submit')({detail: {success: {title: 'Updated'}}});
assert.equal(env.calls.at(-1)[0], 'reload', 'Successful edits refresh native problem data');

const target = {};
env.window.view.editItem(target, {itemid: '8', context: 'host'});
assert.equal(env.calls.at(-1).action, 'item.edit');
assert.equal(env.calls.at(-1).options.trigger_element, target);
env.window.view.editHost(10715);
assert.equal(env.calls.at(-1).action, 'popup.host.edit');
assert.equal(env.calls.at(-1).data.hostid, '10715');

const existing = {editTrigger() {}, otherMethod() {}};
const original = existing.editTrigger;
setup('netapp.monitoring.view', existing);
assert.equal(existing.editTrigger, original, 'Existing native page handlers are preserved');
assert.equal(typeof existing.otherMethod, 'function');
const nativePage = setup('problem.view');
assert.equal(nativePage.window.view, undefined, 'Other Zabbix pages are not modified');
const waiting = setup('netapp.monitoring.view', undefined, true);
assert.equal(waiting.window.view, undefined, 'Waits until inline page scripts have initialized');
waiting.listeners.get('DOMContentLoaded')();
assert.equal(typeof waiting.window.view.editTrigger, 'function');
const linkEnv = setup('netapp.monitoring.list');
const link = {dataset: {}, getAttribute() { return 'zabbix.php?action=netapp.monitoring.view&hostid=12'; },
	dispatchEvent(event) {
		assert.equal(this.dataset.netappContextMenu, '1', 'Context click bypasses navigation guard');
		assert.equal(event.type, 'click');
		assert.equal(event.clientX, 40);
		linkEnv.listeners.get('click')({target: linkTarget, stopImmediatePropagation() { throw new Error('Context click blocked'); }});
	}};
const linkTarget = {closest() { return link; }};
let stopped = false, prevented = false;
linkEnv.listeners.get('click')({target: linkTarget, stopImmediatePropagation() { stopped = true; }, preventDefault() { prevented = true; }});
assert.equal(stopped, true, 'Ordinary click bypasses native menu listener');
assert.equal(prevented, false, 'Ordinary link navigation remains available');
linkEnv.listeners.get('contextmenu')({target: linkTarget, clientX: 40, clientY: 50, preventDefault() { prevented = true; }});
assert.equal(prevented, true, 'Right click opens the native host menu');
assert.equal(link.dataset.netappContextMenu, undefined, 'Temporary context marker is cleared');
linkEnv.listeners.get('keydown')({target: linkTarget, key: 'Enter', preventDefault() {}, stopImmediatePropagation() {}});
assert.equal(linkEnv.calls.at(-1)[0], 'navigate', 'Keyboard Enter opens host details');
console.log('Native menu checks passed: trigger/item/host dialogs, HTTP return URL, save/cancel, native page isolation.');
