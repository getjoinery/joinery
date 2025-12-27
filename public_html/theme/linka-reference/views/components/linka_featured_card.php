<?php
/**
 * Linka Featured Card Component
 *
 * Featured blog/article card with image, category badge, date, and excerpt.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$image = $component_config['image'] ?? '';
$category = $component_config['category'] ?? '';
$category_link = $component_config['category_link'] ?? '#';
$author = $component_config['author'] ?? '';
$date = $component_config['date'] ?? '';
$title = $component_config['title'] ?? '';
$excerpt = $component_config['excerpt'] ?? '';
$link = $component_config['link'] ?? '#';
$show_author = $component_config['show_author'] ?? true;
$show_excerpt = $component_config['show_excerpt'] ?? true;
?>

<div class="single-featured">
    <a href="<?php echo htmlspecialchars($link); ?>" class="blog-img">
        <?php if ($image): ?>
            <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($title); ?>">
        <?php endif; ?>
        <?php if ($category): ?>
            <span><?php echo htmlspecialchars($category); ?></span>
        <?php endif; ?>
    </a>

    <div class="featured-content">
        <ul>
            <?php if ($show_author && $author): ?>
                <li>
                    <a href="#" class="admin">
                        <i class="bx bx-user"></i>
                        <?php echo htmlspecialchars($author); ?>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($date): ?>
                <li>
                    <i class="bx bx-calendar"></i>
                    <?php echo htmlspecialchars($date); ?>
                </li>
            <?php endif; ?>
        </ul>

        <a href="<?php echo htmlspecialchars($link); ?>">
            <h3><?php echo htmlspecialchars($title); ?></h3>
        </a>

        <?php if ($show_excerpt && $excerpt): ?>
            <p><?php echo htmlspecialchars($excerpt); ?></p>
        <?php endif; ?>

        <a href="<?php echo htmlspecialchars($link); ?>" class="read-more">Read More</a>
    </div>
</div>
