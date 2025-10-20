<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_product_logic.php'));

	$page_vars = process_logic(admin_product_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'products-list',
		'page_title' => 'Product',
		'readable_title' => $product->get('pro_name'),
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products',
			$product->get('pro_name')=>'',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Two Column Layout -->
	<div class="row g-3 mb-3">
		<!-- LEFT COLUMN: Product Information -->
		<div class="col-xxl-6">

			<!-- Product Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-box me-2"></span>Product Information</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Product Name:</td>
								<td class="p-1 text-600"><strong><?php echo htmlspecialchars($product->get('pro_name')); ?></strong></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Product Link:</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($product->get_url()); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($product->get_url())); ?></a></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Price:</td>
								<td class="p-1 text-600"><strong><?php echo $currency_symbol . htmlspecialchars($product->get_readable_price()); ?></strong></td>
							</tr>
							<?php if($product->get('pro_max_purchase_count') > 0): ?>
								<?php $remaining = $product->get('pro_max_purchase_count') - $product->get_number_purchased(); ?>
							<tr>
								<td class="p-1" style="width: 35%;">Items Available:</td>
								<td class="p-1 text-600"><?php echo $product->get('pro_max_purchase_count'); ?> (<?php echo $remaining; ?> remaining)</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Max Per Cart:</td>
								<td class="p-1 text-600"><?php echo $product->get('pro_max_cart_count'); ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Purchase Expiration:</td>
								<td class="p-1 text-600">
									<?php if($product->get('pro_expires')): ?>
										<?php echo $product->get('pro_expires'); ?> days
									<?php else: ?>
										Unlimited
									<?php endif; ?>
								</td>
							</tr>
							<?php if($event): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Registration For:</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_event?evt_event_id=<?php echo $event->key; ?>">
										<?php echo htmlspecialchars($event->get('evt_name')); ?>
									</a>
									<?php if($event->get('evt_start_time')): ?>
										<br><small class="text-600"><?php echo LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y'); ?></small>
									<?php endif; ?>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($product_group): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Product Group:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($product_group->get('prg_name')); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($product->get('pro_digital_link')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Digital Link:</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($product->get('pro_digital_link')); ?>" target="_blank"><?php echo htmlspecialchars($product->get('pro_digital_link')); ?></a></td>
							</tr>
							<?php endif; ?>
							<?php if($product->get('pro_description')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Description:</td>
								<td class="p-1 text-600"><?php echo $product->get('pro_description'); ?></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Product Requirements Card -->
			<?php if(!empty($requirements) || count($instances) > 0): ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-clipboard-list me-2"></span>Purchase Requirements</h6>
				</div>
				<div class="card-body">
					<?php if(!empty($requirements)): ?>
					<div class="mb-2">
						<strong class="fs-9">Standard Info Collected:</strong>
						<div class="fs-10 text-600 mt-1"><?php echo implode(', ', $requirements); ?></div>
					</div>
					<?php endif; ?>
					<?php if(count($instances) > 0): ?>
					<div>
						<strong class="fs-9">Additional Info Collected:</strong>
						<div class="fs-10 text-600 mt-1">
							<?php
							$instance_titles = array();
							foreach($instances as $instance){
								$requirement = new ProductRequirement($instance->get('pri_prq_product_requirement_id'), TRUE);
								$instance_titles[] = htmlspecialchars($requirement->get('prq_title'));
							}
							echo implode(', ', $instance_titles);
							?>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

		</div>

		<!-- RIGHT COLUMN: Pricing -->
		<div class="col-xxl-6">

			<!-- Product Versions/Prices Card -->
			<div class="card">
				<div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
					<h6 class="mb-0"><span class="fas fa-dollar-sign me-2"></span>Price Versions</h6>
					<a href="/admin/admin_product_version_edit?product_id=<?php echo $product->key; ?>" class="btn btn-sm btn-falcon-default">
						<span class="fas fa-plus me-1"></span>Add Price
					</a>
				</div>
				<div class="card-body">
					<?php
					$versions = $product->get_product_versions(false);
					if(count($versions) > 0):
					?>
					<div class="list-group list-group-flush">
						<?php foreach ($versions as $version): ?>
						<div class="list-group-item px-0 d-flex justify-content-between align-items-center <?php echo !$version->get('prv_status') ? 'text-decoration-line-through opacity-50' : ''; ?>">
							<div>
								<div class="fs-9 fw-semi-bold">
									<?php echo htmlspecialchars($version->get('prv_version_name')); ?>
									<?php if(!$version->get('prv_status')): ?>
										<span class="badge badge-subtle-secondary ms-2">Inactive</span>
									<?php endif; ?>
								</div>
								<div class="fs-10 text-600"><?php echo $currency_symbol . number_format($version->get('prv_version_price'), 2); ?></div>
							</div>
							<div>
								<a href="/admin/admin_product_version_edit?product_id=<?php echo $product->key; ?>&product_version_id=<?php echo $version->key; ?>" class="btn btn-link btn-sm">Edit</a>
								<?php if($version->get('prv_status')): ?>
									<a href="/admin/admin_product_version_edit?product_id=<?php echo $product->key; ?>&product_version_id=<?php echo $version->key; ?>&action=remove_version" class="btn btn-link btn-sm">Deactivate</a>
								<?php else: ?>
									<a href="/admin/admin_product_version_edit?product_id=<?php echo $product->key; ?>&product_version_id=<?php echo $version->key; ?>&action=activate_version" class="btn btn-link btn-sm">Activate</a>
								<?php endif; ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php else: ?>
					<p class="text-600 mb-0">No price versions defined.</p>
					<?php endif; ?>
				</div>
			</div>

		</div>
	</div>

	<?php

	$page->admin_footer();
?>

