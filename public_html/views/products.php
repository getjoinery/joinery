<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('products_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(products_logic($_GET, $_POST));
$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => 'Products',
]);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Our Products</h1>
                <span>Discover our amazing collection of products</span>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">Products</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">

        <!-- Products Grid -->
        <div class="grid-3" style="gap: 1.5rem;">
            <?php foreach ($page_vars['products'] as $product): ?>
            <div class="product-card" style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;">
                <a href="<?php echo $product->get_url(); ?>" style="display: block; overflow: hidden; text-decoration: none;">
                    <div style="width: 100%; height: 220px; background: linear-gradient(135deg, #f0f4f8 0%, #dde3ea 100%); display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #b0bac4; transition: transform 0.3s;">
                        &#128722;
                    </div>
                </a>
                <div style="padding: 1.25rem; flex: 1; display: flex; flex-direction: column;">
                    <h3 style="font-size: 1.0625rem; margin: 0 0 0.5rem;">
                        <a href="<?php echo $product->get_url(); ?>" style="color: var(--jy-color-text); text-decoration: none;">
                            <?php echo htmlspecialchars($product->get('pro_name')); ?>
                        </a>
                    </h3>
                    <?php if ($product->get('pro_description')): ?>
                    <p style="color: var(--jy-color-text-muted); font-size: 0.875rem; margin: 0 0 1rem; flex: 1;">
                        <?php
                        $desc = strip_tags($product->get('pro_description'));
                        echo strlen($desc) > 120 ? substr($desc, 0, 120) . '...' : $desc;
                        ?>
                    </p>
                    <?php else: ?>
                    <div style="flex: 1;"></div>
                    <?php endif; ?>
                    <div style="text-align: right;">
                        <a href="<?php echo $product->get_url(); ?>" class="btn btn-primary" style="font-size: 0.875rem;">View Details</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
        <div style="margin-top: 3rem; display: flex; justify-content: space-between; align-items: center; background: var(--jy-color-surface); border-radius: 8px; padding: 1.25rem 1.5rem;">
            <p style="margin: 0; color: var(--jy-color-text-muted); font-size: 0.9rem;">
                Showing <?php echo $page_vars['offsetdisp']; ?> to <?php echo $page_vars['numperpage'] + $page_vars['offset']; ?> of <?php echo $page_vars['numrecords']; ?> results
            </p>
            <div style="display: flex; gap: 0.5rem;">
                <?php if ($page_vars['pager']->is_valid_page('-1')): ?>
                <a class="btn btn-outline" href="<?php echo $page_vars['pager']->get_url('-1', ''); ?>">&#8592; Previous</a>
                <?php endif; ?>
                <?php if ($page_vars['pager']->is_valid_page('+1')): ?>
                <a class="btn btn-outline" href="<?php echo $page_vars['pager']->get_url('+1', ''); ?>">Next &#8594;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>

<style>
.product-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12) !important;
}
.product-card:hover img {
    transform: scale(1.04);
}
</style>

</div>
<?php
$page->public_footer(['track' => true]);
?>
