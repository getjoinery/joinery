<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPage extends PublicPageBase {

    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped',
            'header' => 'table-light'
        ];
    }

    public function public_header($options = array()) {
        $session = SessionControl::get_instance();
        $settings = Globalvars::get_instance();

        $title = isset($options['title']) ? $options['title'] : $settings->get_setting('site_name', true, true);
        $showheader = isset($options['showheader']) ? $options['showheader'] : true;
        $description = isset($options['description']) ? $options['description'] : $settings->get_setting('site_description', true, true);

        ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta name="description" content="<?php echo htmlspecialchars($description); ?>">

        <!-- Bootstrap CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/bootstrap.min.css">
        <!-- Owl Carousel CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/owl.theme.default.min.css">
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/owl.carousel.min.css">
        <!-- Magnific Popup CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/magnific-popup.min.css">
        <!-- Animate CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/animate.min.css">
        <!-- Boxicons CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/boxicons.min.css">
        <!-- Flaticon CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/flaticon.css">
        <!-- MeanMenu CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/meanmenu.min.css">
        <!-- Nice Select CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/nice-select.min.css">
        <!-- Phillyzouk Theme CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/style.css">
        <!-- Responsive CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/responsive.css">
        <!-- Joinery Custom CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/joinery-custom.css">

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/theme/phillyzouk/assets/images/favicon.png">

        <title><?php echo htmlspecialchars($title); ?></title>

        <?php $this->global_includes_top($options); ?>
    </head>
    <body>

        <!-- Start Navbar Area -->
        <div class="nav-area">
            <div class="navbar-area">
                <!-- Menu For Mobile Device -->
                <div class="mobile-nav">
                    <a href="/" class="logo">
                        <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
                        <?php else: ?>
                            <span class="site-name-logo"><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Menu For Desktop Device -->
                <div class="main-nav">
                    <nav class="navbar navbar-expand-md">
                        <div class="container-fluid">
                            <a class="navbar-brand" href="/">
                                <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
                                <?php else: ?>
                                    <span class="site-name-logo"><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></span>
                                <?php endif; ?>
                            </a>

                            <div class="collapse navbar-collapse mean-menu">
                                <ul class="navbar-nav m-auto">
                                    <?php
                                    // Get menu data from public menu model
                                    $menu_data = $this->get_menu_data();
                                    $menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();

                                    foreach ($menus as $menu) {
                                        $is_active = isset($menu['is_active']) && $menu['is_active'] ? 'active' : '';
                                        $has_submenu = isset($menu['submenu']) && !empty($menu['submenu']);

                                        echo '<li class="nav-item ' . htmlspecialchars($is_active) . '">';
                                        echo '<a href="' . htmlspecialchars($menu['link']) . '" class="nav-link ' . htmlspecialchars($is_active) . '">' .
                                             htmlspecialchars($menu['name']);

                                        if ($has_submenu) {
                                            echo '<i class="bx bx-chevron-down"></i>';
                                        }
                                        echo '</a>';

                                        if ($has_submenu) {
                                            echo '<ul class="dropdown-menu">';
                                            foreach ($menu['submenu'] as $submenu) {
                                                $submenu_active = isset($submenu['is_active']) && $submenu['is_active'] ? 'active' : '';
                                                echo '<li class="nav-item ' . htmlspecialchars($submenu_active) . '">';
                                                echo '<a href="' . htmlspecialchars($submenu['link']) . '" class="nav-link ' . htmlspecialchars($submenu_active) . '">' .
                                                     htmlspecialchars($submenu['name']) . '</a>';
                                                echo '</li>';
                                            }
                                            echo '</ul>';
                                        }
                                        echo '</li>';
                                    }
                                    ?>
                                </ul>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
        <!-- End Navbar Area -->

        <?php
    }

    public function public_footer($options = array()) {
        $settings = Globalvars::get_instance();
        ?>

        <!-- Start Footer Top Area -->
        <footer class="footer-top-area pt-100 pb-70">
            <div class="container">
                <div class="row">
                    <div class="col-lg-6 col-md-6">
                        <div class="single-widget">
                            <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
                                <a href="/">
                                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
                                </a>
                            <?php else: ?>
                                <h3><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></h3>
                            <?php endif; ?>
                            <p><?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?></p>
                            <div class="social-area">
                                <ul>
                                    <?php if ($settings->get_setting('social_facebook_link')): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($settings->get_setting('social_facebook_link')); ?>" target="_blank">
                                            <i class="bx bxl-facebook"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if ($settings->get_setting('social_twitter_link')): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($settings->get_setting('social_twitter_link')); ?>" target="_blank">
                                            <i class="bx bxl-twitter"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if ($settings->get_setting('social_linkedin_link')): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($settings->get_setting('social_linkedin_link')); ?>" target="_blank">
                                            <i class="bx bxl-linkedin"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if ($settings->get_setting('social_youtube_link')): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($settings->get_setting('social_youtube_link')); ?>" target="_blank">
                                            <i class="bx bxl-youtube"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if ($settings->get_setting('social_instagram_link')): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($settings->get_setting('social_instagram_link')); ?>" target="_blank">
                                            <i class="bx bxl-instagram"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6">
                        <div class="single-widget contact">
                            <h3>Get In Touch</h3>
                            <ul>
                                <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
                                <li>
                                    <i class="bx bx-phone-call"></i>
                                    <span>Phone:</span>
                                    <?php echo htmlspecialchars($phone); ?>
                                </li>
                                <?php endif; ?>

                                <?php if ($email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true)): ?>
                                <li>
                                    <i class="bx bx-envelope"></i>
                                    <span>Email:</span>
                                    <a href="mailto:<?php echo htmlspecialchars($email); ?>">
                                        <?php echo htmlspecialchars($email); ?>
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php if ($address = $settings->get_setting('contact_address', true, true)): ?>
                                <li>
                                    <i class="bx bx-location-plus"></i>
                                    <span>Address:</span>
                                    <?php echo htmlspecialchars($address); ?>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
        <!-- End Footer Top Area -->

        <!-- Start Footer Bottom Area -->
        <footer class="footer-bottom-area">
            <div class="container">
                <div class="copy-right">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>. All rights reserved.</p>
                </div>
            </div>
        </footer>
        <!-- End Footer Bottom Area -->

        <!-- jQuery -->
        <script src="/theme/phillyzouk/assets/js/jquery.min.js"></script>
        <!-- Bootstrap JS -->
        <script src="/theme/phillyzouk/assets/js/bootstrap.bundle.min.js"></script>
        <!-- OWL Carousel JS -->
        <script src="/theme/phillyzouk/assets/js/owl.carousel.min.js"></script>
        <!-- Magnific Popup JS -->
        <script src="/theme/phillyzouk/assets/js/magnific-popup.min.js"></script>
        <!-- WOW JS -->
        <script src="/theme/phillyzouk/assets/js/wow.min.js"></script>
        <!-- MeanMenu JS -->
        <script src="/theme/phillyzouk/assets/js/meanmenu.min.js"></script>
        <!-- Nice Select JS -->
        <script src="/theme/phillyzouk/assets/js/nice-select.min.js"></script>
        <!-- Form Validator JS -->
        <script src="/theme/phillyzouk/assets/js/form-validator.min.js"></script>
        <!-- AjaxChimp JS -->
        <script src="/theme/phillyzouk/assets/js/ajaxchimp.min.js"></script>
        <!-- Contact Form JS -->
        <script src="/theme/phillyzouk/assets/js/contact-form-script.js"></script>
        <!-- Joinery Validator -->
        <script src="/assets/js/joinery-validate.js"></script>
        <!-- Custom JS -->
        <script src="/theme/phillyzouk/assets/js/custom.js?v=2"></script>
    </body>
</html>
        <?php
    }
}
?>
