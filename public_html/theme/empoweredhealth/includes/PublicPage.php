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

        // Get menu data
        $menu_data = $this->get_menu_data();
        $menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();
        $cart_data = isset($menu_data['cart']) ? $menu_data['cart'] : array('count' => 0, 'link' => '/cart');

        // Get contact info from settings
        $phone = $settings->get_setting('contact_phone', true, true);
        $email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true);
        $site_name = $settings->get_setting('site_name', true, true);
        $logo_url = $settings->get_setting('logo_link', true, true);

        ?>
<!DOCTYPE html>
<html lang="en" class="no-js">
    <head>
        <?php $options = parent::public_header_common($options); ?>
        <!-- Mobile Specific Meta -->
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <!-- Favicon-->
        <link rel="shortcut icon" href="/theme/empoweredhealth/assets/img/favicons/favicon.ico">
        <link rel="icon" type="image/png" href="/theme/empoweredhealth/assets/img/favicons/favicon.ico">
        <link rel="apple-touch-icon-precomposed" sizes="144x144" href="/theme/empoweredhealth/assets/img/favicons/apple-icon-144x144.png">
        <link rel="apple-touch-icon-precomposed" sizes="114x114" href="/theme/empoweredhealth/assets/img/favicons/apple-icon-114x114.png">
        <link rel="apple-touch-icon-precomposed" sizes="72x72" href="/theme/empoweredhealth/assets/img/favicons/apple-icon-72x72.png">
        <link rel="apple-touch-icon-precomposed" href="/theme/empoweredhealth/assets/img/favicons/apple-icon-57x57.png">

        <!-- Meta Description -->
        <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
        <!-- meta character set -->
        <meta charset="UTF-8">
        <!-- Site Title -->
        <title><?php echo htmlspecialchars($title); ?></title>

        <link href="https://fonts.googleapis.com/css?family=Poppins:100,200,400,300,500,600,700" rel="stylesheet">
            <!--
            CSS
            ============================================= -->
            <link rel="stylesheet" href="/theme/empoweredhealth/assets/css/empoweredhealth.min.css">

            <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js" type="text/javascript"></script>
            <script type="text/javascript" src="/theme/empoweredhealth/assets/js/jquery.validate-1.9.1.js"></script>

        <?php $this->global_includes_top($options); ?>
    </head>
    <body>
        <button type="button" id="mobile-nav-toggle"><i class="lnr lnr-menu"></i></button>
        <header id="header">
            <div class="header-top">
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-lg-6 col-sm-6 col-4 header-top-left">
                            <?php if ($phone): ?>
                            <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $phone); ?>"><span class="lnr lnr-phone-handset"></span> <span class="text"><span class="text"><?php echo htmlspecialchars($phone); ?></span></span></a>
                            <?php endif; ?>
                            <?php if ($email): ?>
                            <a href="mailto:<?php echo htmlspecialchars($email); ?>"><span class="lnr lnr-envelope"></span> <span class="text"><span class="text"><?php echo htmlspecialchars($email); ?></span></span></a>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-6 col-sm-6 col-8 header-top-right">
                            <?php if ($session->is_logged_in()): ?>
                                <a href="/profile" class="primary-btn text-uppercase">Profile</a>
                                <a href="/logout" class="primary-btn text-uppercase">Logout</a>
                            <?php else: ?>
                                <a href="/login" class="primary-btn text-uppercase">Register / Login</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="container main-menu">
                <div class="row align-items-center justify-content-between d-flex">
                  <div id="logo">
                    <a href="/">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" title="<?php echo htmlspecialchars($site_name); ?>">
                        <?php else: ?>
                            <img src="/theme/empoweredhealth/assets/img/logo-transp.png" alt="<?php echo htmlspecialchars($site_name); ?>" title="<?php echo htmlspecialchars($site_name); ?>">
                        <?php endif; ?>
                    </a>
                  </div>
                  <nav id="nav-menu-container">
                    <ul class="nav-menu">
                      <?php foreach ($menus as $menu): ?>
                          <li class="<?php echo (isset($menu['is_active']) && $menu['is_active']) ? 'menu-active' : ''; ?>">
                              <a href="<?php echo htmlspecialchars($menu['link']); ?>"><?php echo htmlspecialchars($menu['name']); ?></a>
                              <?php if (isset($menu['submenu']) && !empty($menu['submenu'])): ?>
                                  <ul>
                                      <?php foreach ($menu['submenu'] as $submenu): ?>
                                          <li><a href="<?php echo htmlspecialchars($submenu['link']); ?>"><?php echo htmlspecialchars($submenu['name']); ?></a></li>
                                      <?php endforeach; ?>
                                  </ul>
                              <?php endif; ?>
                          </li>
                      <?php endforeach; ?>
                    </ul>
                  </nav><!-- #nav-menu-container -->
                </div>
            </div>
          </header>

        <!-- Main Content -->
        <?php
    }

    public function public_footer($options = array()) {
        $settings = Globalvars::get_instance();
        $menu_data = $this->get_menu_data();
        $menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();

        // Get contact info from settings
        $phone = $settings->get_setting('contact_phone', true, true);
        $email = $settings->get_setting('contact_email', true, true) ?: $settings->get_setting('defaultemail', true, true);
        $address = $settings->get_setting('contact_address', true, true);
        $site_name = $settings->get_setting('site_name', true, true);

        // Social links
        $facebook = $settings->get_setting('social_facebook_link');
        $twitter = $settings->get_setting('social_twitter_link');
        $instagram = $settings->get_setting('social_instagram_link');
        ?>

        <footer class="footer-area section-gap">
            <div class="container">
                <div class="row">

                    <div class="col-lg-4  col-md-6">
                        <div class="single-footer-widget">
                            <h6 class="mb-20">Contact Us</h6>
                            <h3><?php echo $address ? htmlspecialchars($address) : 'Knoxville, TN USA'; ?></h3>
                            <?php if ($phone): ?>
                            <h3><?php echo htmlspecialchars($phone); ?> (Call or text)</h3>
                            <?php endif; ?>
                            <?php if ($email): ?>
                            <h3><?php echo htmlspecialchars($email); ?></h3>
                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="col-lg-2  col-md-6">

                        <div class="single-footer-widget mail-chimp">

                        </div>
                    </div>
                    <div class="col-lg-6  col-md-12">
                        <div class="single-footer-widget newsletter">
                            <h6>Newsletter</h6>
                            <p>Keep up-to-date on news and promotions from us.  We do not sell personal data.</p>
                            <div id="mc_embed_signup">
                                <form action="/lists" method="get" class="form-inline">

                                    <div class="form-group row" style="width: 100%">
                                        <div class="col-lg-8 col-md-12">
                                            <input name="email" placeholder="Your Email Address" onfocus="this.placeholder = ''" onblur="this.placeholder = 'Your Email Address '" required="" type="email">
                                        </div>

                                        <div class="col-lg-4 col-md-12">
                                            <button class="nw-btn primary-btn circle">Subscribe<span class="lnr lnr-arrow-right"></span></button>
                                        </div>
                                    </div>
                                    <div class="info"></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row footer-bottom d-flex justify-content-between">
                    <p class="col-lg-8 col-sm-12 footer-text m-0">
Copyright <?php echo htmlspecialchars($site_name); ?> &copy;<?php echo date('Y'); ?> All rights reserved</p>
                    <div class="col-lg-4 col-sm-12 footer-social">
                        <?php if ($facebook): ?>
                        <a href="<?php echo htmlspecialchars($facebook); ?>" alt="facebook link"><i class="fa fa-facebook"></i></a>
                        <?php endif; ?>
                        <?php if ($twitter): ?>
                        <a href="<?php echo htmlspecialchars($twitter); ?>" alt="twitter link"><i class="fa fa-twitter"></i></a>
                        <?php endif; ?>
                        <?php if ($instagram): ?>
                        <a href="<?php echo htmlspecialchars($instagram); ?>" alt="instagram link"><i class="fa fa-dribbble"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </footer>

        <script src="/theme/empoweredhealth/assets/js/all-js.min.js"></script>
        <script src="/theme/empoweredhealth/assets/js/main.js"></script>
    </body>
</html>
        <?php
    }
}
?>
