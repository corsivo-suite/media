(function () {
	'use strict';
	document.querySelectorAll('[data-corsivo-workspace]').forEach(function (workspace) {
		const panels = Array.from(workspace.querySelectorAll('[data-corsivo-section]'));
		const links = Array.from(workspace.querySelectorAll('[data-corsivo-section-link]'));
		if (!panels.length || !links.length) return;
		const form = workspace.querySelector('form');
		let submitting = false;
		let revealingInvalid = false;
		const snapshot = function () {
			return form ? JSON.stringify(Array.from(new FormData(form).entries()).filter(function (entry) {
				return entry[0] !== '_wp_http_referer';
			})) : '';
		};
		const initial = snapshot();
		const select = function (id, updateHistory) {
			const selected = panels.find(function (panel) { return panel.dataset.corsivoSection === id; }) || panels[0];
			panels.forEach(function (panel) { panel.hidden = panel !== selected; });
			links.forEach(function (link) {
				if (link.hash === '#' + selected.dataset.corsivoSection) link.setAttribute('aria-current', 'page');
				else link.removeAttribute('aria-current');
			});
			if (updateHistory) window.history.pushState(null, '', '#' + selected.dataset.corsivoSection);
			const referer = form && form.querySelector('[name="_wp_http_referer"]');
			if (referer) referer.value = window.location.pathname + window.location.search + '#' + selected.dataset.corsivoSection;
		};
		links.forEach(function (link) {
			link.addEventListener('click', function (event) {
				if (event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
				event.preventDefault();
				select(link.hash.slice(1), true);
			});
		});
		window.addEventListener('hashchange', function () { select(window.location.hash.slice(1), false); });
		window.addEventListener('beforeunload', function (event) {
			if (!submitting && snapshot() !== initial) {
				event.preventDefault();
				event.returnValue = '';
			}
		});
		if (form) {
			form.addEventListener('submit', function () { submitting = true; });
			form.addEventListener('invalid', function (event) {
				if (revealingInvalid) return;
				revealingInvalid = true;
				window.setTimeout(function () { revealingInvalid = false; }, 0);
				const panel = event.target.closest('[data-corsivo-section]');
				if (panel && panel.hidden) select(panel.dataset.corsivoSection, true);
			}, true);
		}
		select(window.location.hash.slice(1), false);
	});
})();
