// Run: node tests/list-loading.mjs. No network or browser required.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/js/list.js', import.meta.url), 'utf8');
const listeners = new Map();
const formListeners = new Map();
const windowListeners = new Map();
const requests = [];
const timers = new Map();
const restored = [];
let now = 1000;
let groupChange;
let serial = 0;
let html = '';
let text = 'Loading';
const results = {
	dataset: {loading: 'Loading', loadError: 'Failed; retry', refreshError: 'Refresh failed; retained'},
	attributes: {},
	setAttribute(name, value) { this.attributes[name] = value; },
	contains(link) { return link.inResults === true; },
	get innerHTML() { return html; }, set innerHTML(value) { html = value; text = ''; },
	get textContent() { return text; }, set textContent(value) { text = value; html = ''; }
};
const status = {textContent: ''};
const summary = {innerHTML: 'counter placeholders', attributes: {},
	setAttribute(name, value) { this.attributes[name] = value; }};
const fields = Object.fromEntries(Object.entries({search: 'prod', tab: 'hosts', sort: 'name', sortorder: 'DESC'})
	.map(([name, value]) => [name, {value}]));
const form = {
	querySelector(selector) {
		if (selector === '[data-netapp-search-status]') return status;
		return fields[selector.match(/name="(.*?)"/)[1]];
	},
	addEventListener(name, callback) { formListeners.set(name, callback); }
};
const tabs = ['hosts', 'problems'].map(tab => ({
	dataset: {netappListTab: tab}, attributes: {}, href: '',
	classList: {toggle() {}}, setAttribute(name, value) { this.attributes[name] = value; },
	matches(selector) { return selector === '[data-netapp-list-tab]'; }
}));
const refresh = {setAttribute() {}};
const window = {
	location: new URL('http://localhost/zabbix.php?action=netapp.monitoring.list&filter_groupids%5B%5D=25&search=prod&page=2&sortorder=DESC'),
	history: {
		pushState(_state, _title, url) { window.location = new URL(url); },
		replaceState(_state, _title, url) { window.location = new URL(url); }
	},
	addEventListener(name, callback) { windowListeners.set(name, callback); }
};
const document = {
	readyState: 'complete',
	querySelector(selector) {
		if (selector === '[data-netapp-list]') return results;
		if (selector === '[data-netapp-list-filter] form') return form;
		return refresh;
	},
	querySelectorAll() { return tabs; },
	getElementById(id) { return id === 'netapp-list-summary' ? summary : null; },
	addEventListener(name, callback) { listeners.set(name, callback); },
	dispatchEvent() {}
};
vm.runInNewContext(source, {
	document, window, URL, URLSearchParams, AbortController,
	Date: class extends Date { static now() { return now; } },
	CustomEvent: class {},
	FormData: class { constructor() {
		return [...Object.entries(fields).map(([name, field]) => [name, field.value]),
			['action', 'netapp.monitoring.list'], ['filter_groupids[]', '25']];
	} },
	DOMParser: class { parseFromString(value) { return {getElementById() { return value === 'login' ? null : {innerHTML: value}; }}; } },
	jQuery(target) { return {
		on(_event, _selector, callback) { assert.equal(target, form); groupChange = callback; },
		multiSelect(...args) { restored.push(args); }
	}; },
	fetch(url, options) { return new Promise(resolve => requests.push({url, options, resolve})); },
	setTimeout(fn) { const id = ++serial; timers.set(id, fn); return id; },
	clearTimeout(id) { timers.delete(id); }
});
const flush = () => new Promise(setImmediate);
async function respond(index, value, ok = true) {
	requests[index].resolve({ok, async json() { return {html: value, summary: 'counts: ' + value,
		groups: [{groupid: '25', name: 'Storage'}]}; }});
	await flush();
}
function click(link, extra = {}) {
	let prevented = false;
	listeners.get('click')({button: 0, target: {closest(selector) {
		return selector === '[data-netapp-refresh]' ? (link === refresh ? refresh : null) : link;
	}}, preventDefault() { prevented = true; }, stopImmediatePropagation() {}, ...extra});
	return prevented;
}
function search(value) {
	fields.search.value = value;
	formListeners.get('input')({target: fields.search});
}
function runTimer() {
	assert.equal(timers.size, 1);
	const [id, fn] = timers.entries().next().value;
	timers.delete(id);
	return fn();
}

assert.equal(requests.length, 1, 'Initial page starts one deferred request');
assert.equal(requests[0].url.searchParams.get('action'), 'netapp.monitoring.list.data');
assert.equal(requests[0].url.searchParams.get('filter_groupids[]'), '25');
assert.equal(requests[0].url.searchParams.get('page'), '2');
assert.equal(results.attributes['aria-busy'], 'true');
assert.equal(summary.attributes['aria-busy'], 'true', 'Counters announce the deferred request');
await respond(0, 'initial hosts');
assert.equal(results.innerHTML, 'initial hosts');
assert.equal(summary.innerHTML, 'counts: initial hosts', 'Counters load with the matching table');
assert.equal(summary.attributes['aria-busy'], 'false');
assert.equal(results.attributes['aria-busy'], 'false');
assert.equal(new URL(tabs[1].href).searchParams.has('page'), false, 'Tabs reset pagination');
assert.equal(new URL(tabs[1].href).searchParams.get('search'), 'prod', 'Tabs retain filters');
assert.equal(click(tabs[1]), true);
await respond(1, 'problems');
assert.equal(fields.tab.value, 'problems', 'Native filter tracks the active tab');
assert.equal(click(tabs[0]), true);
await respond(2, 'first-page hosts');
click(tabs[1]);
await flush();
assert.equal(requests.length, 3, 'Returning to a recently loaded tab reuses its response');
assert.equal(results.innerHTML, 'problems');
assert.equal(summary.innerHTML, 'counts: problems', 'Cached tabs restore their matching counters');

click(refresh);
assert.equal(requests.length, 4, 'Refresh bypasses and clears the cache');
assert.equal(results.innerHTML, 'problems', 'Refresh retains the last table while loading');
assert.equal(summary.innerHTML, 'counts: problems', 'Refresh retains the last counters while loading');
await respond(3, '', false);
assert.equal(results.innerHTML, 'problems', 'Failed refresh keeps the last table');
assert.equal(summary.innerHTML, 'counts: problems', 'Failed refresh keeps the last counters');
assert.equal(summary.attributes['aria-busy'], 'false');
assert.equal(status.textContent, 'Refresh failed; retained');
search('old');
const old = runTimer();
search('new');
assert.equal(requests[4].options.signal.aborted, true, 'Typing cancels the previous request before debounce');
await respond(4, 'obsolete response');
await old;
assert.notEqual(results.innerHTML, 'obsolete response');
assert.notEqual(summary.innerHTML, 'counts: obsolete response', 'An aborted request cannot overwrite counters');
const current = runTimer();
assert.equal(requests[5].url.searchParams.has('page'), false, 'Search resets pagination');
assert.equal(requests[5].url.searchParams.get('tab'), 'problems');
assert.equal(requests[5].url.searchParams.get('search'), 'new');
await respond(5, 'new problems');
await current;
assert.equal(results.innerHTML, 'new problems');
assert.equal(summary.innerHTML, 'counts: new problems', 'Search updates counters and table together');
assert.equal(fields.search.value, 'new', 'The focused native input stays in place');
assert.equal(new URL(window.location).searchParams.get('action'), 'netapp.monitoring.list', 'History remains reloadable');

const page = new URL(window.location);
page.searchParams.set('page', '2');
const next = {href: page.href, inResults: true, matches() { return false; }};
assert.equal(click(next, {ctrlKey: true}), false, 'Modified clicks retain browser navigation');
assert.equal(click(next), true, 'Native paging links load asynchronously');
await respond(6, 'second problem page');
window.location.searchParams.delete('page');
windowListeners.get('popstate')();
await flush();
assert.equal(results.innerHTML, 'new problems', 'Back restores the cached page');
assert.equal(summary.innerHTML, 'counts: new problems', 'Back restores the matching counters');
assert.equal(restored[0][0], 'clean', 'Back restores the native group selector');
assert.equal(restored[1][1].id, '25');

now += 60001;
windowListeners.get('popstate')();
assert.equal(requests.length, 8, 'Expired cache entries fetch current data');
await respond(7, 'login');
assert.equal(results.textContent, 'Failed; retry', 'Unexpected login HTML cannot replace monitoring data');
assert.equal(summary.innerHTML, 'counter placeholders', 'Failed navigation cannot display counters for the previous scope');
click(refresh);
await respond(8, 'recovered');
assert.equal(results.innerHTML, 'recovered', 'Refresh retries a failed initial load');
groupChange();
assert.equal(timers.size, 1, 'Native multiselect changes schedule a new request');
formListeners.get('compositionstart')();
assert.equal(timers.size, 0, 'IME composition cancels pending searches');
formListeners.get('input')({target: fields.search, isComposing: true});
assert.equal(timers.size, 0, 'IME input does not send incomplete queries');
fields.search.value = 'composed';
formListeners.get('compositionend')();
const composed = runTimer();
await respond(9, 'composed');
await composed;
vm.runInNewContext(source, {document: {readyState: 'complete', querySelector() { return null; }, getElementById() { return null; }}});
console.log('Deferred list checks passed: loading, filters, tabs, native paging, history, cache, refresh, errors, request races, IME, isolation.');
