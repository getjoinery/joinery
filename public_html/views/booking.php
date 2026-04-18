<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('booking_logic.php', 'logic'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $page_vars = process_logic(booking_logic($_GET, $_POST));
    $booking_type = $page_vars['booking_type'];
    $client_user  = $page_vars['client_user'];

    $page = new PublicPage();
    $page->public_header([
        'title'    => 'Book an appointment',
        'banner'   => 'Book',
        'submenu'  => 'Book',
    ]);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Book an Appointment</h1>
                <span>Schedule your appointment with our convenient booking system</span>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">Book</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="jy-content-section">
    <div class="jy-container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2.5rem; text-align: center;">
                <div style="font-size: 3rem; color: #f6c23e; margin-bottom: 1rem;">&#128197;</div>
                <h4 style="margin-bottom: 1rem;">Booking Temporarily Unavailable</h4>
                <div class="alert alert-info" style="margin-bottom: 1.5rem; text-align: left;">
                    Booking functionality is temporarily disabled while we review our calendar integration.
                </div>
                <p style="color: var(--jy-color-text-muted); margin-bottom: 2rem;">We apologize for any inconvenience. Please check back soon or contact us directly for scheduling assistance.</p>
                <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                    <a href="/contact" class="btn btn-primary">Contact Us</a>
                    <a href="/" class="btn btn-outline">Back to Home</a>
                </div>
            </div>
        </div>
    </div>
</section>

</div>
<?php
    $page->public_footer(['track' => true]);
?>
