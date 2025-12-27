<?php
/**
 * Contact page for Linka Reference Theme
 *
 * @version 1.0.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => 'Contact - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));
?>

<!-- Start Page Title Area -->
<div class="page-title-area bg-9">
    <div class="container">
        <div class="page-title-content">
            <h2>Contact</h2>
            <ul>
                <li>
                    <a href="/">Home</a>
                </li>
                <li>Contact</li>
            </ul>
        </div>
    </div>
</div>
<!-- End Page Title Area -->

<!-- Start Contact Info Area -->
<section class="contact-info-area pt-100 pb-70">
    <div class="container">
        <div class="row">
            <?php if ($email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true)): ?>
            <div class="col-lg-3 col-sm-6">
                <div class="single-contact-info">
                    <i class="bx bx-envelope"></i>
                    <h3>Email Us:</h3>
                    <a href="mailto:<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
            <div class="col-lg-3 col-sm-6">
                <div class="single-contact-info">
                    <i class="bx bx-phone-call"></i>
                    <h3>Call Us:</h3>
                    <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $phone); ?>"><?php echo htmlspecialchars($phone); ?></a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($address = $settings->get_setting('contact_address', true, true)): ?>
            <div class="col-lg-3 col-sm-6">
                <div class="single-contact-info">
                    <i class="bx bx-location-plus"></i>
                    <h3>Location</h3>
                    <a href="#"><?php echo htmlspecialchars($address); ?></a>
                </div>
            </div>
            <?php endif; ?>

            <div class="col-lg-3 col-sm-6">
                <div class="single-contact-info">
                    <i class="bx bx-support"></i>
                    <h3>Live Chat</h3>
                    <a href="#">Available 24/7 for support</a>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Contact Info Area -->

<!-- Start Contact Area -->
<section class="main-contact-area pb-100">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="contact-wrap contact-pages mb-0">
                    <div class="contact-form">
                        <div class="section-title">
                            <h2>Drop us a message for any query</h2>
                        </div>
                        <form id="contactForm" action="/ajax/contact" method="POST">
                            <div class="row">
                                <div class="col-lg-6 col-sm-6">
                                    <div class="form-group">
                                        <input type="text" name="name" id="name" class="form-control" required data-error="Please enter your name" placeholder="Your Name">
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-6 col-sm-6">
                                    <div class="form-group">
                                        <input type="email" name="email" id="email" class="form-control" required data-error="Please enter your email" placeholder="Your Email">
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-6 col-sm-6">
                                    <div class="form-group">
                                        <input type="text" name="phone_number" id="phone_number" required data-error="Please enter your number" class="form-control" placeholder="Your Phone">
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-6 col-sm-6">
                                    <div class="form-group">
                                        <input type="text" name="msg_subject" id="msg_subject" class="form-control" required data-error="Please enter your subject" placeholder="Your Subject">
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-12 col-md-12">
                                    <div class="form-group">
                                        <textarea name="message" class="form-control" id="message" cols="30" rows="5" required data-error="Write your message" placeholder="Your Message"></textarea>
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-12 col-md-12">
                                    <button type="submit" class="default-btn btn-two">
                                        Send Message
                                    </button>
                                    <div id="msgSubmit" class="h3 text-center hidden"></div>
                                    <div class="clearfix"></div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Contact Area -->

<?php
$page->public_footer();
?>
