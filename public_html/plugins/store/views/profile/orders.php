<?php
/**
 * Full order history sub-page, with the user's plugin license keys.
 *
 * @version 2.3
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('orders_profile_logic.php', 'logic', 'system', null, 'store', false));

$page_vars = process_logic(orders_profile_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => 'My Orders',
]);

$session = $page_vars['session'];
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>My Orders</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">Dashboard</a></li>
                        <li class="active">Orders</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="jy-tight">Order History</h6>
            </div>
            <div class="card-body">
                <?php if (empty($page_vars['orders']) || $page_vars['numorders'] == 0): ?>
                    <p class="muted jy-tight">No orders found.</p>
                <?php else: ?>
                    <?php $i = 0; foreach ($page_vars['orders'] as $order): ?>
                    <div class="jy-orders-row<?php echo $i > 0 ? ' is-divided' : ''; ?>">
                        <div>
                            <p class="jy-orders-num">Order #<?php echo htmlspecialchars($order->key); ?></p>
                            <p class="muted text-sm jy-tight">$<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
                        </div>
                        <div class="text-end">
                            <p class="muted text-sm jy-tight">
                                <?php echo $order->get_local('ord_timestamp', 'M j, Y'); ?>
                            </p>
                        </div>
                    </div>
                    <?php $i++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($page_vars['numorders'] > 0): ?>
            <div class="card-footer muted text-sm d-flex justify-content-between align-items-center">
                <span><?php echo $page_vars['numorders']; ?> order<?php echo $page_vars['numorders'] != 1 ? 's' : ''; ?></span>
                <?php
                $pager = $page_vars['pager'];
                if ($pager->num_records() > $pager->num_per_page()):
                ?>
                <div class="pagination">
                    <?php if ($pager->is_valid_page('-1')): ?>
                        <a href="<?php echo htmlspecialchars($pager->get_url('-1')); ?>" class="page-link">&laquo;</a>
                    <?php else: ?>
                        <span class="page-link disabled">&laquo;</span>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= $pager->total_pages(); $p++): ?>
                        <?php if ($p == $pager->current_page()): ?>
                            <span class="page-link active"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($pager->get_url($p)); ?>" class="page-link"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($pager->is_valid_page('+1')): ?>
                        <a href="<?php echo htmlspecialchars($pager->get_url('+1')); ?>" class="page-link">&raquo;</a>
                    <?php else: ?>
                        <span class="page-link disabled">&raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (isset($page_vars['ownerships']) && $page_vars['ownerships']->count() > 0): ?>
        <div class="card">
            <div class="card-header">
                <h6 class="jy-tight">What you own</h6>
            </div>
            <div class="card-body">
                <p class="muted text-sm">These are yours for good — you will not be charged for them again.</p>
                <?php $k = 0; foreach ($page_vars['ownerships'] as $ownership): ?>
                <div class="jy-orders-row<?php echo $k > 0 ? ' is-divided' : ''; ?>">
                    <div>
                        <p class="jy-orders-num"><?php
                            $owned_tag = $ownership->get('own_tag');
                            if ($owned_tag === Ownership::TAG_ALL) {
                                echo 'All products';
                            } else if (!empty($page_vars['ownership_labels'][$owned_tag])) {
                                echo htmlspecialchars($page_vars['ownership_labels'][$owned_tag]);
                            } else {
                                echo htmlspecialchars($owned_tag);
                            }
                        ?></p>
                        <?php if ($ownership->get('own_license_key')): ?>
                        <p class="muted text-sm jy-tight"><code><?php echo htmlspecialchars($ownership->get('own_license_key')); ?></code></p>
                        <p class="muted text-sm jy-tight">Covers one production instance; staging and development copies are included.</p>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <p class="muted text-sm jy-tight">
                            <?php echo $ownership->get_local('own_create_time', 'M j, Y'); ?>
                        </p>
                    </div>
                </div>
                <?php $k++; endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
