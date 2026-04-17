<?php
/**
 * Linka Inspiration Component
 *
 * Inspiration section with staggered card layout.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$section_title = $component_config['section_title'] ?? 'Inspiration';
$cards = $component_config['cards'] ?? [];
?>

<section class="inspiration-area pt-100 pb-70">
    <div class="container">
        <?php if ($section_title): ?>
            <div class="section-title">
                <h2><?php echo htmlspecialchars($section_title); ?></h2>
            </div>
        <?php endif; ?>

        <div class="row">
            <?php foreach ($cards as $card): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="single-inspiration<?php echo !empty($card['offset']) ? ' mt-minus-50' : ''; ?>">
                        <a href="<?php echo htmlspecialchars($card['link'] ?? '#'); ?>">
                            <?php if (!empty($card['image'])): ?>
                                <img src="<?php echo htmlspecialchars($card['image']); ?>" alt="<?php echo htmlspecialchars($card['title'] ?? ''); ?>">
                            <?php endif; ?>
                        </a>

                        <?php if (!empty($card['category'])): ?>
                            <span class="blog-link"><?php echo htmlspecialchars($card['category']); ?></span>
                        <?php endif; ?>

                        <div class="inspiration-content">
                            <a href="<?php echo htmlspecialchars($card['link'] ?? '#'); ?>">
                                <h3><?php echo htmlspecialchars($card['title'] ?? ''); ?></h3>
                            </a>

                            <?php if (!empty($card['date'])): ?>
                                <ul>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        <?php echo htmlspecialchars($card['date']); ?>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
