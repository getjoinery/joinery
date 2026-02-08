<?php
/**
 * PhotoHelper - Renders entity photo management UI components
 *
 * Static utility class that outputs HTML and JavaScript for managing
 * entity photos (upload, delete, reorder, set primary). Supports
 * multiple display modes for different use cases.
 *
 * Usage:
 *   require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
 *   PhotoHelper::render_photo_card('grid', 'event', $id, $photos, $options);
 *   PhotoHelper::render_photo_scripts('grid', 'event', $id, $options);
 *
 * @version 1.0.0
 * @see /specs/profile_picture_upload_spec.md
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

class PhotoHelper {

	/**
	 * Default options for all modes
	 */
	private static $defaults = [
		'set_primary_url' => '',
		'card_title' => 'Photos',
		'image_size' => 'profile_card',
		'confirm_delete_msg' => 'Remove this photo?',
		'editable' => true,
		'aspect_ratio' => '4/5',
		'empty_message' => 'No photos yet',
	];

	/**
	 * Render the photo card HTML
	 *
	 * @param string $mode Display mode: 'grid' or 'single'
	 * @param string $entity_type Entity type for EntityPhoto system
	 * @param int $entity_id Entity primary key
	 * @param MultiEntityPhoto $photos Photo collection
	 * @param array $options Configuration options
	 */
	public static function render_photo_card($mode, $entity_type, $entity_id, $photos, $options = []) {
		$options = array_merge(self::$defaults, $options);

		switch ($mode) {
			case 'grid':
				self::render_grid_card($entity_type, $entity_id, $photos, $options);
				break;
			case 'single':
				self::render_single_card($entity_type, $entity_id, $photos, $options);
				break;
			default:
				throw new Exception("PhotoHelper: Unknown mode '$mode'");
		}
	}

	/**
	 * Render the associated JavaScript
	 *
	 * @param string $mode Display mode: 'grid' or 'single'
	 * @param string $entity_type Entity type for EntityPhoto system
	 * @param int $entity_id Entity primary key
	 * @param array $options Configuration options
	 */
	public static function render_photo_scripts($mode, $entity_type, $entity_id, $options = []) {
		$options = array_merge(self::$defaults, $options);

		switch ($mode) {
			case 'grid':
				self::render_grid_scripts($entity_type, $entity_id, $options);
				break;
			case 'single':
				self::render_single_scripts($entity_type, $entity_id, $options);
				break;
			default:
				throw new Exception("PhotoHelper: Unknown mode '$mode'");
		}
	}

	/**
	 * Generate the namespaced ID prefix for this instance
	 *
	 * @param string $entity_type
	 * @param int $entity_id
	 * @return string e.g., "joinery-photo-event-123"
	 */
	private static function get_prefix($entity_type, $entity_id) {
		return 'joinery-photo-' . htmlspecialchars($entity_type) . '-' . (int)$entity_id;
	}

	// =========================================================================
	// Grid Mode
	// =========================================================================

	/**
	 * Render multi-photo grid card HTML
	 */
	private static function render_grid_card($entity_type, $entity_id, $photos, $options) {
		$prefix = self::get_prefix($entity_type, $entity_id);
		$editable = $options['editable'];
		$image_size = $options['image_size'];
		$aspect_ratio = htmlspecialchars($options['aspect_ratio']);
		$empty_message = htmlspecialchars($options['empty_message']);
		$card_title = htmlspecialchars($options['card_title']);
		?>
		<div class="card mt-3">
			<div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
				<h6 class="mb-0"><span class="fas fa-images me-2"></span><?php echo $card_title; ?></h6>
				<?php if ($editable): ?>
				<button type="button" class="btn btn-primary btn-sm" id="<?php echo $prefix; ?>-upload-btn">
					<span class="fas fa-plus me-1"></span>Upload
				</button>
				<input type="file" id="<?php echo $prefix; ?>-upload-input" accept="image/*" style="display:none;">
				<?php endif; ?>
			</div>
			<div class="card-body">
				<div id="<?php echo $prefix; ?>-grid" class="row g-2">
					<?php if (count($photos) == 0): ?>
						<div id="<?php echo $prefix; ?>-empty" class="col-12 text-center text-muted py-4">
							<span class="fas fa-image fa-3x mb-2 d-block opacity-25"></span>
							<?php echo $empty_message; ?>
						</div>
					<?php endif; ?>
					<?php foreach ($photos as $photo): ?>
						<?php $photo_file = new File($photo->get('eph_fil_file_id'), TRUE); ?>
						<div class="col-4 col-md-3 joinery-photo-item" data-photo-id="<?php echo $photo->key; ?>"
							 <?php if ($editable): ?>draggable="true" style="cursor: grab;"<?php endif; ?>>
							<div class="position-relative">
								<img src="<?php echo htmlspecialchars($photo_file->get_url($image_size)); ?>"
									 class="img-fluid rounded" alt=""
									 style="width:100%; aspect-ratio:<?php echo $aspect_ratio; ?>; object-fit:cover; pointer-events:none;">
								<?php if ($photo->get('eph_is_primary')): ?>
									<span class="position-absolute top-0 start-0 m-1 text-warning" title="Primary photo"
										  style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px;">
										<span class="fas fa-star"></span>
									</span>
								<?php elseif ($editable): ?>
									<a href="#" class="position-absolute top-0 start-0 m-1 text-white joinery-photo-set-primary-btn"
									   data-photo-id="<?php echo $photo->key; ?>" title="Set as primary"
									   style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px;">
										<span class="far fa-star"></span>
									</a>
								<?php endif; ?>
								<?php if ($editable): ?>
								<a href="#" class="position-absolute top-0 end-0 m-1 text-white joinery-photo-delete-btn"
								   data-photo-id="<?php echo $photo->key; ?>" title="Remove photo"
								   style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px;">
									<span class="fas fa-times-circle"></span>
								</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render multi-photo grid JavaScript
	 */
	private static function render_grid_scripts($entity_type, $entity_id, $options) {
		$prefix = self::get_prefix($entity_type, $entity_id);
		$confirm_delete_msg = htmlspecialchars($options['confirm_delete_msg'], ENT_QUOTES);
		$set_primary_url = htmlspecialchars($options['set_primary_url'], ENT_QUOTES);
		$empty_message = htmlspecialchars($options['empty_message']);
		?>
		<script>
		(function() {
			var prefix = <?php echo json_encode($prefix); ?>;
			var entityType = <?php echo json_encode($entity_type); ?>;
			var entityId = <?php echo (int)$entity_id; ?>;
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
							btnUpload.innerHTML = '<span class="fas fa-plus me-1"></span>Upload';
							return;
						}
						window.location.reload();
					})
					.catch(function(err) {
						alert('Upload failed: ' + err.message);
						btnUpload.disabled = false;
						btnUpload.innerHTML = '<span class="fas fa-plus me-1"></span>Upload';
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
				form.action = <?php echo json_encode($options['set_primary_url']); ?>;
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
				if (!confirm(<?php echo json_encode($options['confirm_delete_msg']); ?>)) return;

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
							'<span class="fas fa-image fa-3x mb-2 d-block opacity-25"></span>' +
							<?php echo json_encode($options['empty_message']); ?> + '</div>';
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
		})();
		</script>
		<?php
	}

	// =========================================================================
	// Single Mode (stub for future use)
	// =========================================================================

	/**
	 * Render single-photo card HTML (not yet implemented)
	 */
	private static function render_single_card($entity_type, $entity_id, $photos, $options) {
		throw new Exception("PhotoHelper: 'single' mode is not yet implemented");
	}

	/**
	 * Render single-photo JavaScript (not yet implemented)
	 */
	private static function render_single_scripts($entity_type, $entity_id, $options) {
		throw new Exception("PhotoHelper: 'single' mode is not yet implemented");
	}
}
