<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('pricing_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(pricing_logic($_GET, $_POST));

$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => 'Pricing',
]);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
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

<section class="content-section">
    <div class="container">

        <!-- Pricing Cards -->
        <div class="pricing grid-3" style="gap: 1.5rem; align-items: stretch;">
            <?php
            $cardIndex = 0;
            foreach ($page_vars['tier_display_data'] as $item):
                $tier      = $item['tier'];
                $product   = $item['product'];
                $version   = $item['version'];
                $cardIndex++;
                $isPopular = ($cardIndex == 2);
            ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; display: flex; flex-direction: column; position: relative;
                        <?php echo $isPopular ? 'border: 2px solid var(--color-primary); box-shadow: 0 4px 20px rgba(0,0,0,0.12);' : ''; ?>">

                <?php if ($isPopular): ?>
                <div style="background: var(--color-primary); color: #fff; text-align: center; padding: 0.375rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.05em;">
                    MOST POPULAR
                </div>
                <?php endif; ?>

                <div style="padding: 2rem; text-align: center; border-bottom: 1px solid var(--color-border, #eee);">
                    <div style="width: 64px; height: 64px; background: rgba(26,188,156,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 1.75rem;">
                        &#11088;
                    </div>
                    <h3 style="font-size: 1.25rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($product->get('pro_name')); ?></h3>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-primary); margin-bottom: 0.25rem;">
                        <?php echo $product->get_readable_price($version->key); ?>
                    </div>
                    <p style="color: var(--color-muted); font-size: 0.875rem; margin: 0;"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></p>
                </div>

                <div style="padding: 1.5rem 2rem; flex: 1; display: flex; flex-direction: column;">

                    <?php if ($product->get('pro_description')): ?>
                    <p style="color: var(--color-muted); font-size: 0.9rem; text-align: center; margin-bottom: 1.25rem;">
                        <?php echo $product->get('pro_description'); ?>
                    </p>
                    <?php endif; ?>

                    <div style="flex: 1; margin-bottom: 1.5rem;"></div>

                    <a href="<?php echo $product->get_url() . '?product_version_id=' . $version->key; ?>"
                       class="btn <?php echo $isPopular ? 'btn-primary' : 'btn-outline'; ?>"
                       style="display: block; text-align: center; font-weight: 600;">
                        Choose This Plan &#8250;
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Help Box -->
        <div style="max-width: 640px; margin: 3rem auto 0; background: var(--color-light, #f8f9fa); border-radius: 8px; padding: 2.5rem; text-align: center;">
            <h4 style="margin-bottom: 0.75rem;">Need Help Choosing?</h4>
            <p style="color: var(--color-muted); margin-bottom: 1.5rem;">Not sure which plan is right for you? Our team is here to help you find the perfect solution for your needs.</p>
            <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                <a href="/contact" class="btn btn-primary">Contact Us</a>
                <a href="/products" class="btn btn-outline">View All Products</a>
            </div>
        </div>

        <!-- Comparison Table -->
        <div style="margin-top: 3rem;">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <h3>Compare Plans</h3>
                <p style="color: var(--color-muted);">See what's included in each plan</p>
            </div>

            <div style="overflow-x: auto;">
                <table class="styled-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Features</th>
                            <?php foreach ($page_vars['tier_display_data'] as $item): ?>
                            <th style="text-align: center;"><?php echo htmlspecialchars($item['product']->get('pro_name')); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Basic Access</strong></td>
                            <?php foreach ($page_vars['tier_display_data'] as $item): ?>
                            <td style="text-align: center; color: #198754; font-weight: 700;">&#10003;</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td><strong>Premium Support</strong></td>
                            <?php
                            $supportIndex = 0;
                            foreach ($page_vars['tier_display_data'] as $item):
                                $supportIndex++;
                            ?>
                            <td style="text-align: center;">
                                <?php if ($supportIndex >= 2): ?>
                                <span style="color: #198754; font-weight: 700;">&#10003;</span>
                                <?php else: ?>
                                <span style="color: var(--color-muted);">&#8212;</span>
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
                            <td style="text-align: center;">
                                <?php if ($analyticsIndex >= 3): ?>
                                <span style="color: #198754; font-weight: 700;">&#10003;</span>
                                <?php else: ?>
                                <span style="color: var(--color-muted);">&#8212;</span>
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

<?php
$page->public_footer(['track' => true]);
?>
