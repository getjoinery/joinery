<?php
/**
 * Linka Page Title Component
 *
 * Linka-styled page title area with breadcrumb navigation and overlay background.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$title = $component_config['title'] ?? '';
$background_class = $component_config['background_class'] ?? 'bg-12';
$background_image = $component_config['background_image'] ?? '';
$breadcrumbs = $component_config['breadcrumbs'] ?? [];

// Build background style
$bg_style = '';
if (!empty($background_image)) {
    $bg_style = 'background-image: url(' . htmlspecialchars($background_image) . '); background-size: cover; background-position: center;';
}
?>

<div class="page-title-area <?php echo htmlspecialchars($background_class); ?>" <?php echo $bg_style ? 'style="' . $bg_style . '"' : ''; ?>>
    <div class="container">
        <div class="page-title-content">
            <?php if ($title): ?>
                <h2><?php echo htmlspecialchars($title); ?></h2>
            <?php endif; ?>

            <?php if (!empty($breadcrumbs)): ?>
                <ul>
                    <?php
                    $total = count($breadcrumbs);
                    $i = 0;
                    foreach ($breadcrumbs as $crumb):
                        $i++;
                        $is_last = ($i === $total);
                    ?>
                        <li>
                            <?php if (!$is_last && !empty($crumb['link'])): ?>
                                <a href="<?php echo htmlspecialchars($crumb['link']); ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($crumb['text']); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
