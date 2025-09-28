<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Check permissions
$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();

// Get all tiers
$tiers = MultiSubscriptionTier::GetAllActive();

// Display page
$page->admin_header(array(
    'menu-id' => 'subscription-tiers',
    'page_title' => 'Subscription Tiers',
    'readable_title' => 'Subscription Tier Management',
    'breadcrumbs' => array(
        'Subscription Tiers' => ''
    )
));

// Set up alt links for adding new tier
$altlinks = array('Add Subscription Tier' => '/admin/admin_subscription_tier_edit');
$headers = array('ID', 'Level', 'Name', 'Display Name', 'Members', 'Actions');
?>

<div class="container-fluid">
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Tier saved successfully!</div>
    <?php endif; ?>

    <?php
    // Display table with altlinks
    $table_options = array(
        'altlinks' => $altlinks,
        'title' => 'Subscription Tiers',
        'search_on' => FALSE
    );
    $page->tableheader($headers, $table_options, null);

    foreach ($tiers as $tier) {
        // Count members
        $group = new Group($tier->get('sbt_grp_group_id'), TRUE);
        $members = $group->get_member_list();
        $member_count = count($members);

        $actions_html = '<a href="admin_subscription_tier_edit.php?id=' . $tier->key . '" class="btn btn-sm btn-primary">Edit</a> ';
        $actions_html .= '<a href="admin_subscription_tier_members.php?id=' . $tier->key . '" class="btn btn-sm btn-info">View Members</a>';

        $row = array(
            $tier->key,
            $tier->get('sbt_tier_level'),
            htmlspecialchars($tier->get('sbt_name')),
            htmlspecialchars($tier->get('sbt_display_name')),
            $member_count,
            $actions_html
        );

        $page->disprow($row);
    }

    $page->endtable(null);
    ?>

    <!-- Products Using Tiers -->
    <div class="card mt-4">
        <div class="card-header">
            <h3>Products Granting Tiers</h3>
        </div>
        <div class="card-body">
            <?php
            // Get all products that have subscription tiers
            $products_with_tiers = [];

            foreach ($tiers as $tier) {
                // Only get products that have this specific tier ID set
                $dbconnector = DbConnector::get_instance();
                $dblink = $dbconnector->get_db_link();

                $sql = "SELECT pro_product_id, pro_name
                        FROM pro_products
                        WHERE pro_sbt_subscription_tier_id = ?
                        AND pro_delete_time IS NULL
                        ORDER BY pro_name ASC";

                $q = $dblink->prepare($sql);
                $q->execute([$tier->key]);

                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $products_with_tiers[] = [
                        'pro_product_id' => $row['pro_product_id'],
                        'pro_name' => $row['pro_name'],
                        'sbt_display_name' => $tier->get('sbt_display_name'),
                        'sbt_tier_level' => $tier->get('sbt_tier_level')
                    ];
                }
            }

            // Sort products by tier level and name
            usort($products_with_tiers, function($a, $b) {
                if ($a['sbt_tier_level'] == $b['sbt_tier_level']) {
                    return strcmp($a['pro_name'], $b['pro_name']);
                }
                return $a['sbt_tier_level'] - $b['sbt_tier_level'];
            });
            ?>

            <?php if (empty($products_with_tiers)): ?>
                <p class="text-muted">No products have subscription tiers assigned yet. Edit a product to assign a subscription tier.</p>
            <?php else: ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Grants Tier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products_with_tiers as $product): ?>
                            <tr>
                                <td><?php echo $product['pro_product_id']; ?></td>
                                <td><?php echo htmlspecialchars($product['pro_name']); ?></td>
                                <td><?php echo htmlspecialchars($product['sbt_display_name']); ?></td>
                                <td>
                                    <a href="admin_product_edit.php?pro_product_id=<?php echo $product['pro_product_id']; ?>"
                                       class="btn btn-sm btn-primary">Edit Product</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page->admin_footer();
?>