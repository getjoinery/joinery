<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('products_logic.php', 'logic', 'system', null, 'store', false));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(products_logic(array_merge($_GET, $_POST, $params ?? [])));
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

<section class="jy-content-section">
    <div class="jy-container">

        <!-- Products Grid -->
        <div class="grid-3 jy-products-grid">
            <?php foreach ($page_vars['products'] as $product): ?>
            <div class="product-card jy-products-card">
                <a href="<?php echo $product->get_url(); ?>" class="jy-products-imglink">
                    <div class="jy-products-img">
                        &#128722;
                    </div>
                </a>
                <div class="jy-products-body">
                    <h3 class="jy-products-name">
                        <a href="<?php echo $product->get_url(); ?>" class="jy-products-namelink">
                            <?php echo htmlspecialchars($product->get('pro_name')); ?>
                        </a>
                    </h3>
                    <?php if ($product->get('pro_description')): ?>
                    <p class="jy-products-desc">
                        <?php
                        $desc = strip_tags($product->get('pro_description'));
                        echo strlen($desc) > 120 ? substr($desc, 0, 120) . '...' : $desc;
                        ?>
                    </p>
                    <?php else: ?>
                    <div class="jy-grow"></div>
                    <?php endif; ?>
                    <div class="text-end">
                        <a href="<?php echo $product->get_url(); ?>" class="btn btn-primary jy-fs-sm">View Details</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
        <div class="jy-products-pager">
            <p class="jy-products-pager-info">
                Showing <?php echo $page_vars['offsetdisp']; ?> to <?php echo $page_vars['numperpage'] + $page_vars['offset']; ?> of <?php echo $page_vars['numrecords']; ?> results
            </p>
            <div class="d-flex gap-2">
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

</div>
<?php
$page->public_footer(['track' => true]);
?>
