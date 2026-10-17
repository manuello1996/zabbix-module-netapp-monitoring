// Run with Node.js: node tests/metrics-toggle.mjs. No browser or network required.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

let click;
const panels = new Map();
const makeResource = id => {
	const panel = {hidden: true};
	panels.set(id, panel);
	const classes = new Set();
	const row = {classList: {toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); }}};
	const button = {attributes: {'aria-controls': id, 'aria-expanded': 'false'},
		getAttribute(name) { return this.attributes[name]; },
		setAttribute(name, value) { this.attributes[name] = value; },
		closest(selector) { return selector === 'tr' ? row : this; }};
	return {button, panel, classes};
};
vm.runInNewContext(readFileSync(new URL('../assets/js/metrics.js', import.meta.url), 'utf8'), {
	document: {addEventListener(type, fn) { assert.equal(type, 'click'); click = fn; },
		getElementById(id) { return panels.get(id); }}
});
const first = makeResource('volume1');
const second = makeResource('volume2');
click({target: first.button});
assert.equal(first.panel.hidden, false);
assert.equal(first.button.getAttribute('aria-expanded'), 'true');
assert.equal(first.classes.has('netapp-monitoring-resource-expanded'), true);
assert.equal(second.panel.hidden, true, 'Opening one volume leaves other volumes collapsed');
click({target: {closest() { return first.button; }}});
assert.equal(first.panel.hidden, true, 'Clicking nested button content closes the panel');
assert.equal(first.button.getAttribute('aria-expanded'), 'false');
assert.equal(first.classes.size, 0);
const replacement = makeResource('volume-after-search');
click({target: replacement.button});
assert.equal(replacement.panel.hidden, false, 'Delegated handler supports newly searched results');
click({target: {closest() { return null; }}});
panels.delete('volume-after-search');
click({target: replacement.button});
console.log('Metrics toggle checks passed: inline control, expand/collapse, isolation, and live search replacement.');
