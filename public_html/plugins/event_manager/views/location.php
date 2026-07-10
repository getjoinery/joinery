<?php
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('location_logic.php', 'logic', 'system', null, 'event_manager', false));

    $page_vars = process_logic(location_logic(array_merge($_GET, $_POST, $params ?? [])));
    $location  = $page_vars['location'];

    $page = new PublicPage();
    $location_header_options = [
        'is_valid_page'    => $is_valid_page,
        'title'            => $location->get('loc_name'),
        'entity_type'      => 'location',
        'entity_body_html' => $location->get('loc_description'),
    ];
    if ($location->get('loc_short_description')) {
        $location_header_options['meta_description'] = $location->get('loc_short_description');
    }
    if (method_exists($location, 'get_picture_link') && $location->get_picture_link('og_image')) {
        $location_header_options['preview_image_url'] = $location->get_picture_link('og_image');
    }
    $page->public_header($location_header_options);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($location->get('loc_name')); ?></h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($location->get('loc_name')); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-loc-wrap">

            <!-- Description -->
            <div class="jy-loc-card">
                <div class="jy-loc-desc-row">
                    <div class="jy-loc-desc-icon">&#128205;</div>
                    <div class="jy-loc-grow">
                        <?php echo $location->get('loc_description'); ?>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <?php if ($location->get('loc_address') || $location->get('loc_phone') || $location->get('loc_email')): ?>
            <div class="jy-loc-contact">
                <div class="jy-loc-contact-head">
                    <h5 class="jy-loc-contact-title">Contact Information</h5>
                </div>
                <div class="jy-loc-contact-body">

                    <?php if ($location->get('loc_address')): ?>
                    <div class="jy-loc-item">
                        <div class="jy-loc-item-icon">&#128205;</div>
                        <div>
                            <h6 class="jy-loc-item-title">Address</h6>
                            <p class="jy-muted jy-tight"><?php echo nl2br(htmlspecialchars($location->get('loc_address'))); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($location->get('loc_phone')): ?>
                    <div class="jy-loc-item">
                        <div class="jy-loc-item-icon">&#128222;</div>
                        <div>
                            <h6 class="jy-loc-item-title">Phone</h6>
                            <p class="jy-muted jy-tight">
                                <a href="tel:<?php echo htmlspecialchars($location->get('loc_phone')); ?>" class="jy-loc-link">
                                    <?php echo htmlspecialchars($location->get('loc_phone')); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($location->get('loc_email')): ?>
                    <div class="jy-loc-item">
                        <div class="jy-loc-item-icon">&#9993;</div>
                        <div>
                            <h6 class="jy-loc-item-title">Email</h6>
                            <p class="jy-muted jy-tight">
                                <a href="mailto:<?php echo htmlspecialchars($location->get('loc_email')); ?>" class="jy-loc-link">
                                    <?php echo htmlspecialchars($location->get('loc_email')); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</section>

</div>
<?php
    $page->public_footer(['track' => true]);
?>
