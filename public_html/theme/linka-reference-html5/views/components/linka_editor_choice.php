<?php
/**
 * Linka Editor's Choice Component
 *
 * Editor's choice layout with main featured article and sidebar articles.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$section_title = $component_config['section_title'] ?? "Editor's Choice";
$background_color = $component_config['background_color'] ?? '';
$main_article = $component_config['main_article'] ?? [];
$sidebar_articles = $component_config['sidebar_articles'] ?? [];

// Build section class/style
$section_class = 'editor-choice-area pt-100 pb-70';
$section_style = '';
if (!empty($background_color)) {
    $section_style = 'background-color: ' . htmlspecialchars($background_color) . ';';
} else {
    $section_class .= ' bg-color';
}
?>

<section class="<?php echo $section_class; ?>" <?php echo $section_style ? 'style="' . $section_style . '"' : ''; ?>>
    <div class="container">
        <?php if ($section_title): ?>
            <div class="section-title">
                <h2><?php echo htmlspecialchars($section_title); ?></h2>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <?php if (!empty($main_article)): ?>
                    <div class="editor-blog">
                        <a href="<?php echo htmlspecialchars($main_article['link'] ?? '#'); ?>">
                            <?php if (!empty($main_article['image'])): ?>
                                <img src="<?php echo htmlspecialchars($main_article['image']); ?>" alt="<?php echo htmlspecialchars($main_article['title'] ?? ''); ?>">
                            <?php endif; ?>
                        </a>

                        <div class="editor-blog-content">
                            <a href="<?php echo htmlspecialchars($main_article['link'] ?? '#'); ?>">
                                <h3><?php echo htmlspecialchars($main_article['title'] ?? ''); ?></h3>
                            </a>

                            <?php if (!empty($main_article['excerpt'])): ?>
                                <p><?php echo htmlspecialchars($main_article['excerpt']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($main_article['date'])): ?>
                                <ul>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        <?php echo htmlspecialchars($main_article['date']); ?>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <?php foreach ($sidebar_articles as $article): ?>
                    <div class="right-blog-editor media align-items-center">
                        <a href="<?php echo htmlspecialchars($article['link'] ?? '#'); ?>">
                            <?php if (!empty($article['image'])): ?>
                                <img src="<?php echo htmlspecialchars($article['image']); ?>" alt="<?php echo htmlspecialchars($article['title'] ?? ''); ?>">
                            <?php endif; ?>
                        </a>

                        <div class="right-blog-content">
                            <a href="<?php echo htmlspecialchars($article['link'] ?? '#'); ?>">
                                <h3><?php echo htmlspecialchars($article['title'] ?? ''); ?></h3>
                            </a>

                            <?php if (!empty($article['date'])): ?>
                                <span>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo htmlspecialchars($article['date']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
