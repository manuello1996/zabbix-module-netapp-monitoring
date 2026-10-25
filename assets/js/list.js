/* VMware's lightweight page + permission-checked data endpoint pattern. */
(() => {
	const start = () => {
		const results = document.querySelector('[data-netapp-list]');
		const form = document.querySelector('[data-netapp-list-filter] form');
		const summary = document.getElementById('netapp-list-summary');
		if (!results || !form || !summary) return;
		const emptySummary = summary.innerHTML;
		const status = form.querySelector('[data-netapp-search-status]');
		const input = form.querySelector('[name="search"]');
		const tabs = [...document.querySelectorAll('[data-netapp-list-tab]')];
		const messages = {...results.dataset};
		const recent = new Map();
		let controller;
		let timer;
		let generation = 0;
		let hasData = false;
		let restoring = false;
		let loading = false;
		const autoRefresh = new window.NetAppCommon.AutoRefresh({
			control: document.getElementById('netapp-monitoring-auto-refresh'),
			status: document.getElementById('netapp-monitoring-refresh-status'),
			isBusy: () => loading,
			onRefresh: () => load(window.location.href, {historyMode: null, refresh: true})
		});
		const normalize = value => {
			const url = new URL(value, window.location.href);
			url.searchParams.set('action', 'netapp.monitoring.list');
			['filter_set', 'filter_rst'].forEach(name => url.searchParams.delete(name));
			url.hash = '';
			url.searchParams.sort();
			return url;
		};
		const sync = url => {
			const tab = url.searchParams.get('tab') || 'hosts';
			input.value = url.searchParams.get('search') || '';
			for (const name of ['tab', 'sort', 'sortorder']) {
				const field = form.querySelector(`[name="${name}"]`);
				if (field) field.value = url.searchParams.get(name) || (name === 'tab' ? 'hosts' : name === 'sort' ? 'name' : 'ASC');
			}
			for (const link of tabs) {
				const active = link.dataset.netappListTab === tab;
				link.classList.toggle('netapp-monitoring-tab-active', active);
				link.setAttribute('aria-current', active ? 'page' : 'false');
				const target = new URL(url);
				target.searchParams.set('tab', link.dataset.netappListTab);
				target.searchParams.delete('page');
				link.href = target.href;
			}
			document.querySelector('[data-netapp-refresh]')?.setAttribute('data-url', url.href);
		};
		const load = async (value, {historyMode = 'push', refresh = false, restoreGroups = false} = {}) => {
			autoRefresh.pause();
			loading = true;
			clearTimeout(timer);
			controller?.abort();
			const current = ++generation;
			const url = normalize(value);
			if (refresh) recent.clear();
			if (historyMode === 'push' && url.href !== window.location.href) window.history.pushState(null, '', url);
			else if (historyMode === 'replace') window.history.replaceState(null, '', url);
			sync(url);
			results.setAttribute('aria-busy', 'true');
			summary.setAttribute('aria-busy', 'true');
			if (status) status.textContent = messages.loading;
			autoRefresh.setStatus(messages.loading);
			const keepContent = refresh && hasData;
			if (!keepContent) {
				hasData = false;
				results.textContent = messages.loading;
				summary.innerHTML = emptySummary;
			}
			const request = new AbortController();
			controller = request;
			try {
				let data = recent.get(url.href);
				if (!data || Date.now() - data.loadedAt >= autoRefresh.cacheLifetime) {
					const target = new URL(url);
					target.searchParams.set('action', 'netapp.monitoring.list.data');
					const response = await fetch(target, {cache: 'no-store', credentials: 'same-origin', signal: request.signal});
					if (!response.ok) throw new Error('Monitoring request failed');
					data = await response.json();
					if (typeof data.html !== 'string' || typeof data.summary !== 'string' || !Array.isArray(data.groups)) {
						throw new Error('Monitoring data unavailable');
					}
					data.loadedAt = Date.now();
				}
				if (current !== generation || request.signal.aborted) return;
				const replacement = new DOMParser().parseFromString(data.html, 'text/html').getElementById('netapp-search-results');
				if (!replacement) throw new Error('Monitoring content unavailable');
				results.innerHTML = replacement.innerHTML;
				summary.innerHTML = data.summary;
				hasData = true;
				if (data.html.length + data.summary.length <= 1048576) {
					recent.delete(url.href);
					recent.set(url.href, data);
					while (recent.size > 12) recent.delete(recent.keys().next().value);
				}
				if (restoreGroups && typeof jQuery === 'function') {
					restoring = true;
					try {
						const select = jQuery('#filter_groupids_');
						select.multiSelect('clean');
						data.groups.forEach(group => select.multiSelect('addData', {id: group.groupid, name: group.name}));
					}
					finally { restoring = false; }
				}
				if (status) status.textContent = '';
				autoRefresh.updated(data.loadedAt);
				document.dispatchEvent(new CustomEvent('zbx_reload', {detail: {source: 'netapp-monitoring-list'}}));
			}
			catch (error) {
				if (current !== generation || error.name === 'AbortError') return;
				if (status) status.textContent = keepContent ? messages.refreshError : messages.loadError;
				autoRefresh.setStatus(keepContent ? messages.refreshError : messages.loadError);
				if (!keepContent) results.textContent = messages.loadError;
			}
			finally {
				if (current === generation) {
					results.setAttribute('aria-busy', 'false');
					summary.setAttribute('aria-busy', 'false');
					loading = false;
					autoRefresh.resume();
				}
			}
		};
		const search = (immediate = false) => {
			if (restoring) return;
			autoRefresh.pause();
			clearTimeout(timer);
			controller?.abort();
			generation++;
			const url = new URL(window.location.href);
			url.search = new URLSearchParams(new FormData(form)).toString();
			url.searchParams.delete('page');
			timer = setTimeout(() => load(url, {historyMode: 'replace'}), immediate ? 0 : 80);
		};
		form.addEventListener('input', event => {
			if (event.target === input && !event.isComposing) search();
		});
		form.addEventListener('compositionstart', () => {
			autoRefresh.pause();
			clearTimeout(timer);
			controller?.abort();
			generation++;
		});
		form.addEventListener('compositionend', () => search());
		form.addEventListener('submit', event => { event.preventDefault(); search(true); });
		if (typeof jQuery === 'function') {
			jQuery(form).on('change', '[name="filter_groupids[]"], .multiselect', () => search());
		}
		document.addEventListener('click', event => {
			if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
			const refresh = event.target.closest('[data-netapp-refresh]');
			if (refresh) {
				event.preventDefault();
				event.stopImmediatePropagation();
				load(window.location.href, {historyMode: null, refresh: true});
				return;
			}
			const link = event.target.closest('a[href]');
			if (!link || (!results.contains(link) && !link.matches('[data-netapp-list-tab]'))) return;
			const target = new URL(link.href, window.location.href);
			if (target.origin !== window.location.origin || target.searchParams.get('action') !== 'netapp.monitoring.list') return;
			event.preventDefault();
			load(target);
		}, true);
		window.addEventListener('popstate', () => load(window.location.href, {historyMode: null, restoreGroups: true}));
		load(window.location.href, {historyMode: 'replace'});
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
	else start();
})();
