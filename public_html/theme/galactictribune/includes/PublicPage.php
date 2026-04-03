<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {

    // Implement required abstract method from PublicPageBase
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-wrapper',
            'table' => 'table',
            'header' => 'thead'
        ];
    }

    /**
     * Custom public header for Galactic Tribune theme - matches galactictribune.net design
     */
    public function public_header($options = array()) {
        // Call common header functionality
        ob_start();
        $this->public_header_common($options);
        $_head_inject = ob_get_clean();

        // Get menu data from PublicPageBase
        $menu_data = $this->get_menu_data();

        ?>
        <!DOCTYPE html>
        <html class="h-full" lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:fb="http://ogp.me/ns/fb#">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <meta name="description" content="<?php echo $options['description'] ?? ''; ?>">
            <meta name="keywords" content="">

            <title><?php echo htmlspecialchars($options['title'] ?? $menu_data['site_info']['site_name'] ?? 'Galactic Tribune'); ?></title>

            <?php echo $_head_inject; ?>
            <script src="<?php echo PathHelper::getThemeFilePath('jquery-3.4.1.min.js', 'assets/js', 'web'); ?>"></script>
            <script src="<?php echo PathHelper::getThemeFilePath('jquery.validate-1.9.1.js', 'assets/js', 'web'); ?>"></script>

            <!-- CSS -->
            <link rel="stylesheet" type="text/css" href="<?php echo PathHelper::getThemeFilePath('output.css', 'assets/css', 'web'); ?>">

            <?php
            $settings = Globalvars::get_instance();
            if($settings && $settings->get_setting('custom_css')){
                echo '<style>'.$settings->get_setting('custom_css').'</style>';
            }
            ?>
        </head>

        <script language="javascript">
         $(document).ready(function() {
            $('.js-clickable-menu').click(function() {
             var clicked_menu = $(this).nextAll('.js-clicked-menu');
             clicked_menu.toggleClass('invisible');
             $('.js-clicked-menu').not(clicked_menu).addClass('invisible');
             event.stopPropagation();
            });

            $('#user-menu-button').click(function() {
             $('#user-menu').toggleClass('invisible');
             event.stopPropagation();
            });

            $('#mobile-toggle-button').click(function() {
                $('#mobile-menu').removeClass('invisible');
            });

            $('#mobile-close-button').click(function() {
             $('#mobile-menu').addClass('invisible');
            });

            $('html').click(function() {
                $('.js-clicked-menu').addClass('invisible');
            });
        });
        </script>

        <body class="h-full">
        <div class="min-h-full">

        <!-- This example requires Tailwind CSS v2.0+ -->
        <div class="bg-gray-50">
          <div class="relative bg-white z-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6">
              <div class="flex justify-between items-center py-6 md:justify-start md:space-x-10">
                <div class="flex justify-start lg:w-0 lg:flex-1">
                  <a href="/">
                    <span class="sr-only"><?php echo htmlspecialchars($menu_data['site_info']['site_name'] ?? 'galactictribune.net'); ?></span>
                    <h3><a href="/"><?php echo htmlspecialchars($menu_data['site_info']['site_name'] ?? 'galactictribune.net'); ?></a></h3>
                  </a>
                </div>
                <div class="-mr-2 -my-2 md:hidden">
                  <button id="mobile-toggle-button" type="button" class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500" aria-expanded="false">
                    <span class="sr-only">Open menu</span>
                    <!-- Heroicon name: outline/menu -->
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                  </button>
                </div>
                <nav class="hidden md:flex space-x-10">
                  <?php foreach ($menu_data['main_menu'] as $menu_item): ?>
                    <?php if (!empty($menu_item['submenu'])): ?>
                      <!-- Dropdown menu -->
                      <div class="relative">
                        <button type="button" class="js-clickable-menu text-base font-medium text-gray-500 hover:text-gray-900<?php echo $menu_item['is_active'] ? ' text-gray-900' : ''; ?> group inline-flex items-center" aria-expanded="false">
                          <span><?php echo htmlspecialchars($menu_item['name']); ?></span>
                          <svg class="ml-2 h-5 w-5 text-gray-400 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                          </svg>
                        </button>

                        <div class="js-clicked-menu invisible absolute z-10 -ml-4 mt-3 transform px-2 w-screen max-w-md sm:px-0 lg:ml-0 lg:left-1/2 lg:-translate-x-1/2">
                          <div class="rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 overflow-hidden">
                            <div class="relative grid gap-6 bg-white px-5 py-6 sm:gap-8 sm:p-8">
                              <?php foreach ($menu_item['submenu'] as $submenu_item): ?>
                                <a href="<?php echo htmlspecialchars($submenu_item['link']); ?>" class="-m-3 p-3 flex items-start rounded-lg hover:bg-gray-50<?php echo $submenu_item['is_active'] ? ' bg-gray-50' : ''; ?>">
                                  <svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                  </svg>
                                  <div class="ml-4">
                                    <p class="text-base font-medium text-gray-900">
                                      <?php echo htmlspecialchars($submenu_item['name']); ?>
                                    </p>
                                  </div>
                                </a>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php else: ?>
                      <!-- Regular menu item -->
                      <a href="<?php echo htmlspecialchars($menu_item['link']); ?>"
                         class="text-base font-medium text-gray-500 hover:text-gray-900<?php echo $menu_item['is_active'] ? ' text-gray-900' : ''; ?>">
                        <?php echo htmlspecialchars($menu_item['name']); ?>
                      </a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </nav>

                <div class="hidden md:flex items-center justify-end space-x-8 md:flex-1 lg:w-0">
                  <?php if ($menu_data['user_menu']['is_logged_in']): ?>
                    <span class="text-base font-medium text-gray-500">
                      Welcome, <?php echo htmlspecialchars($menu_data['user_menu']['display_name']); ?>
                    </span>
                    <a href="/logout" class="whitespace-nowrap text-base font-medium text-gray-500 hover:text-gray-900">
                      Sign out
                    </a>
                  <?php else: ?>
                    <a href="/login" class="whitespace-nowrap text-base font-medium text-gray-500 hover:text-gray-900">
                      Sign in
                    </a>
                    <?php if ($menu_data['site_info']['register_enabled']): ?>
                      <a href="/register" class="whitespace-nowrap text-base font-medium text-gray-500 hover:text-gray-900">
                        Sign up
                      </a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Mobile menu (hidden by default) -->
        <div id="mobile-menu" class="invisible absolute top-0 inset-x-0 p-2 transition transform origin-top-right md:hidden z-50">
          <div class="rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 bg-white divide-y-2 divide-gray-50">
            <div class="pt-5 pb-6 px-5">
              <div class="flex items-center justify-between">
                <div>
                  <span class="sr-only"><?php echo htmlspecialchars($menu_data['site_info']['site_name'] ?? 'galactictribune.net'); ?></span>
                  <h3><a href="/"><?php echo htmlspecialchars($menu_data['site_info']['site_name'] ?? 'galactictribune.net'); ?></a></h3>
                </div>
                <div class="-mr-2">
                  <button id="mobile-close-button" type="button" class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                    <span class="sr-only">Close menu</span>
                    <!-- Heroicon name: outline/x -->
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>
              <div class="mt-6">
                <nav class="grid gap-y-8">
                  <?php foreach ($menu_data['main_menu'] as $menu_item): ?>
                    <?php if (!empty($menu_item['submenu'])): ?>
                      <!-- Parent menu item with submenu -->
                      <div>
                        <button type="button" class="js-clickable-menu -m-3 p-3 flex items-center justify-between rounded-md hover:bg-gray-50<?php echo $menu_item['is_active'] ? ' bg-gray-50' : ''; ?> w-full text-left">
                          <div class="flex items-center">
                            <svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <span class="ml-3 text-base font-medium text-gray-900"><?php echo htmlspecialchars($menu_item['name']); ?></span>
                          </div>
                          <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                          </svg>
                        </button>
                        <div class="js-clicked-menu invisible ml-8 mt-2 space-y-2">
                          <?php foreach ($menu_item['submenu'] as $submenu_item): ?>
                            <a href="<?php echo htmlspecialchars($submenu_item['link']); ?>" class="block p-2 text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50 rounded-md<?php echo $submenu_item['is_active'] ? ' bg-gray-50 text-gray-900' : ''; ?>">
                              <?php echo htmlspecialchars($submenu_item['name']); ?>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php else: ?>
                      <!-- Regular menu item -->
                      <a href="<?php echo htmlspecialchars($menu_item['link']); ?>"
                         class="-m-3 p-3 flex items-center rounded-md hover:bg-gray-50<?php echo $menu_item['is_active'] ? ' bg-gray-50' : ''; ?>">
                        <!-- Heroicon name: outline/chart-bar -->
                        <svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <span class="ml-3 text-base font-medium text-gray-900"><?php echo htmlspecialchars($menu_item['name']); ?></span>
                      </a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </nav>
              </div>
            </div>
            <div class="py-6 px-5 space-y-6">
              <div class="grid grid-cols-2 gap-y-4 gap-x-8">
              </div>
              <div>
                <?php if (!$menu_data['user_menu']['is_logged_in']): ?>
                  <p class="mt-6 text-center text-base font-medium text-gray-500">
                    Existing user?
                    <a href="/login" class="text-blue-600 hover:text-blue-500">
                      Sign in
                    </a>
                  </p>
                  <?php if ($menu_data['site_info']['register_enabled']): ?>
                    <p class="mt-2 text-center text-base font-medium text-gray-500">
                      New user?
                      <a href="/register" class="text-blue-600 hover:text-blue-500">
                        Sign up
                      </a>
                    </p>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="mt-6 text-center text-base font-medium text-gray-500">
                    Welcome, <?php echo htmlspecialchars($menu_data['user_menu']['display_name']); ?>
                    <br>
                    <a href="/logout" class="text-blue-600 hover:text-blue-500">
                      Sign out
                    </a>
                  </p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>





		  <div class="py-10 relative">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8"><main class="lg:col-span-9 xl:col-span-6">
        <div class="px-4 sm:px-0">
        <?php
    }

    /**
     * Begin main page content area - outputs the title section that matches live site
     */
    public static function BeginPage($title = '', $options = array()) {
        if ($title) {
            return '<div class="py-10 text-center relative">
				<div class="max-w-7xl mx-auto sm:px-6 lg:px-8"><h1 class="flex-1 text-3xl font-bold text-gray-900 mb-6">' . htmlspecialchars($title) . '</h1></div></div>

';
        }
        return '';
    }

    /**
     * End main page content area - matches live site (no-op since structure handled in footer)
     */
    public static function EndPage($options = array()) {
        return '';
    }

    /**
     * Custom public footer for Galactic Tribune theme - matches live site structure
     */
    public function public_footer($options = array()) {
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();
        ?>
        </div>
      </main>
	</div>
  </div><footer class="bg-white">
  <div class="max-w-7xl mx-auto py-12 px-4 overflow-hidden sm:px-6 lg:px-8">
    <div class="flex justify-center space-x-2 text-gray-700">
    </div>
    <p class="mt-8 text-center text-base text-gray-400">
      &copy; <?php echo date('Y'); ?> galactictribune.net All rights reserved.
    </p>
  </div>
</footer>		
		
		
		
		</div>

	</body>
</html>
        <?php
    }
}

?>