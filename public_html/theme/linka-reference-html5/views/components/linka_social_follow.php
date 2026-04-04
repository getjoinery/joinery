<?php
/**
 * Linka Social Follow Component
 *
 * Social media follow buttons with follower counts.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$networks = $component_config['networks'] ?? [];

// Default networks if none specified
if (empty($networks)) {
    $networks = [
        ['name' => 'Facebook', 'count' => 'Like Us', 'icon' => 'bx bxl-facebook', 'link' => '#'],
        ['name' => 'Twitter', 'count' => 'Follow Us', 'icon' => 'bx bxl-twitter', 'link' => '#'],
        ['name' => 'Instagram', 'count' => 'Follow Us', 'icon' => 'bx bxl-instagram', 'link' => '#'],
        ['name' => 'YouTube', 'count' => 'Subscribe', 'icon' => 'bx bxl-youtube', 'link' => '#']
    ];
}
?>

<div class="follows-area widget">
    <ul>
        <?php foreach ($networks as $network): ?>
            <li>
                <a href="<?php echo htmlspecialchars($network['link'] ?? '#'); ?>" target="_blank">
                    <?php echo htmlspecialchars($network['name'] ?? ''); ?> <br>
                    <span><?php echo htmlspecialchars($network['count'] ?? ''); ?></span>
                    <?php if (!empty($network['icon'])): ?>
                        <i class="<?php echo htmlspecialchars($network['icon']); ?>"></i>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
