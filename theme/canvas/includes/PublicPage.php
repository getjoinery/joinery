<?php
// PathHelper is always available - no need to require it
PathHelper::requireOnce('includes/PublicPageFalcon.php');
PathHelper::requireOnce('includes/Pager.php');

class PublicPage extends PublicPageFalcon {
    
    public function public_header($options = array()) {
        $session = SessionControl::get_instance();
        $settings = Globalvars::get_instance();
        
        // Set defaults
        $title = isset($options['title']) ? $options['title'] : 'Canvas';
        $showheader = isset($options['showheader']) ? $options['showheader'] : true;
        $description = isset($options['description']) ? $options['description'] : 'Canvas Theme for Joinery';
        
        ?>
<!DOCTYPE html>
<html dir="ltr" lang="en-US">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
    
    <!-- Font Imports -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital@0;1&display=swap" rel="stylesheet">
    
    <!-- Core Style -->
    <link rel="stylesheet" href="/theme/canvas/assets/css/style.css">
    
    <!-- Font Icons -->
    <link rel="stylesheet" href="/theme/canvas/assets/css/font-icons.css">
    
    <!-- Plugins/Components CSS -->
    <link rel="stylesheet" href="/theme/canvas/assets/css/swiper.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/theme/canvas/assets/css/custom.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <title><?php echo htmlspecialchars($title); ?></title>
</head>

<body class="stretched">
    <!-- Document Wrapper -->
    <div id="wrapper">
        
        <?php if($showheader): ?>
        <!-- Header -->
        <header id="header" class="full-header">
            <div id="header-wrap">
                <div class="container">
                    <div class="header-row">
                        
                        <!-- Logo -->
                        <div id="logo">
                            <a href="/">
                                <img class="logo-default" srcset="/theme/canvas/assets/images/logo.png, /theme/canvas/assets/images/logo@2x.png 2x" src="/theme/canvas/assets/images/logo@2x.png" alt="Logo">
                                <img class="logo-dark" srcset="/theme/canvas/assets/images/logo-dark.png, /theme/canvas/assets/images/logo-dark@2x.png 2x" src="/theme/canvas/assets/images/logo-dark@2x.png" alt="Logo">
                            </a>
                        </div>
                        
                        <div class="header-misc">
                            <!-- Top Search -->
                            <div id="top-search" class="header-misc-icon">
                                <a href="#" id="top-search-trigger"><i class="uil uil-search"></i><i class="bi-x-lg"></i></a>
                            </div>
                            
                            <!-- User Menu -->
                            <div id="top-account" class="header-misc-icon">
                                <a href="/login"><i class="uil uil-user"></i></a>
                            </div>
                        </div>
                        
                        <div class="primary-menu-trigger">
                            <button class="cnvs-hamburger" type="button" title="Open Mobile Menu">
                                <span class="cnvs-hamburger-box"><span class="cnvs-hamburger-inner"></span></span>
                            </button>
                        </div>
                        
                        <!-- Primary Navigation -->
                        <nav class="primary-menu">
                            <ul class="menu-container">
                                <?php
                                // Get dynamic menu from database
                                $menus = PublicPage::get_public_menu();
                                foreach ($menus as $menu) {
                                    if ($menu['parent'] === true) {
                                        $submenus = $menu['submenu'];
                                        // If there are no submenu items, output a simple nav item
                                        if (empty($submenus)) {
                                            echo '<li class="menu-item">';
                                            echo '<a class="menu-link" href="' . htmlspecialchars($menu['link']) . '"><div>' . htmlspecialchars($menu['name']) . '</div></a>';
                                            echo '</li>';
                                        } else {
                                            // Menu with submenus
                                            echo '<li class="menu-item">';
                                            echo '<a class="menu-link" href="' . htmlspecialchars($menu['link']) . '"><div>' . htmlspecialchars($menu['name']) . '</div></a>';
                                            echo '<ul class="sub-menu-container">';
                                            foreach ($submenus as $submenu) {
                                                echo '<li class="menu-item">';
                                                echo '<a class="menu-link" href="' . htmlspecialchars($submenu['link']) . '"><div>' . htmlspecialchars($submenu['name']) . '</div></a>';
                                                echo '</li>';
                                            }
                                            echo '</ul>';
                                            echo '</li>';
                                        }
                                    }
                                }
                                ?>
                            </ul>
                        </nav>
                        
                    </div>
                </div>
            </div>
        </header>
        <?php endif; ?>
        <?php
    }
    
    public function public_footer($options = array()) {
        $settings = Globalvars::get_instance();
        ?>
        
        <!-- Footer -->
        <footer id="footer" class="dark">
            <div class="container">
                
                <!-- Footer Widgets -->
                <div class="footer-widgets-wrap">
                    <div class="row col-mb-50">
                        <div class="col-lg-8">
                            <div class="row col-mb-50">
                                <div class="col-md-4">
                                    <div class="widget">
                                        <img src="/theme/canvas/assets/images/footer-widget-logo.png" alt="Logo" class="footer-logo">
                                        <p>Powered by <strong>Canvas</strong> Theme</p>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="widget widget_links">
                                        <h4>Quick Links</h4>
                                        <ul>
                                            <li><a href="/">Home</a></li>
                                            <li><a href="/about">About</a></li>
                                            <li><a href="/contact">Contact</a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="widget">
                                        <h4>Contact Info</h4>
                                        <abbr title="Email Address"><strong>Email:</strong></abbr> info@example.com
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="widget">
                                <h4>Follow Us</h4>
                                <div class="d-flex">
                                    <a href="#" class="social-icon border-transparent si-small h-bg-facebook">
                                        <i class="fa-brands fa-facebook-f"></i>
                                        <i class="fa-brands fa-facebook-f"></i>
                                    </a>
                                    <a href="#" class="social-icon border-transparent si-small h-bg-x-twitter">
                                        <i class="fa-brands fa-x-twitter"></i>
                                        <i class="fa-brands fa-x-twitter"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <!-- Copyrights -->
            <div id="copyrights">
                <div class="container">
                    <div class="row col-mb-30">
                        <div class="col-md-6 text-center text-md-start">
                            Copyrights &copy; <?php echo date('Y'); ?> All Rights Reserved.
                        </div>
                    </div>
                </div>
            </div>
        </footer>
        
    </div><!-- #wrapper end -->
    
    <!-- Go To Top -->
    <div id="gotoTop" class="uil uil-angle-up"></div>
    
    <!-- JavaScripts -->
    <script src="/theme/canvas/assets/js/plugins.min.js"></script>
    <script src="/theme/canvas/assets/js/functions.bundle.js"></script>
    
</body>
</html>
        <?php
    }
}
?>