/* Search the complete server-side dataset without replacing the active input. */
(() => {
	let controller;
	let generation = 0;
	const recent = new Map();
	const loadedAt = Date.now();
	const cacheLifetime = 5000;
	let seeded = false;
	const remember = (key, html, expires = Date.now() + cacheLifetime) => {
		if (html.length > 1048576) return;
		recent.delete(key);
		recent.set(key, {html, expires});
		while (recent.size > 8) recent.delete(recent.keys().next().value);
	};
	const prepareFilter = () => {
		const form = document.querySelector('[data-netapp-main-filter] form');
		if (!form) return;
		form.setAttribute('data-netapp-search', '1');
		// Zabbix's native multiselect emits a jQuery change event.
		if (typeof jQuery === 'function') {
			jQuery(form).on('change', '[name="filter_groupids[]"], .multiselect', () => schedule(form));
		}
	};
	const schedule = (form, immediate = false) => {
		searchInput.cancel();
		controller?.abort();
		const current = ++generation;
		const status = form.querySelector('[data-netapp-search-status]');
		const results = document.getElementById('netapp-search-results');
		if (!results) return;
		const url = new URL(window.location.href);
		url.search = new URLSearchParams(new FormData(form)).toString();
		url.searchParams.delete('page');
		url.hash = '';
		const key = url.toString();
		if (!seeded) {
			seeded = true;
			const previous = new URL(window.location.href);
			const summary = document.getElementById('netapp-host-summary');
			if (previous.searchParams.get('action') === 'netapp.monitoring.view'
					&& !previous.searchParams.has('focus') && Number(previous.searchParams.get('page') || 1) === 1
					&& typeof results.outerHTML === 'string' && typeof summary?.outerHTML === 'string') {
				const original = new URL(url);
				original.searchParams.set('search', previous.searchParams.get('search') || '');
				remember(original.toString(), summary.outerHTML + results.outerHTML, loadedAt + cacheLifetime);
			}
		}
		const apply = html => {
			const page = new DOMParser().parseFromString(html, 'text/html');
			const replacement = page.getElementById('netapp-search-results');
			if (!replacement) throw new Error('Search results unavailable');
			const summary = document.getElementById('netapp-host-summary');
			const updatedSummary = summary ? page.getElementById('netapp-host-summary') : null;
			if (summary && !updatedSummary) throw new Error('Host status unavailable');
			if (current !== generation) return;
			replacement.removeAttribute('aria-busy');
			results.replaceWith(replacement);
			if (summary) summary.replaceWith(updatedSummary);
			window.history.replaceState(null, '', url);
			document.querySelector('[data-netapp-refresh]')?.setAttribute('data-url', url.toString());
			if (status) status.textContent = replacement.getAttribute('data-netapp-search-message') || '';
		};
		const cached = recent.get(key);
		if (cached && cached.expires > Date.now()) {
			apply(cached.html);
			return;
		}
		results.setAttribute('aria-busy', 'true');
		if (status) status.textContent = status.dataset.searching;
		searchInput.queue(async () => {
			controller = new AbortController();
			try {
				const requestUrl = new URL(url);
				const fragment = requestUrl.searchParams.get('action') === 'netapp.monitoring.view';
				if (fragment) requestUrl.searchParams.set('action', 'netapp.monitoring.search');
				const response = await fetch(requestUrl, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store'});
				if (!response.ok) throw new Error('Search request failed');
				const html = fragment ? (await response.json()).html : await response.text();
				if (typeof html !== 'string') throw new Error('Search response unavailable');
				if (current !== generation) return;
				apply(html);
				remember(key, html);
			}
			catch (error) {
				if (current !== generation || error.name === 'AbortError') return;
				results.removeAttribute('aria-busy');
				if (status) status.textContent = status.dataset.error;
			}
		}, immediate);
	};
	const searchInput = new window.NetAppCommon.SearchInput(document, {
		selector: '[name="search"]', formSelector: 'form[data-netapp-search]',
		clearSelector: '[data-netapp-search-clear]', delay: 80,
		onChange: (input, immediate) => schedule(input.closest('form[data-netapp-search]'), immediate),
		onCancel: () => { controller?.abort(); generation++; }
	});
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', prepareFilter, {once: true});
	}
	else prepareFilter();
})();
