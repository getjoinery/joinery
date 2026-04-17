<?php
/**
 * Image Gallery Component
 *
 * Displays a large image with clickable thumbnail strip below.
 * Pure HTML5 + inline styles + vanilla JS. Works for any entity type.
 *
 * Config (passed via ComponentRenderer::render):
 *   'photos'          - MultiEntityPhoto collection or array of arrays with keys:
 *                        large, thumb, caption, alt, file_id (optional if primary_file_id set)
 *   'primary_file_id' - File ID to display first; also used as fallback when no photos exist
 *   'large_size'      - ImageSizeRegistry key for main image (default: 'content')
 *   'thumbnail_size'  - ImageSizeRegistry key for thumbnails (default: 'avatar')
 *   'alt_text'        - Default alt text when caption is empty
 *   'show_captions'   - Whether to display captions (default: true)
 *   'css_class'       - Extra CSS class on container
 *
 * Available variables:
 *   $component_config - Configuration array
 *   $component_data   - Dynamic data (empty for this component)
 *   $component        - PageContent object or null (null in programmatic mode)
 *
 * @version 1.1.0
 */

$photos_input    = $component_config['photos'] ?? null;
$primary_file_id = $component_config['primary_file_id'] ?? null;
$large_size      = $component_config['large_size'] ?? 'content';
$thumb_size      = $component_config['thumbnail_size'] ?? 'avatar';
$default_alt     = $component_config['alt_text'] ?? '';
$show_captions   = $component_config['show_captions'] ?? true;
$css_class       = $component_config['css_class'] ?? '';

// Build normalised photos array
$photos_data = [];

if ($photos_input && is_object($photos_input) && method_exists($photos_input, 'count')) {
	// MultiEntityPhoto collection
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	$primary_item = null;
	$other_items  = [];

	foreach ($photos_input as $photo) {
		$file = new File($photo->get('eph_fil_file_id'), TRUE);
		$item = [
			'large'   => $file->get_url($large_size, 'full'),
			'thumb'   => $file->get_url($thumb_size, 'full'),
			'caption' => $photo->get('eph_caption') ?: '',
			'alt'     => $photo->get('eph_caption') ?: $default_alt,
			'file_id' => (int) $photo->get('eph_fil_file_id'),
		];
		if ($primary_file_id && $photo->get('eph_fil_file_id') == $primary_file_id) {
			$primary_item = $item;
		} else {
			$other_items[] = $item;
		}
	}

	// Primary first
	if ($primary_item) {
		array_unshift($other_items, $primary_item);
	}
	$photos_data = $other_items;

} elseif (is_array($photos_input)) {
	// Pre-built array
	$photos_data = $photos_input;
}

// Fallback: no entity photos but primary_file_id is set — show that single image
if (empty($photos_data) && $primary_file_id) {
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	$file = new File($primary_file_id, TRUE);
	if ($file->get('fil_file_id')) {
		$photos_data[] = [
			'large'   => $file->get_url($large_size, 'full'),
			'thumb'   => $file->get_url($thumb_size, 'full'),
			'caption' => '',
			'alt'     => $default_alt,
			'file_id' => (int) $primary_file_id,
		];
	}
}

// Nothing to render
if (empty($photos_data)) {
	return;
}

$gallery_id = 'jgallery-' . mt_rand(1000, 9999);
$has_thumbs  = count($photos_data) > 1;
?>
<div class="joinery-gallery <?php echo htmlspecialchars($css_class); ?>" id="<?php echo $gallery_id; ?>">
	<div class="joinery-gallery-main">
		<img src="<?php echo htmlspecialchars($photos_data[0]['large']); ?>"
		     alt="<?php echo htmlspecialchars($photos_data[0]['alt']); ?>"
		     id="<?php echo $gallery_id; ?>-main"
		     style="width:100%; max-height:500px; object-fit:cover; border-radius:6px; display:block;">
		<?php if ($show_captions): ?>
		<p id="<?php echo $gallery_id; ?>-caption"
		   style="text-align:center; margin:8px 0 0; color:#666; font-size:0.9em;<?php echo empty($photos_data[0]['caption']) ? ' display:none;' : ''; ?>">
			<?php echo htmlspecialchars($photos_data[0]['caption']); ?>
		</p>
		<?php endif; ?>
	</div>

	<?php if ($has_thumbs): ?>
	<div class="joinery-gallery-thumbs" style="display:flex; gap:8px; margin-top:12px; overflow-x:auto; padding-bottom:4px;">
		<?php foreach ($photos_data as $i => $pdata): ?>
		<img src="<?php echo htmlspecialchars($pdata['thumb']); ?>"
		     alt="<?php echo htmlspecialchars($pdata['alt']); ?>"
		     data-large-src="<?php echo htmlspecialchars($pdata['large']); ?>"
		     data-caption="<?php echo htmlspecialchars($pdata['caption'] ?? ''); ?>"
		     style="width:80px; height:60px; object-fit:cover; border-radius:4px; cursor:pointer; flex-shrink:0; transition:opacity .2s, border-color .2s; <?php echo $i === 0 ? 'opacity:1; border:2px solid #0d6efd;' : 'opacity:0.5; border:2px solid transparent;'; ?>"
		     onclick="jGalleryClick(this,'<?php echo $gallery_id; ?>')">
		<?php endforeach; ?>
	</div>
	<script>
	function jGalleryClick(el, gid) {
		var g = document.getElementById(gid);
		g.querySelector('#' + gid + '-main').src = el.dataset.largeSrc;
		var cap = g.querySelector('#' + gid + '-caption');
		if (cap) {
			if (el.dataset.caption) { cap.textContent = el.dataset.caption; cap.style.display = ''; }
			else { cap.style.display = 'none'; }
		}
		g.querySelectorAll('.joinery-gallery-thumbs img').forEach(function(t) {
			t.style.opacity = '0.5'; t.style.border = '2px solid transparent';
		});
		el.style.opacity = '1'; el.style.border = '2px solid #0d6efd';
	}
	</script>
	<?php endif; ?>
</div>
