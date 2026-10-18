import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const script = readFileSync(new URL('../assets/js/resources.js', import.meta.url), 'utf8');
for (const readyState of ['loading', 'complete']) {
	const classes = new Set();
	let ready, timeout, delay, scrolled;
	const row = {classList: {add: name => classes.add(name), remove: name => classes.delete(name)},
		scrollIntoView: options => { scrolled = options; }};
	vm.runInNewContext(script, {
		document: {readyState,
			querySelector: selector => { assert.equal(selector, '[data-netapp-resource-focus="1"]'); return row; },
			addEventListener: (event, handler) => { assert.equal(event, 'DOMContentLoaded'); ready = handler; }},
		setTimeout: (handler, ms) => { timeout = handler; delay = ms; }
	});
	if (readyState === 'loading') {
		assert.equal(classes.size, 0, 'Wait for the resource row to exist');
		ready();
	}
	assert.ok(classes.has('netapp-monitoring-resource-highlight'));
	assert.equal(scrolled.block, 'center');
	assert.equal(scrolled.behavior, 'auto', 'No forced smooth motion');
	assert.equal(delay, 5000);
	timeout();
	assert.equal(classes.size, 0, 'Highlight clears after five seconds');
}
vm.runInNewContext(script, {document: {readyState: 'complete', querySelector: () => null},
	setTimeout: () => assert.fail('No timer without a target')});
console.log('Resource focus checks passed: readiness, scrolling, temporary highlight, and missing target.');
