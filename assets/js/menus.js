/* NetApp host navigation; native item and editing behavior comes from common. */
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
})();
