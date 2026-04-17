<?php
/**
 * Linka Featured Grid Component
 *
 * Grid of featured articles with section title.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$section_title = $component_config['section_title'] ?? '';
$columns = $component_config['columns'] ?? '3';
$show_author = $component_config['show_author'] ?? true;
$show_excerpt = $component_config['show_excerpt'] ?? true;
$articles = $component_config['articles'] ?? [];
$background_color = $component_config['background_color'] ?? '';

// Column class based on count
$col_classes = [
    '2' => 'col-lg-6 col-md-6',
    '3' => 'col-lg-4 col-md-6',
    '4' => 'col-lg-3 col-md-6'
];
$col_class = $col_classes[$columns] ?? 'col-lg-4 col-md-6';

// Build style
$section_style = '';
if (!empty($background_color)) {
    $section_style = 'background-color: ' . htmlspecialchars($background_color) . ';';
}
?>

<section class="featured-area one pb-70" <?php echo $section_style ? 'style="' . $section_style . '"' : ''; ?>>
    <div class="container">
        <?php if ($section_title): ?>
            <div class="section-title">
                <h2><?php echo htmlspecialchars($section_title); ?></h2>
            </div>
        <?php endif; ?>

        <div class="row">
            <?php foreach ($articles as $article): ?>
                <div class="<?php echo $col_class; ?>">
                    <div class="single-featured">
                        <a href="<?php echo htmlspecialchars($article['link'] ?? '#'); ?>" class="blog-img">
                            <?php if (!empty($article['image'])): ?>
                                <img src="<?php echo htmlspecialchars($article['image']); ?>" alt="<?php echo htmlspecialchars($article['title'] ?? ''); ?>">
                            <?php endif; ?>
                            <?php if (!empty($article['category'])): ?>
                                <span><?php echo htmlspecialchars($article['category']); ?></span>
                            <?php endif; ?>
                        </a>

                        <div class="featured-content">
                            <ul>
                                <?php if ($show_author && !empty($article['author'])): ?>
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            <?php echo htmlspecialchars($article['author']); ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if (!empty($article['date'])): ?>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        <?php echo htmlspecialchars($article['date']); ?>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <a href="<?php echo htmlspecialchars($article['link'] ?? '#'); ?>">
                                <h3><?php echo htmlspecialchars($article['title'] ?? ''); ?></h3>
                            </a>

                            <?php if ($show_excerpt && !empty($article['excerpt'])): ?>
                                <p><?php echo htmlspecialchars($article['excerpt']); ?></p>
                            <?php endif; ?>

                            <a href="<?php echo htmlspecialchars($article['link'] ?? '#'); ?>" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
