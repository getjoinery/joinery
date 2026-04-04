<?php
/**
 * Linka Hero Component
 *
 * Full-width hero section with background image, headline, and metadata.
 * Based on the main-blog-item styling from Linka index.html.
 *
 * @version 1.0.0
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$title = $component_config['title'] ?? 'Welcome';
$category = $component_config['category'] ?? '';
$author = $component_config['author'] ?? '';
$date = $component_config['date'] ?? '';
$image = $component_config['image'] ?? '';
$link = $component_config['link'] ?? '#';
$min_height = $component_config['min_height'] ?? '500px';
?>

<section class="main-blog-area pb-0">
    <div class="container-fluid p-0">
        <div class="single-main-blog-item" style="min-height: <?php echo htmlspecialchars($min_height); ?>;">
            <?php if ($image): ?>
                <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($title); ?>" style="width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0;">
            <?php endif; ?>

            <?php if ($category): ?>
                <span class="blog-link"><?php echo htmlspecialchars($category); ?></span>
            <?php endif; ?>

            <div class="main-blog-content">
                <a href="<?php echo htmlspecialchars($link); ?>">
                    <h3><?php echo htmlspecialchars($title); ?></h3>
                </a>

                <?php if ($author || $date): ?>
                    <ul>
                        <?php if ($author): ?>
                            <li>
                                <span class="admin">
                                    <i class="bx bx-user"></i>
                                    By <?php echo htmlspecialchars($author); ?>
                                </span>
                            </li>
                        <?php endif; ?>
                        <?php if ($date): ?>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo htmlspecialchars($date); ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
