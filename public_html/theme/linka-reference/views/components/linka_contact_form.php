<?php
/**
 * Linka Contact Form Component
 *
 * Contact form with name, email, phone, subject, and message fields.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$title = $component_config['title'] ?? 'Drop us a message for any query';
$form_action = $component_config['form_action'] ?? '/ajax/contact';
$show_phone = $component_config['show_phone'] ?? true;
$show_subject = $component_config['show_subject'] ?? true;
$button_text = $component_config['button_text'] ?? 'Send Message';
$success_message = $component_config['success_message'] ?? 'Thank you for your message!';
?>

<section class="main-contact-area pb-100">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="contact-wrap contact-pages mb-0">
                    <div class="contact-form">
                        <?php if ($title): ?>
                            <div class="section-title">
                                <h2><?php echo htmlspecialchars($title); ?></h2>
                            </div>
                        <?php endif; ?>

                        <form id="contactForm" action="<?php echo htmlspecialchars($form_action); ?>" method="POST">
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

                                <?php if ($show_phone): ?>
                                    <div class="col-lg-6 col-sm-6">
                                        <div class="form-group">
                                            <input type="text" name="phone_number" id="phone_number" required data-error="Please enter your number" class="form-control" placeholder="Your Phone">
                                            <div class="help-block with-errors"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($show_subject): ?>
                                    <div class="col-lg-6 col-sm-6">
                                        <div class="form-group">
                                            <input type="text" name="msg_subject" id="msg_subject" class="form-control" required data-error="Please enter your subject" placeholder="Your Subject">
                                            <div class="help-block with-errors"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="col-lg-12 col-md-12">
                                    <div class="form-group">
                                        <textarea name="message" class="form-control" id="message" cols="30" rows="5" required data-error="Write your message" placeholder="Your Message"></textarea>
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>

                                <div class="col-lg-12 col-md-12">
                                    <button type="submit" class="default-btn btn-two">
                                        <?php echo htmlspecialchars($button_text); ?>
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
