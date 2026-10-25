/* Remember location collapse state for this signed-in user and browser tab. */
(() => {
	const states = new Map();
	const keyFor = scope => `netapp-monitoring.locations.${scope.dataset.user || 'anonymous'}`;
	const load = scope => {
		const key = keyFor(scope);
		if (!states.has(key)) {
			let values = [];
			try {
				const stored = JSON.parse(sessionStorage.getItem(key) || '[]');
				if (Array.isArray(stored)) values = stored.filter(value => typeof value === 'string');
			}
			catch (_) { /* Browser storage may be disabled. In-memory state still works. */ }
			states.set(key, new Set(values));
		}
		return states.get(key);
	};
	const apply = scope => window.NetAppCommon.LocationGroups.apply(scope, load(scope));
	const initialize = () => document.querySelectorAll('[data-netapp-locations]').forEach(apply);
	document.addEventListener('click', event => {
		const button = event.target.closest('[data-location-toggle]');
		const scope = button?.closest('[data-netapp-locations]');
		if (!scope) return;
		event.preventDefault();
		const collapsed = load(scope);
		const key = button.dataset.locationToggle;
		if (collapsed.has(key)) collapsed.delete(key);
		else collapsed.add(key);
		try { sessionStorage.setItem(keyFor(scope), JSON.stringify([...collapsed])); }
		catch (_) { /* Preserve the interaction when storage is unavailable. */ }
		apply(scope);
	});
	const start = () => {
		initialize();
		// Search replaces the results container. Observe inserted nodes only so
		// applying hidden/aria-expanded attributes cannot trigger an observer loop.
		new MutationObserver(records => {
			if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1))) initialize();
		}).observe(document.body, {childList: true, subtree: true});
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
	else start();
})();
