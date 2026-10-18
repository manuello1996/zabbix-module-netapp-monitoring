/* Resource links resolve their page on the server, including duplicate names. */
(() => {
	const showResource = () => {
		const row = document.querySelector('[data-netapp-resource-focus="1"]');
		if (!row) return;
		row.classList.add('netapp-monitoring-resource-highlight');
		row.scrollIntoView({block: 'center', behavior: 'auto'});
		setTimeout(() => row.classList.remove('netapp-monitoring-resource-highlight'), 5000);
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showResource, {once: true});
	else showResource();
})();
