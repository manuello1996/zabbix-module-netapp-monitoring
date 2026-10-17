import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/js/problems.js', import.meta.url), 'utf8');
let click;
const classes = initial => {
	const values = new Set(initial);
	return {contains: name => values.has(name), toggle(name, enabled) {
		if (enabled) values.add(name); else values.delete(name);
	}};
};
const children = ['77', '77', '88'].map(causeEventid => ({
	dataset: {causeEventid}, classList: classes(['hidden'])
}));
const table = {querySelectorAll: () => children};
const button = {dataset: {eventid: '77'}, classList: classes(['collapsed', 'down']),
	closest: selector => selector === 'table' ? table : {},
	setAttribute(name, value) { this[name] = value; }};
vm.runInNewContext(source, {
	document: {addEventListener(name, handler) { assert.equal(name, 'click'); click = handler; }},
	ZBX_STYLE_COLLAPSED: 'collapsed', ZBX_ICON_CHEVRON_DOWN: 'down', ZBX_ICON_CHEVRON_UP: 'up',
	t: text => text
});
let prevented = false;
const activate = () => click({target: {closest: () => button}, preventDefault() { prevented = true; }});
activate();
assert.equal(prevented, true);
assert.equal(button['aria-expanded'], 'true');
assert.equal(button.title, 'Collapse');
assert.equal(button.classList.contains('up'), true);
assert.equal(children[0].classList.contains('hidden'), false);
assert.equal(children[1].classList.contains('hidden'), false);
assert.equal(children[2].classList.contains('hidden'), true, 'Other causes stay closed');
activate();
assert.equal(button['aria-expanded'], 'false');
assert.equal(button.title, 'Expand');
assert.equal(children[0].classList.contains('hidden'), true);
button.closest = () => null;
prevented = false;
activate();
assert.equal(prevented, false, 'Native dashboards outside this module are untouched');
console.log('Problem symptom toggle checks passed: expand/collapse, scoped rows, accessibility, dashboard isolation.');
