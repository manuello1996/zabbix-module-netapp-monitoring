// Run with Node.js: node tests/live-search.mjs. No browser or network required.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/js/search.js', import.meta.url), 'utf8');
const listeners = new Map();
const timers = new Map();
const requests = [];
let groupChange;
let timerId = 0;
const status = {textContent: '', dataset: {searching: 'Searching', error: 'Failed'}};
const refresh = {setAttribute(name, value) { this[name] = value; }};
let results;
let summary;
const summaryResult = text => ({text, replaceWith(next) { summary = next; }});
summary = summaryResult('healthy');
const result = text => ({text, attributes: {},
	setAttribute(name, value) { this.attributes[name] = value; },
	getAttribute(name) { return this.attributes[name]; },
	removeAttribute(name) { delete this.attributes[name]; },
	replaceWith(next) { results = next; }
});
results = result('initial');
const form = {values: {action: 'netapp.monitoring.view', hostid: '10715', tab: 'volumes', search: '', sort: 'name', sortorder: 'ASC'},
	querySelector(selector) { return selector === '[name="search"]' ? input : status; }, matches() { return true; }, setAttribute(name, value) { this[name] = value; }};
let focused = false;
const input = {name: 'search', get value() { return form.values.search; }, set value(value) { form.values.search = value; },
	focus() { focused = true; }, closest() { return form; }};
const window = {location: {href: 'http://127.0.0.1/zabbix.php?action=netapp.monitoring.view&page=3'},
	history: {replaceState(_state, _title, url) { window.location.href = url.toString(); }}};
const document = {
	addEventListener(name, callback) { listeners.set(name, callback); },
	getElementById(id) { return id === 'netapp-host-summary' ? summary : results; }, querySelector(selector) { return selector === '[data-netapp-main-filter] form' ? form : refresh; }
};
vm.runInNewContext(source, {document, window, URL, URLSearchParams, AbortController,
	jQuery(target) { assert.equal(target, form); return {on(name, selector, callback) { groupChange = callback; }}; },
	FormData: class { constructor(form) { return Object.entries(form.values); } },
	DOMParser: class { parseFromString(text) { return {getElementById(id) {
		if (text === 'login') return null;
		if (id === 'netapp-host-summary') return text === 'missing summary' ? null : summaryResult('status: ' + text);
		const replacement = result(text);
		if (text === 'counted results') replacement.attributes['data-netapp-search-message'] = '2 of 4 resources match';
		return replacement;
	}}; } },
	setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; },
	clearTimeout(id) { timers.delete(id); },
	fetch(url, options) { return new Promise((resolve, reject) => requests.push({url, options, resolve, reject})); }
});
assert.equal(form['data-netapp-search'], '1', 'Native CFilter form gains live search behavior');
assert.equal(typeof groupChange, 'function', 'Native multiselect jQuery change handler is registered');
function type(value, extra = {}) {
	form.values.search = value;
	listeners.get('input')({target: input, ...extra});
}
function runTimer() {
	assert.equal(timers.size, 1);
	const [id, fn] = timers.entries().next().value;
	timers.delete(id);
	return fn();
}
function respond(request, text, ok = true) { request.resolve({ok, async text() { return text; }}); }

type('v'); type('vol');
assert.equal(requests.length, 0, 'Typing is debounced');
assert.equal(timers.size, 1, 'Rapid typing coalesces into one request');
const first = runTimer();
assert.equal(requests[0].url.searchParams.get('search'), 'vol');
assert.equal(requests[0].url.searchParams.has('page'), false, 'Search resets pagination');
assert.equal(requests[0].url.searchParams.get('tab'), 'volumes');
respond(requests[0], 'volumes filtered');
await first;
assert.equal(results.text, 'volumes filtered');
assert.equal(summary.text, 'status: volumes filtered', 'Host banner, context and counters update with the same snapshot');
assert.equal(status.textContent, '');
assert.equal(refresh['data-url'], window.location.href);
assert.equal(form.values.search, 'vol', 'Input is never replaced');

type('old'); const old = runTimer();
type('new');
assert.equal(requests[1].options.signal.aborted, true, 'New input aborts the old request immediately');
respond(requests[1], 'old stale results'); await old;
assert.equal(results.text, 'volumes filtered', 'Stale reply cannot replace results even during debounce');
assert.equal(summary.text, 'status: volumes filtered', 'Stale reply cannot change the collection banner');
const latest = runTimer(); respond(requests[2], 'new results'); await latest;
assert.equal(results.text, 'new results');

type('broken'); const failed = runTimer(); respond(requests[3], 'login'); await failed;
assert.equal(status.textContent, 'Failed', 'Expired session or missing results is visible');
assert.equal(results.text, 'new results', 'Failed search preserves previous results');
assert.equal(summary.text, 'status: new results', 'Failed search preserves the corresponding host status');
assert.equal(results.attributes['aria-busy'], undefined);

type('', {isComposing: true});
assert.equal(timers.size, 0, 'IME intermediate input does not query');
listeners.get('compositionend')({target: input});
const clear = runTimer(); respond(requests[4], 'all results'); await clear;
assert.equal(requests[4].url.searchParams.get('search'), '', 'Clearing query restores all results');

let prevented = false;
listeners.get('submit')({target: form, preventDefault() { prevented = true; }});
assert.equal(prevented, true, 'Enter searches without navigation');
const enter = runTimer(); respond(requests[5], 'submitted'); await enter;
assert.equal(results.text, 'submitted');
type('outage');
const outage = runTimer(); respond(requests[6], 'HTTP collection failing'); await outage;
assert.equal(summary.text, 'status: HTTP collection failing');
type('recovered');
const recovered = runTimer(); respond(requests[7], 'healthy again'); await recovered;
assert.equal(summary.text, 'status: healthy again', 'Recovery replaces the obsolete outage warning');
type('missing status');
const incomplete = runTimer(); respond(requests[8], 'missing summary'); await incomplete;
assert.equal(results.text, 'healthy again', 'Missing host status prevents a partial result update');
assert.equal(summary.text, 'status: healthy again');
summary = null;
form.values = {action: 'netapp.monitoring.list', tab: 'problems', search: 'Cabinet', 'filter_groupids[]': '25'};
groupChange();
const groupSearch = runTimer(); respond(requests[9], 'group filtered problems'); await groupSearch;
assert.equal(requests[9].url.searchParams.get('filter_groupids[]'), '25', 'Group multiselect values included');
assert.equal(requests[9].url.searchParams.get('tab'), 'problems', 'Group changes preserve active main tab');
assert.equal(requests[9].url.searchParams.has('page'), false, 'Group change resets pagination');
form.values = {action: 'netapp.monitoring.view', hostid: '10715', tab: 'volumes', search: 'latency'};
listeners.get('click')({target: {closest() { return {closest() { return form; }}; }}, preventDefault() {}});
assert.equal(input.value, '', 'Clear button empties the query');
assert.equal(focused, true, 'Clear button returns focus to the input');
const cleared = runTimer(); respond(requests[10], 'all resources'); await cleared;
assert.equal(requests[10].url.searchParams.get('search'), '', 'Clear updates results without navigating');
input.value = 'latency';
listeners.get('keydown')({key: 'Escape', target: input, preventDefault() {}});
assert.equal(input.value, '', 'Escape clears the active query');
const escaped = runTimer(); respond(requests[11], 'all resources'); await escaped;
listeners.get('keydown')({key: 'Escape', target: input, preventDefault() { assert.fail('Empty Escape should do nothing'); }});
assert.equal(timers.size, 0, 'Empty Escape does not request data');
input.value = 'composing';
listeners.get('keydown')({key: 'Escape', isComposing: true, target: input});
assert.equal(input.value, 'composing', 'Escape preserves IME composition');
window.location.href += '#old-resource';
type('count'); const counted = runTimer(); respond(requests[12], 'counted results'); await counted;
assert.equal(status.textContent, '2 of 4 resources match', 'Matching resource count is announced');
assert.equal(requests[12].url.hash, '', 'New search clears an obsolete resource anchor');
console.log('Live search checks passed: debounce, pagination, focus preservation, request races, failure, IME, clear, Enter.');
