<?php
/**
 * Video Embed Component
 *
 * Responsive YouTube or Vimeo video embed. Pure HTML5, no framework dependencies.
 * Parses video URLs to extract IDs and generates secure iframe embeds.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading = $component_config['heading'] ?? '';
$video_url = $component_config['video_url'] ?? '';
$aspect_ratio = $component_config['aspect_ratio'] ?? '16x9';
$caption = $component_config['caption'] ?? '';

// Map aspect ratio to CSS value
$ratio_map = [
	'16x9' => '16 / 9',
	'4x3' => '4 / 3',
	'21x9' => '21 / 9',
];
$css_ratio = $ratio_map[$aspect_ratio] ?? '16 / 9';

/**
 * Parse video URL to get embed URL
 * Only allows youtube.com and vimeo.com embed domains
 */
$embed_url = '';
if ($video_url) {
	// YouTube: youtube.com/watch?v=ID, youtu.be/ID, youtube.com/embed/ID
	if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $video_url, $matches)) {
		$embed_url = 'https://www.youtube.com/embed/' . htmlspecialchars($matches[1]);
	}
	// Vimeo: vimeo.com/ID
	elseif (preg_match('/vimeo\.com\/(\d+)/', $video_url, $matches)) {
		$embed_url = 'https://player.vimeo.com/video/' . htmlspecialchars($matches[1]);
	}
}

if (!$embed_url) {
	return; // Nothing to render
}
?>
<section>
	<div style="max-width: 1100px; margin: 0 auto; padding: 3rem 1rem;">
		<?php if ($heading): ?>
			<h2 style="margin: 0 0 1.5rem 0;"><?php echo htmlspecialchars($heading); ?></h2>
		<?php endif; ?>

		<div style="aspect-ratio: <?php echo $css_ratio; ?>; width: 100%;">
			<iframe
				src="<?php echo $embed_url; ?>"
				style="width: 100%; height: 100%; border: 0;"
				loading="lazy"
				allowfullscreen
				allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
			></iframe>
		</div>

		<?php if ($caption): ?>
			<p style="margin: 0.75rem 0 0 0; color: #6c757d; font-size: 0.9rem;"><?php echo htmlspecialchars($caption); ?></p>
		<?php endif; ?>
	</div>
</section>
