/**
 * Falcon Theme - Admin Minimal JS
 * A stripped-down version of theme.js containing only essential admin functionality.
 * Replaces the 14,000+ line theme.js for admin pages.
 */

(function() {
  'use strict';

  /* -------------------------------------------------------------------------- */
  /*                                    Utils                                   */
  /* -------------------------------------------------------------------------- */

  var docReady = function(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      setTimeout(fn, 1);
    }
  };

  var hasClass = function(el, className) {
    return el && el.classList.contains(className);
  };

  var addClass = function(el, className) {
    el && el.classList.add(className);
  };

  var removeClass = function(el, className) {
    el && el.classList.remove(className);
  };

  /* -------------------------------------------------------------------------- */
  /*                              Local Storage                                 */
  /* -------------------------------------------------------------------------- */

  var getItemFromStore = function(key, defaultValue) {
    try {
      return JSON.parse(localStorage.getItem(key)) || defaultValue;
    } catch (e) {
      return defaultValue;
    }
  };

  var setItemToStore = function(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      // localStorage not available
    }
  };

  /* -------------------------------------------------------------------------- */
  /*                            Navbar Vertical Toggle                          */
  /* -------------------------------------------------------------------------- */

  var handleNavbarVerticalCollapsed = function() {
    var navbarVerticalToggle = document.querySelector('.navbar-vertical-toggle');
    var html = document.documentElement;
    var navbarVerticalCollapse = document.querySelector('.navbar-vertical .navbar-collapse');

    // Restore collapsed state from localStorage
    if (getItemFromStore('isNavbarVerticalCollapsed', false)) {
      addClass(html, 'navbar-vertical-collapsed');
    }

    if (navbarVerticalToggle) {
      navbarVerticalToggle.addEventListener('click', function(e) {
        navbarVerticalToggle.blur();
        html.classList.toggle('navbar-vertical-collapsed');

        var isCollapsed = hasClass(html, 'navbar-vertical-collapsed');
        setItemToStore('isNavbarVerticalCollapsed', isCollapsed);

        // Dispatch custom event
        var event = new CustomEvent('navbar.vertical.toggle');
        e.currentTarget.dispatchEvent(event);
      });
    }

    // Hover expand when collapsed (desktop only)
    if (navbarVerticalCollapse) {
      navbarVerticalCollapse.addEventListener('mouseover', function() {
        if (hasClass(html, 'navbar-vertical-collapsed') && window.innerWidth >= 1200) {
          addClass(html, 'navbar-vertical-collapsed-hover');
        }
      });

      navbarVerticalCollapse.addEventListener('mouseleave', function() {
        removeClass(html, 'navbar-vertical-collapsed-hover');
      });
    }
  };

  /* -------------------------------------------------------------------------- */
  /*                              Navbar Toggler                                */
  /* -------------------------------------------------------------------------- */

  var navbarToggleInit = function() {
    var navbarToggler = document.querySelector('.navbar-toggler');
    var navbarCollapse = document.querySelector('.navbar-collapse');

    if (navbarToggler && navbarCollapse) {
      navbarToggler.addEventListener('click', function() {
        navbarCollapse.classList.toggle('show');
      });
    }
  };

  /* -------------------------------------------------------------------------- */
  /*                           Tooltip & Popover Init                           */
  /* -------------------------------------------------------------------------- */

  var tooltipInit = function() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
      var tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
      tooltipTriggerList.forEach(function(el) {
        new bootstrap.Tooltip(el);
      });
    }
  };

  var popoverInit = function() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Popover) {
      var popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
      popoverTriggerList.forEach(function(el) {
        new bootstrap.Popover(el);
      });
    }
  };

  /* -------------------------------------------------------------------------- */
  /*                              Dropdown Fix for iOS                          */
  /* -------------------------------------------------------------------------- */

  var dropdownMenuInit = function() {
    // Fix for iOS dropdown in table-responsive
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

    if (isIOS) {
      document.querySelectorAll('.table-responsive').forEach(function(table) {
        table.addEventListener('shown.bs.dropdown', function(e) {
          if (this.scrollWidth > this.clientWidth) {
            this.style.paddingBottom = e.target.nextElementSibling.clientHeight + 'px';
          }
        });
        table.addEventListener('hidden.bs.dropdown', function() {
          this.style.paddingBottom = '';
        });
      });
    }
  };

  /* -------------------------------------------------------------------------- */
  /*                            Scrollbar Class (Simple)                        */
  /* -------------------------------------------------------------------------- */

  var scrollbarInit = function() {
    // Just ensure scrollbar elements have proper overflow
    document.querySelectorAll('.scrollbar').forEach(function(el) {
      el.style.overflow = 'auto';
    });
  };

  /* -------------------------------------------------------------------------- */
  /*                                Initialize                                  */
  /* -------------------------------------------------------------------------- */

  docReady(handleNavbarVerticalCollapsed);
  docReady(navbarToggleInit);
  docReady(tooltipInit);
  docReady(popoverInit);
  docReady(dropdownMenuInit);
  docReady(scrollbarInit);

})();
