<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('pricing_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(pricing_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
]);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Our Pricing Plans</h1>
                <span>Choose the perfect plan for your needs</span>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">Pricing</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="jy-content-section">
    <div class="jy-container">

        <!-- Pricing Cards -->
        <div class="pricing grid-3 jy-pricing-grid">
            <?php
            $cardIndex = 0;
            foreach ($page_vars['tier_display_data'] as $item):
                $tier      = $item['tier'];
                $product   = $item['product'];
                $version   = $item['version'];
                $cardIndex++;
                $isPopular = ($cardIndex == 2);
            ?>
            <div class="jy-pricing-card<?php echo $isPopular ? ' is-popular' : ''; ?>">

                <?php if ($isPopular): ?>
                <div class="jy-pricing-badge">
                    MOST POPULAR
                </div>
                <?php endif; ?>

                <div class="jy-pricing-head">
                    <div class="jy-pricing-icon">
                        &#11088;
                    </div>
                    <h3 class="jy-pricing-name"><?php echo htmlspecialchars($product->get('pro_name')); ?></h3>
                    <div class="jy-pricing-price">
                        <?php echo $product->get_readable_price($version->key); ?>
                    </div>
                    <p class="jy-pricing-tier"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></p>
                </div>

                <div class="jy-pricing-body">

                    <?php if ($product->get('pro_description')): ?>
                    <p class="jy-pricing-desc">
                        <?php echo $product->get('pro_description'); ?>
                    </p>
                    <?php endif; ?>

                    <div class="jy-pricing-spacer"></div>

                    <a href="<?php echo $product->get_url() . '?product_version_id=' . $version->key; ?>"
                       class="btn <?php echo $isPopular ? 'btn-primary' : 'btn-outline'; ?> jy-pricing-cta">
                        Choose This Plan &#8250;
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Help Box -->
        <div class="jy-pricing-help">
            <h4 class="jy-pricing-help-title">Need Help Choosing?</h4>
            <p class="jy-pricing-help-text">Not sure which plan is right for you? Our team is here to help you find the perfect solution for your needs.</p>
            <div class="jy-pricing-help-actions">
                <a href="/contact" class="btn btn-primary">Contact Us</a>
                <a href="/products" class="btn btn-outline">View All Products</a>
            </div>
        </div>

        <!-- Comparison Table -->
        <div class="jy-pricing-compare">
            <div class="jy-pricing-compare-head">
                <h3>Compare Plans</h3>
                <p class="jy-pricing-compare-sub">See what's included in each plan</p>
            </div>

            <div class="jy-pricing-table-wrap">
                <table class="styled-table jy-pricing-table">
                    <thead>
                        <tr>
                            <th>Features</th>
                            <?php foreach ($page_vars['tier_display_data'] as $item): ?>
                            <th><?php echo htmlspecialchars($item['product']->get('pro_name')); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Basic Access</strong></td>
                            <?php foreach ($page_vars['tier_display_data'] as $item): ?>
                            <td class="jy-pricing-cell jy-pricing-yes">&#10003;</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Premium Support</strong></td>
                            <?php
                            $supportIndex = 0;
                            foreach ($page_vars['tier_display_data'] as $item):
                                $supportIndex++;
                            ?>
                            <td class="jy-pricing-cell">
                                <?php if ($supportIndex >= 2): ?>
                                <span class="jy-pricing-yes">&#10003;</span>
                                <?php else: ?>
                                <span class="jy-pricing-no">&#8212;</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Advanced Analytics</strong></td>
                            <?php
                            $analyticsIndex = 0;
                            foreach ($page_vars['tier_display_data'] as $item):
                                $analyticsIndex++;
                            ?>
                            <td class="jy-pricing-cell">
                                <?php if ($analyticsIndex >= 3): ?>
                                <span class="jy-pricing-yes">&#10003;</span>
                                <?php else: ?>
                                <span class="jy-pricing-no">&#8212;</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

</div>
<?php
$page->public_footer(['track' => true]);
?>
