/* Native Zabbix menus expect edit handlers on the page's global view object. */
(() => {
	const selector = '[data-netapp-host-link]';
	// Keep the ordinary host link and use Zabbix's menu only on right click.
	document.addEventListener('click', event => {
		const link = event.target.closest(selector);
		if (link && link.dataset.netappContextMenu !== '1') event.stopImmediatePropagation();
	}, true);
	document.addEventListener('keydown', event => {
		const link = event.target.closest(selector);
		if (!link || event.key !== 'Enter') return;
		event.preventDefault();
		event.stopImmediatePropagation();
		window.location.assign(link.getAttribute('href'));
	}, true);
	document.addEventListener('contextmenu', event => {
		const link = event.target.closest(selector);
		if (!link) return;
		event.preventDefault();
		link.dataset.netappContextMenu = '1';
		try {
			link.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true,
				view: window, clientX: event.clientX, clientY: event.clientY}));
		}
		finally { delete link.dataset.netappContextMenu; }
	}, true);
	const install = () => {
		const action = new URL(window.location.href).searchParams.get('action');
		if (!['netapp.monitoring.list', 'netapp.monitoring.view'].includes(action)) return;
		const pageView = typeof view === 'object' && view !== null ? view : (window.view ??= {});
		const open = (action, data, dialogueid, target) => {
			const originalUrl = window.location.href;
			if (typeof clearMessages === 'function') clearMessages();
			const overlay = PopUp(action, data, {
				dialogueid, dialogue_class: 'modal-popup-large',
				trigger_element: target, prevent_navigation: true
			});
			const dialogue = overlay?.$dialogue?.[0];
			if (!dialogue) return;
			const restoreUrl = () => window.history.replaceState(null, '', originalUrl);
			dialogue.addEventListener('dialogue.close', restoreUrl, {once: true});
			dialogue.addEventListener('dialogue.submit', event => {
				restoreUrl();
				const success = event.detail?.success;
				if (success && typeof postMessageOk === 'function') {
					postMessageOk(success.title);
					if (success.messages && typeof postMessageDetails === 'function') {
						postMessageDetails('success', success.messages);
					}
				}
				window.location.reload();
			}, {once: true});
		};
		if (typeof pageView.editTrigger !== 'function') {
			pageView.editTrigger = data => open('trigger.edit', data, 'trigger-edit');
		}
		if (typeof pageView.editItem !== 'function') {
			pageView.editItem = (target, data) => open('item.edit', data, 'item-edit', target);
		}
		if (typeof pageView.editHost !== 'function') {
			pageView.editHost = hostid => open('popup.host.edit', {hostid: String(hostid)}, 'host_edit');
		}
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', install, {once: true});
	}
	else {
		install();
	}
})();
