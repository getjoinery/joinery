/*
 * PhotoHelper's photo grid (includes/PhotoHelper.php, grid mode): upload,
 * reorder by drag, set the primary photo, remove. Each grid on the page has a
 * <script type="application/json" data-joinery-photo-grid> block with its
 * prefix, entity, set-primary URL and messages.
 *
 * @version 1.0
 */
(function() {
	'use strict';
function setUpGrid(cfg) {
	var prefix = cfg.prefix;
	var entityType = cfg.entityType;
	var entityId = cfg.entityId;
	var grid = document.getElementById(prefix + '-grid');
	if (!grid) return;

	// Upload button
	var btnUpload = document.getElementById(prefix + '-upload-btn');
	var fileInput = document.getElementById(prefix + '-upload-input');
	if (btnUpload && fileInput) {
		btnUpload.addEventListener('click', function() {
			fileInput.click();
		});
		fileInput.addEventListener('change', function() {
			if (!this.files || !this.files[0]) return;
			var file = this.files[0];
			btnUpload.disabled = true;
			btnUpload.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';

			var formData = new FormData();
			formData.append('action', 'upload');
			formData.append('entity_type', entityType);
			formData.append('entity_id', entityId);
			formData.append('file', file);

			fetch('/ajax/entity_photos_ajax', {
				method: 'POST',
				body: formData
			})
			.then(function(resp) { return resp.json(); })
			.then(function(data) {
				if (data.error) {
					alert('Upload failed: ' + data.error);
					btnUpload.disabled = false;
					btnUpload.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="margin-right:0.25rem; vertical-align:text-bottom;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Upload';
					return;
				}
				if (data.warning) {
					alert(data.warning);
				}
				window.location.reload();
			})
			.catch(function(err) {
				alert('Upload failed: ' + err.message);
				btnUpload.disabled = false;
				btnUpload.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="margin-right:0.25rem; vertical-align:text-bottom;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Upload';
			});

			this.value = '';
		});
	}

	// Set primary - event delegation scoped to grid
	grid.addEventListener('click', function(e) {
		var btn = e.target.closest('.joinery-photo-set-primary-btn');
		if (!btn) return;
		e.preventDefault();
		var photoId = btn.getAttribute('data-photo-id');

		var form = document.createElement('form');
		form.method = 'POST';
		form.action = cfg.setPrimaryUrl;
		form.style.display = 'none';

		var actionInput = document.createElement('input');
		actionInput.type = 'hidden';
		actionInput.name = 'action';
		actionInput.value = 'set_primary_photo';
		form.appendChild(actionInput);

		var photoInput = document.createElement('input');
		photoInput.type = 'hidden';
		photoInput.name = 'photo_id';
		photoInput.value = photoId;
		form.appendChild(photoInput);

		document.body.appendChild(form);
		form.submit();
	});

	// Delete photo - event delegation scoped to grid
	grid.addEventListener('click', function(e) {
		var btn = e.target.closest('.joinery-photo-delete-btn');
		if (!btn) return;
		e.preventDefault();
		if (!confirm(cfg.confirmDeleteMsg)) return;

		var photoId = btn.getAttribute('data-photo-id');
		var photoItem = btn.closest('.joinery-photo-item');

		var formData = new FormData();
		formData.append('action', 'delete');
		formData.append('entity_type', entityType);
		formData.append('entity_id', entityId);
		formData.append('photo_id', photoId);

		fetch('/ajax/entity_photos_ajax', {
			method: 'POST',
			body: formData
		})
		.then(function(resp) { return resp.json(); })
		.then(function(data) {
			if (data.error) {
				alert('Delete failed: ' + data.error);
				return;
			}
			if (photoItem) photoItem.remove();
			var remaining = grid.querySelectorAll('.joinery-photo-item');
			if (remaining.length === 0) {
				grid.innerHTML = '<div id="' + prefix + '-empty" class="col-12 text-center text-muted py-4">' +
					'<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.25; margin-bottom:0.5rem; display:block; margin-left:auto; margin-right:auto;"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>' +
					cfg.emptyMessage + '</div>';
			}
		})
		.catch(function(err) {
			alert('Delete failed: ' + err.message);
		});
	});

	// Drag-and-drop reorder
	var dragItem = null;

	grid.addEventListener('dragstart', function(e) {
		dragItem = e.target.closest('.joinery-photo-item');
		if (!dragItem) return;
		dragItem.style.opacity = '0.4';
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('text/plain', dragItem.dataset.photoId);
	});

	grid.addEventListener('dragend', function(e) {
		if (dragItem) {
			dragItem.style.opacity = '1';
			dragItem = null;
		}
		grid.querySelectorAll('.joinery-photo-item').forEach(function(item) {
			item.style.borderLeft = '';
			item.style.borderRight = '';
		});
	});

	grid.addEventListener('dragover', function(e) {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		var target = e.target.closest('.joinery-photo-item');
		if (!target || target === dragItem) return;

		grid.querySelectorAll('.joinery-photo-item').forEach(function(item) {
			item.style.borderLeft = '';
			item.style.borderRight = '';
		});

		var rect = target.getBoundingClientRect();
		var midX = rect.left + rect.width / 2;
		if (e.clientX < midX) {
			target.style.borderLeft = '3px solid #2c7be5';
		} else {
			target.style.borderRight = '3px solid #2c7be5';
		}
	});

	grid.addEventListener('drop', function(e) {
		e.preventDefault();
		var target = e.target.closest('.joinery-photo-item');
		if (!target || !dragItem || target === dragItem) return;

		var rect = target.getBoundingClientRect();
		var midX = rect.left + rect.width / 2;
		if (e.clientX < midX) {
			grid.insertBefore(dragItem, target);
		} else {
			grid.insertBefore(dragItem, target.nextSibling);
		}

		grid.querySelectorAll('.joinery-photo-item').forEach(function(item) {
			item.style.borderLeft = '';
			item.style.borderRight = '';
		});

		var photoIds = [];
		grid.querySelectorAll('.joinery-photo-item').forEach(function(item) {
			photoIds.push(item.dataset.photoId);
		});

		var formData = new FormData();
		formData.append('action', 'reorder');
		formData.append('entity_type', entityType);
		formData.append('entity_id', entityId);
		photoIds.forEach(function(id) {
			formData.append('photo_ids[]', id);
		});

		fetch('/ajax/entity_photos_ajax', {
			method: 'POST',
			body: formData
		})
		.then(function(resp) { return resp.json(); })
		.then(function(data) {
			if (data.error) {
				alert('Reorder failed: ' + data.error);
				window.location.reload();
			}
		})
		.catch(function(err) {
			alert('Reorder failed: ' + err.message);
			window.location.reload();
		});
	});
}

function start() {
	document.querySelectorAll('script[type="application/json"][data-joinery-photo-grid]').forEach(function(el) {
		var cfg;
		try { cfg = JSON.parse(el.textContent); } catch (e) { return; }
		if (cfg && cfg.prefix) setUpGrid(cfg);
	});
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
