<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('booking_logic.php', 'logic'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $page_vars = process_logic(booking_logic(array_merge($_GET, $_POST, $params ?? [])));
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
        <div class="jy-narrow-sm">
            <div class="jy-booking-panel">
                <div class="jy-booking-icon">&#128197;</div>
                <h4 class="jy-booking-title">Booking Temporarily Unavailable</h4>
                <div class="alert alert-info jy-booking-alert">
                    Booking functionality is temporarily disabled while we review our calendar integration.
                </div>
                <p class="jy-booking-text">We apologize for any inconvenience. Please check back soon or contact us directly for scheduling assistance.</p>
                <div class="jy-booking-actions">
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
