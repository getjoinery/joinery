/*
 * The analytics admin pages (adm/admin_analytics_*.php):
 *   - #sqlbtn shows and hides the query behind a report (#sql);
 *   - a <canvas data-jy-chart="block-id"> draws the Chart.js chart whose
 *     type, labels, datasets and options are that JSON block;
 *   - on the funnels page, #funnel-step-options ({page: {...}, event: {...}})
 *     fills each step's value list when its type changes.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	function start() {
		var sqlElement = document.getElementById('sql');
		var sqlBtn = document.getElementById('sqlbtn');
		if (sqlBtn && sqlElement) {
			sqlElement.style.display = 'none';
			sqlBtn.addEventListener('click', function (e) {
				e.preventDefault();
				sqlElement.style.display = sqlElement.style.display === 'none' ? '' : 'none';
			});
		}

		document.querySelectorAll('canvas[data-jy-chart]').forEach(function (canvas) {
			var cfg = joineryPageData(canvas.getAttribute('data-jy-chart'));
			if (!cfg || !window.Chart) return;
			new Chart(canvas.getContext('2d'), {
				type: cfg.type || 'line',
				data: { labels: cfg.labels || [], datasets: cfg.datasets || [] },
				options: cfg.options || {}
			});
		});

		var steps = joineryPageData('funnel-step-options');
		if (steps) {
			for (var n = 1; n <= 5; n++) {
				(function (n) {
					var typeSel = document.querySelector('[name="step_' + n + '_type"]');
					var valSel = document.querySelector('[name="step_' + n + '"]');
					if (!typeSel || !valSel) return;
					typeSel.addEventListener('change', function () {
						var opts = (this.value === 'event' ? steps.event : steps.page) || {};
						valSel.innerHTML = '';
						Object.keys(opts).forEach(function (k) {
							var o = document.createElement('option');
							o.value = k;
							o.textContent = opts[k];
							valSel.appendChild(o);
						});
					});
				})(n);
			}
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
