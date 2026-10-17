/* Delegate so controls also work after live search replaces the results. */
document.addEventListener('click', event => {
	const button = event.target.closest?.('[data-netapp-metrics-toggle]');
	if (!button) return;
	const panel = document.getElementById(button.getAttribute('aria-controls'));
	if (!panel) return;
	const expanded = button.getAttribute('aria-expanded') !== 'true';
	button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
	panel.hidden = !expanded;
	button.closest('tr')?.classList.toggle('netapp-monitoring-resource-expanded', expanded);
});
