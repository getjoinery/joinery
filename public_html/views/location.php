<?php
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('location_logic.php', 'logic'));

    $page_vars = process_logic(location_logic($_GET, $_POST, $location, $params));
    $location  = $page_vars['location'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => $location->get('pag_title'),
    ]);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
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

<section class="content-section">
    <div class="container">
        <div style="max-width: 860px; margin: 0 auto;">

            <!-- Description -->
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; margin-bottom: 1.5rem;">
                <div style="display: flex; gap: 1.5rem; align-items: flex-start;">
                    <div style="font-size: 2.5rem; color: var(--color-primary); flex-shrink: 0;">&#128205;</div>
                    <div style="flex: 1;">
                        <?php echo $location->get('loc_description'); ?>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <?php if ($location->get('loc_address') || $location->get('loc_phone') || $location->get('loc_email')): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem;">
                    <h5 style="margin: 0; color: #fff;">Contact Information</h5>
                </div>
                <div style="padding: 1.5rem; display: flex; flex-wrap: wrap; gap: 1.5rem;">

                    <?php if ($location->get('loc_address')): ?>
                    <div style="flex: 1; min-width: 180px; display: flex; gap: 0.75rem; align-items: flex-start;">
                        <div style="font-size: 1.25rem; color: var(--color-primary); flex-shrink: 0; margin-top: 0.125rem;">&#128205;</div>
                        <div>
                            <h6 style="margin: 0 0 0.25rem;">Address</h6>
                            <p style="color: var(--color-muted); margin: 0;"><?php echo nl2br(htmlspecialchars($location->get('loc_address'))); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($location->get('loc_phone')): ?>
                    <div style="flex: 1; min-width: 180px; display: flex; gap: 0.75rem; align-items: flex-start;">
                        <div style="font-size: 1.25rem; color: var(--color-primary); flex-shrink: 0; margin-top: 0.125rem;">&#128222;</div>
                        <div>
                            <h6 style="margin: 0 0 0.25rem;">Phone</h6>
                            <p style="color: var(--color-muted); margin: 0;">
                                <a href="tel:<?php echo htmlspecialchars($location->get('loc_phone')); ?>" style="color: var(--color-muted); text-decoration: none;">
                                    <?php echo htmlspecialchars($location->get('loc_phone')); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($location->get('loc_email')): ?>
                    <div style="flex: 1; min-width: 180px; display: flex; gap: 0.75rem; align-items: flex-start;">
                        <div style="font-size: 1.25rem; color: var(--color-primary); flex-shrink: 0; margin-top: 0.125rem;">&#9993;</div>
                        <div>
                            <h6 style="margin: 0 0 0.25rem;">Email</h6>
                            <p style="color: var(--color-muted); margin: 0;">
                                <a href="mailto:<?php echo htmlspecialchars($location->get('loc_email')); ?>" style="color: var(--color-muted); text-decoration: none;">
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

<?php
    $page->public_footer(['track' => true]);
?>
