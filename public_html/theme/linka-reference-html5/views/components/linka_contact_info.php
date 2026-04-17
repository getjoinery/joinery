<?php
/**
 * Linka Contact Info Component
 *
 * Contact information cards with icons - email, phone, location, live chat.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$items = $component_config['items'] ?? [];
$columns = $component_config['columns'] ?? '4';

// Column class based on count
$col_classes = [
    '2' => 'col-lg-6 col-sm-6',
    '3' => 'col-lg-4 col-sm-6',
    '4' => 'col-lg-3 col-sm-6'
];
$col_class = $col_classes[$columns] ?? 'col-lg-3 col-sm-6';
?>

<section class="contact-info-area pt-100 pb-70">
    <div class="container">
        <div class="row">
            <?php foreach ($items as $item): ?>
                <div class="<?php echo $col_class; ?>">
                    <div class="single-contact-info">
                        <?php if (!empty($item['icon'])): ?>
                            <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                        <?php endif; ?>

                        <?php if (!empty($item['title'])): ?>
                            <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                        <?php endif; ?>

                        <?php if (!empty($item['line1'])): ?>
                            <?php if (!empty($item['line1_link'])): ?>
                                <a href="<?php echo htmlspecialchars($item['line1_link']); ?>"><?php echo htmlspecialchars($item['line1']); ?></a>
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($item['line1']); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($item['line2'])): ?>
                            <?php if (!empty($item['line2_link'])): ?>
                                <a href="<?php echo htmlspecialchars($item['line2_link']); ?>"><?php echo htmlspecialchars($item['line2']); ?></a>
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($item['line2']); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
