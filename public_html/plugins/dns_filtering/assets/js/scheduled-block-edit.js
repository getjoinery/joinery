/*
 * The block editor (views/profile/scheduled_block_edit.php). The page's
 * #sd-block-config carries: block_id, always_on, and for a scheduled block
 * the category metadata and the tier flags (can_edit_main, has_advanced).
 *   - the domain rules list adds and removes rules;
 *   - an always-on block saves each Block|Allow change at once;
 *   - a scheduled block has the overnight hint, the Weekdays / Every day links
 *     ([data-sd-days]), and the override list.
 *
 * @version 1.2 - the overnight hint shows by the hidden property too
 * @version 1.1 - a rule's error shows by the hidden property (it starts [hidden], which beats style.display)
 * @version 1.0
 */
(function () {
var CFG = (window.joineryPageData && joineryPageData('sd-block-config')) || {};

// ── Domain rules ──────────────────────────────────────────────────────────
(function(){
	var blockId = CFG.block_id | 0;
    if (!document.getElementById('domain-rules-rows')) return;
	var tbody = document.getElementById('domain-rules-rows');
	var hostInput = document.getElementById('sd-new-hostname');
	var actionSelect = document.getElementById('sd-new-action');
	var addBtn = document.getElementById('sd-add-rule');
	var errEl = document.getElementById('sd-rule-error');

	function showError(msg){
		errEl.textContent = msg;
		errEl.hidden = false;
	}
	function clearError(){
		errEl.textContent = '';
		errEl.hidden = true;
	}

	// POST a dns_filtering API action, normalizing the envelope back to
	// the legacy { success, ...data, error } shape.
	function dnsfApiPost(action, params){
		return joineryApi.post('dns_filtering/' + action, params || {})
			.then(function(data){ return Object.assign({ success: true }, data || {}); })
			.catch(function(err){ return { success: false, error: err.message || 'Request failed.' }; });
	}

	addBtn.addEventListener('click', function(){
		clearError();
		var hostname = hostInput.value.trim();
		if(!hostname){ showError('Enter a hostname.'); return; }
		addBtn.disabled = true;

		dnsfApiPost('block_rule_add', {
			block_id: blockId,
			hostname: hostname,
			action: actionSelect.value
		}).then(function(data){
			addBtn.disabled = false;
			if(!data.success){
				showError(data.error || 'Could not add rule.');
				return;
			}
			var empty = document.getElementById('domain-rules-empty');
			if(empty){ empty.remove(); }
			var tr = document.createElement('tr');
			tr.setAttribute('data-rule-id', data.rule_id);
			tr.innerHTML = '<td></td><td>'+(data.action_label)+'</td><td><button type="button" class="th-btn style5 sd-rule-delete dnsf-btn-danger" data-rule-id="'+data.rule_id+'">Delete</button></td>';
			tr.firstChild.textContent = data.hostname;
			tbody.appendChild(tr);
			hostInput.value = '';
		}).catch(function(){
			addBtn.disabled = false;
			showError('Network error. Try again.');
		});
	});

	tbody.addEventListener('click', function(e){
		var btn = e.target.closest('.sd-rule-delete');
		if(!btn){ return; }
		clearError();
		var ruleId = btn.getAttribute('data-rule-id');
		btn.disabled = true;

		dnsfApiPost('block_rule_delete', { rule_id: ruleId }).then(function(data){
			if(!data.success){
				btn.disabled = false;
				showError(data.error || 'Could not delete rule.');
				return;
			}
			var row = btn.closest('tr');
			if(row){ row.remove(); }
			if(tbody.children.length === 0){
				var empty = document.createElement('tr');
				empty.id = 'domain-rules-empty';
				empty.innerHTML = '<td colspan="3" class="text-muted">No custom rules yet.</td>';
				tbody.appendChild(empty);
			}
		}).catch(function(){
			btn.disabled = false;
			showError('Network error. Try again.');
		});
	});
})();

if (CFG.always_on) {
// ── Always-on: save on change ─────────────────────────────────────────────
// Always-on save-on-change: each segmented Block|Allow change posts to the
// per-rule AJAX endpoint immediately, so users don't scroll for a Save button.
(function(){
	var rows = document.querySelectorAll('.sd-rule-row.sd-auto-save');
	if(!rows.length){ return; }
	var blockId = CFG.block_id | 0;
	if(!blockId){ return; }

	function flash(row, cls){
		row.classList.remove('sd-saving','sd-saved','sd-save-error');
		row.classList.add(cls);
		if(cls === 'sd-saved'){
			setTimeout(function(){ row.classList.remove('sd-saved'); }, 1200);
		}
	}

	function dnsfApiPost(action, params){
		return joineryApi.post('dns_filtering/' + action, params || {})
			.then(function(data){ return Object.assign({ success: true }, data || {}); })
			.catch(function(err){ return { success: false, error: err.message || 'Request failed.' }; });
	}

	rows.forEach(function(row){
		var key = row.getAttribute('data-key');
		var type = row.getAttribute('data-type');
		row.querySelectorAll('input[type="radio"]').forEach(function(r){
			r.addEventListener('change', function(){
				if(!r.checked){ return; }
				flash(row, 'sd-saving');
				dnsfApiPost('block_filter_set', {
					block_id: blockId, type: type, key: key, action: r.value
				})
				.then(function(j){
					flash(row, j.success ? 'sd-saved' : 'sd-save-error');
					if(!j.success && j.error){ console.error('Save failed:', j.error); }
				})
				.catch(function(e){
					flash(row, 'sd-save-error');
					console.error('Save failed:', e);
				});
			});
		});
	});
})();
} else {
// ── Scheduled ─────────────────────────────────────────────────────────────
// Overnight hint — only relevant to scheduled blocks
function checkOvernight() {
	var start = document.querySelector('[name="start_time"]');
	var end = document.querySelector('[name="end_time"]');
	var hint = document.getElementById('overnight-hint');
	if (start && end && hint) {
		hint.hidden = !(end.value < start.value && end.value !== '' && start.value !== '');
	}
}
document.addEventListener('DOMContentLoaded', function() {
	var start = document.querySelector('[name="start_time"]');
	var end = document.querySelector('[name="end_time"]');
	if (start) start.addEventListener('change', checkOvernight);
	if (end) end.addEventListener('change', checkOvernight);
	checkOvernight();
});

// Day shortcut links
function selectDays(days) {
	var checkboxes = document.querySelectorAll('[name="days_blocked[]"]');
	checkboxes.forEach(function(cb) {
		cb.checked = days.indexOf(cb.value) !== -1;
	});
}

// Override list management
(function(){
	var list = document.getElementById('sd-overrides-list');
	if(!list){ return; }
	var addCat = document.getElementById('sd-add-category');
	var form = list.closest('form');

	function escapeHtml(s){
		return String(s).replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
		});
	}

	function clearEmptyMessage(){
		var empty = document.getElementById('sd-overrides-empty');
		if(empty){ empty.remove(); }
	}

	function showEmptyMessageIfNeeded(){
		if(list.querySelectorAll('.sd-override-row').length === 0 && !document.getElementById('sd-overrides-empty')){
			var d = document.createElement('div');
			d.id = 'sd-overrides-empty';
			d.className = 'sd-empty-state';
			d.textContent = 'No filters yet — this schedule uses your always-on rules.';
			list.appendChild(d);
		}
	}

	function buildRow(key, label, action, isAdvanced){
		var row = document.createElement('div');
		row.className = 'sd-rule-row sd-override-row';
		row.setAttribute('data-key', key);
		row.setAttribute('data-advanced', isAdvanced ? '1' : '0');
		var nameAttr = 'rule_' + key;
		var idBlock = nameAttr + '_block';
		var idAllow = nameAttr + '_allow';
		var blockChecked = action === '0' ? ' checked' : '';
		var allowChecked = action === '1' ? ' checked' : '';
		row.innerHTML =
			'<span class="sd-rule-label">' + escapeHtml(label) + '</span>' +
			'<div class="sd-rule-controls">' +
				'<div class="sd-segmented" role="radiogroup">' +
					'<input type="radio" name="' + escapeHtml(nameAttr) + '" id="' + escapeHtml(idBlock) + '" value="0"' + blockChecked + '>' +
					'<label for="' + escapeHtml(idBlock) + '">Block</label>' +
					'<input type="radio" name="' + escapeHtml(nameAttr) + '" id="' + escapeHtml(idAllow) + '" value="1"' + allowChecked + '>' +
					'<label for="' + escapeHtml(idAllow) + '">Allow</label>' +
				'</div>' +
				'<button type="button" class="btn-remove-override" aria-label="Remove filter">&times;</button>' +
			'</div>';
		return row;
	}

	if(addCat){
		addCat.addEventListener('change', function(){
			if(!addCat.value){ return; }
			var opt = addCat.options[addCat.selectedIndex];
			var key = addCat.value;
			var label = opt.textContent;
			var isAdvanced = opt.getAttribute('data-advanced') === '1';

			clearEmptyMessage();
			// New filters default to Block; user can flip via the segmented control on the row.
			var row = buildRow(key, label, '0', isAdvanced);
			list.appendChild(row);

			// Remove the chosen option from the picker so the user can't add a duplicate.
			opt.remove();
			// If the optgroup is now empty, remove it for cleanliness.
			addCat.querySelectorAll('optgroup').forEach(function(og){
				if(og.children.length === 0){ og.remove(); }
			});
			addCat.selectedIndex = 0;
		});
	}

	list.addEventListener('click', function(e){
		var btn = e.target.closest('.btn-remove-override');
		if(!btn){ return; }
		var row = btn.closest('.sd-override-row');
		if(!row){ return; }
		var key = row.getAttribute('data-key');
		var isAdvanced = row.getAttribute('data-advanced') === '1';

		// If this is an advanced override and the user lacks the feature, the radio
		// inputs are disabled and won't submit anything — so update_filters() with
		// $skip_keys would preserve the row. Fall back to the explicit-delete path.
		var radio = row.querySelector('input[type="radio"]');
		if(isAdvanced && radio && radio.disabled){
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'remove_advanced_keys[]';
			hidden.value = key;
			form.appendChild(hidden);
		}

		row.remove();
		showEmptyMessageIfNeeded();

		// Restore the option to the picker (only if user can edit this category).
		if(addCat){
			var meta = CFG.category_meta && CFG.category_meta[key];
			if(meta){
				var canRestore = isAdvanced ? !!CFG.has_advanced : !!CFG.can_edit_main;
				if(canRestore){
					var groupName = meta.group;
					var og = addCat.querySelector('optgroup[label="' + CSS.escape(groupName) + '"]');
					if(!og){
						og = document.createElement('optgroup');
						og.setAttribute('label', groupName);
						addCat.appendChild(og);
					}
					var newOpt = document.createElement('option');
					newOpt.value = key;
					newOpt.textContent = meta.label;
					newOpt.setAttribute('data-type', meta.type);
					newOpt.setAttribute('data-advanced', isAdvanced ? '1' : '0');
					og.appendChild(newOpt);
				}
			}
		}
	});
})();
document.addEventListener('click', function (e) {
	var a = e.target.closest ? e.target.closest('[data-sd-days]') : null;
	if (!a) return;
	e.preventDefault();
	selectDays(a.getAttribute('data-sd-days').split(','));
});
}
})();
