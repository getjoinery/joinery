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
 * @version 1.3.0 - the grid's script is assets/js/photo-grid.js, its settings a JSON block (no inline script)
 * @version 1.2.0 - an upload's `warning` (saved, but no sizes) is shown before the reload
 * @version 1.1.0
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
				<h6 class="mb-0"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:0.5rem; vertical-align:text-bottom;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><?php echo $card_title; ?></h6>
				<?php if ($editable): ?>
				<button type="button" class="btn btn-primary btn-sm" id="<?php echo $prefix; ?>-upload-btn">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="margin-right:0.25rem; vertical-align:text-bottom;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Upload
				</button>
				<input type="file" id="<?php echo $prefix; ?>-upload-input" accept="image/*" style="display:none;">
				<?php endif; ?>
			</div>
			<div class="card-body">
				<div id="<?php echo $prefix; ?>-grid" class="row g-2">
					<?php if (count($photos) == 0): ?>
						<div id="<?php echo $prefix; ?>-empty" class="col-12 text-center text-muted py-4">
							<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.25; margin-bottom:0.5rem; display:block; margin-left:auto; margin-right:auto;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
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
								<?php if (isset($options['primary_file_id']) && $photo->get('eph_fil_file_id') == $options['primary_file_id']): ?>
									<span class="position-absolute top-0 start-0 text-warning" title="Primary photo"
										  style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px; margin:0.25rem; line-height:1;">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14 2 9.27l6.91-1.01z"/></svg>
									</span>
								<?php elseif ($editable): ?>
									<a href="#" class="position-absolute top-0 start-0 text-white joinery-photo-set-primary-btn"
									   data-photo-id="<?php echo $photo->key; ?>" title="Set as primary"
									   style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px; margin:0.25rem; line-height:1; text-decoration:none;">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14 2 9.27l6.91-1.01z"/></svg>
									</a>
								<?php endif; ?>
								<?php if ($editable): ?>
								<a href="#" class="position-absolute top-0 end-0 text-white joinery-photo-delete-btn"
								   data-photo-id="<?php echo $photo->key; ?>" title="Remove photo"
								   style="background:rgba(0,0,0,0.5); border-radius:50%; padding:2px 4px; margin:0.25rem; line-height:1; text-decoration:none;">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
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
	 * The multi-photo grid's settings (a JSON block) and its script (once per page)
	 */
	private static function render_grid_scripts($entity_type, $entity_id, $options) {
		$prefix = self::get_prefix($entity_type, $entity_id);
		// The grid's settings as data, and its script as a file: no inline
		// script (specs/strict_script_policy.md).
		$cfg = array(
			'prefix' => $prefix,
			'entityType' => (string)$entity_type,
			'entityId' => (int)$entity_id,
			'setPrimaryUrl' => (string)$options['set_primary_url'],
			'confirmDeleteMsg' => (string)$options['confirm_delete_msg'],
			'emptyMessage' => (string)$options['empty_message'],
		);
		echo '<script type="application/json" data-joinery-photo-grid>'
			. json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . '</script>';
		static $script_sent = false;
		if (!$script_sent) {
			$script_sent = true;
			echo '<script defer src="/assets/js/photo-grid.js?v='
				. (@filemtime(PathHelper::getIncludePath('assets/js/photo-grid.js')) ?: '1') . '"></script>';
		}
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
