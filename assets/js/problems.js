/* Native Problems rows need their dashboard expand handler on module pages too. */
document.addEventListener('click', event => {
	const button = event.target.closest?.('button[data-action="show_symptoms"]');
	if (!button?.closest('[data-netapp-problems]')) return;
	const table = button.closest('table');
	if (!table) return;
	const rows = [...table.querySelectorAll('tr[data-cause-eventid]')]
		.filter(row => row.dataset.causeEventid === button.dataset.eventid);
	if (!rows.length) return;
	event.preventDefault();
	const expanded = rows.some(row => row.classList.contains('hidden'));
	rows.forEach(row => row.classList.toggle('hidden', !expanded));
	button.classList.toggle(ZBX_STYLE_COLLAPSED, !expanded);
	button.classList.toggle(ZBX_ICON_CHEVRON_DOWN, !expanded);
	button.classList.toggle(ZBX_ICON_CHEVRON_UP, expanded);
	button.title = expanded ? t('Collapse') : t('Expand');
	button.setAttribute('aria-expanded', String(expanded));
});
