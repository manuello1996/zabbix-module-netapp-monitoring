/* Search the complete server-side dataset without replacing the active input. */
(() => {
	let timer;
	let controller;
	let generation = 0;
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
		clearTimeout(timer);
		controller?.abort();
		const current = ++generation;
		const status = form.querySelector('[data-netapp-search-status]');
		const results = document.getElementById('netapp-search-results');
		if (!results) return;
		results.setAttribute('aria-busy', 'true');
		if (status) status.textContent = status.dataset.searching;
		timer = setTimeout(async () => {
			controller = new AbortController();
			const url = new URL(window.location.href);
			url.search = new URLSearchParams(new FormData(form)).toString();
			url.searchParams.delete('page');
			try {
				const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
				if (!response.ok) throw new Error('Search request failed');
				const page = new DOMParser().parseFromString(await response.text(), 'text/html');
				const replacement = page.getElementById('netapp-search-results');
				if (!replacement) throw new Error('Search results unavailable');
				if (current !== generation) return;
				replacement.removeAttribute('aria-busy');
				results.replaceWith(replacement);
				window.history.replaceState(null, '', url);
				document.querySelector('[data-netapp-refresh]')?.setAttribute('data-url', url.toString());
				if (status) status.textContent = '';
			}
			catch (error) {
				if (current !== generation || error.name === 'AbortError') return;
				results.removeAttribute('aria-busy');
				if (status) status.textContent = status.dataset.error;
			}
		}, immediate ? 0 : 250);
	};
	document.addEventListener('input', event => {
		if (event.target.name !== 'search' || event.isComposing) return;
		const form = event.target.closest('form[data-netapp-search]');
		if (form) schedule(form);
	});
	document.addEventListener('compositionstart', event => {
		if (!event.target.closest('form[data-netapp-search]')) return;
		clearTimeout(timer);
		controller?.abort();
		generation++;
	});
	document.addEventListener('compositionend', event => {
		const form = event.target.closest('form[data-netapp-search]');
		if (form) schedule(form);
	});
	document.addEventListener('submit', event => {
		if (!event.target.matches('form[data-netapp-search]')) return;
		event.preventDefault();
		schedule(event.target, true);
	});
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', prepareFilter, {once: true});
	}
	else prepareFilter();
})();
