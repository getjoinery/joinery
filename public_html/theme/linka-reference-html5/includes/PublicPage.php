<?php
/**
 * PublicPage for Linka Reference HTML5 Theme
 *
 * Zero-dependency HTML5 version — no jQuery, no Bootstrap JS.
 *
 * @version 1.0.0
 */
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
        <?php $options = parent::public_header_common($options); ?>
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta name="description" content="<?php echo htmlspecialchars($description); ?>">

        <!-- Bootstrap CSS (grid and base styles only) -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/bootstrap.min.css">
        <!-- Owl Carousel CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/owl.theme.default.min.css">
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/owl.carousel.min.css">
        <!-- Animate CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/animate.min.css">
        <!-- Boxicons CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/boxicons.min.css">
        <!-- Flaticon CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/flaticon.css">
        <!-- Linka Theme CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/style.css">
        <!-- Responsive CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/responsive.css">
        <!-- Joinery Custom CSS -->
        <link rel="stylesheet" href="/theme/linka-reference-html5/assets/css/joinery-custom.css?v=3">

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/theme/linka-reference-html5/assets/images/favicon.png">

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

                                        echo '<li class="nav-item">';
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
                                                echo '<li class="nav-item">';
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

                                <!-- Start Other Option -->
                                <div class="others-option">
                                    <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
                                    <div class="call-us">
                                        <i class="bx bx-phone-call"></i>
                                        <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $phone); ?>"><?php echo htmlspecialchars($phone); ?></a>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    // Get cart data from menu_data
                                    $cart_data = isset($menu_data['cart']) ? $menu_data['cart'] : array('count' => 0, 'link' => '/cart');
                                    ?>
                                    <div class="user-menu">
                                        <a href="<?php echo htmlspecialchars($cart_data['link']); ?>" class="user-link cart-link">
                                            <i class="bx bx-cart"></i>
                                            <?php if ($cart_data['count'] > 0): ?>
                                            <span class="cart-count"><?php echo intval($cart_data['count']); ?></span>
                                            <?php endif; ?>
                                        </a>
                                        <?php if ($session->is_logged_in()): ?>
                                            <a href="/profile" class="user-link">
                                                <i class="bx bx-user"></i>
                                                <span>Profile</span>
                                            </a>
                                            <a href="/logout" class="user-link">
                                                <i class="bx bx-log-out"></i>
                                                <span>Logout</span>
                                            </a>
                                        <?php else: ?>
                                            <a href="/login" class="user-link">
                                                <i class="bx bx-log-in"></i>
                                                <span>Login</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- End Other Option -->
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
                    <div class="col-lg-3 col-md-6">
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

                    <div class="col-lg-3 col-md-6">
                        <div class="single-widget">
                            <h3>Important Links</h3>
                            <ul>
                                <li>
                                    <a href="/">
                                        <i class="bx bx-chevrons-right"></i>
                                        Home
                                    </a>
                                </li>
                                <li>
                                    <a href="/about">
                                        <i class="bx bx-chevrons-right"></i>
                                        About
                                    </a>
                                </li>
                                <li>
                                    <a href="/blog">
                                        <i class="bx bx-chevrons-right"></i>
                                        Blog
                                    </a>
                                </li>
                                <li>
                                    <a href="/contact">
                                        <i class="bx bx-chevrons-right"></i>
                                        Contact
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="single-widget">
                            <h3>Categories</h3>
                            <ul>
                                <li>
                                    <a href="/blog">
                                        <i class="bx bx-chevrons-right"></i>
                                        Blog
                                    </a>
                                </li>
                                <li>
                                    <a href="#">
                                        <i class="bx bx-chevrons-right"></i>
                                        Gallery
                                    </a>
                                </li>
                                <li>
                                    <a href="#">
                                        <i class="bx bx-chevrons-right"></i>
                                        Videos
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="single-widget contact">
                            <h3>Get In Touch</h3>
                            <ul>
                                <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
                                <li>
                                    <i class="bx bx-phone-call"></i>
                                    <span>Hotline:</span>
                                    <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $phone); ?>">
                                        <?php echo htmlspecialchars($phone); ?>
                                    </a>
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

        <!-- Start Go Top Area -->
        <div class="go-top">
            <i class='bx bx-chevrons-up'></i>
            <i class='bx bx-chevrons-up'></i>
        </div>
        <!-- End Go Top Area -->

        <script src="/assets/js/joinery-validate.js"></script>
        <script src="/theme/linka-reference-html5/assets/js/custom.js?v=2"></script>
    </body>
</html>
        <?php
    }
}
?>
