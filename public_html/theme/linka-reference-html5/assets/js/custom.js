'use strict';

document.addEventListener('DOMContentLoaded', function() {

    // ------- Header Sticky ------- //
    window.addEventListener('scroll', function() {
        var navbarArea = document.querySelector('.navbar-area');
        if (navbarArea) {
            if (window.scrollY > 150) {
                navbarArea.classList.add('is-sticky');
            } else {
                navbarArea.classList.remove('is-sticky');
            }
        }
    });

    // ------- Mobile Nav (MeanMenu replacement) ------- //
    // Build a simple mobile toggle for the .mean-menu collapse
    var mobileNavArea = document.querySelector('.mobile-nav');
    var mainNavArea = document.querySelector('.main-nav');
    if (mobileNavArea && mainNavArea) {
        // Create hamburger button
        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'mobile-nav-toggle';
        toggleBtn.setAttribute('aria-label', 'Toggle navigation');
        toggleBtn.innerHTML = '<i class="bx bx-menu"></i>';
        toggleBtn.style.cssText = 'background:none;border:none;cursor:pointer;font-size:24px;padding:8px;float:right;';
        mobileNavArea.appendChild(toggleBtn);

        // Create mobile menu panel
        var mobileMenu = document.createElement('div');
        mobileMenu.id = 'mobile-menu-panel';
        mobileMenu.style.cssText = 'display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:#fff;z-index:9999;overflow-y:auto;padding:20px;box-sizing:border-box;';

        // Close button
        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '<i class="bx bx-x"></i>';
        closeBtn.style.cssText = 'background:none;border:none;cursor:pointer;font-size:28px;float:right;';
        closeBtn.addEventListener('click', function() {
            mobileMenu.style.display = 'none';
        });
        mobileMenu.appendChild(closeBtn);

        // Clone nav links from desktop nav
        var desktopNav = document.querySelector('.mean-menu .navbar-nav');
        if (desktopNav) {
            var mobileNavClone = desktopNav.cloneNode(true);
            mobileNavClone.style.cssText = 'list-style:none;padding:40px 0 0;margin:0;clear:both;';
            // Style each item
            mobileNavClone.querySelectorAll('li').forEach(function(li) {
                li.style.borderBottom = '1px solid #eee';
            });
            mobileNavClone.querySelectorAll('a').forEach(function(a) {
                a.style.cssText = 'display:block;padding:12px 0;color:#333;text-decoration:none;font-weight:600;';
            });
            // Expand/collapse submenus
            mobileNavClone.querySelectorAll('.dropdown-menu').forEach(function(sub) {
                sub.style.cssText = 'display:none;list-style:none;padding:0 0 0 16px;margin:0;position:static !important;';
                var parentLink = sub.previousElementSibling;
                if (parentLink) {
                    parentLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        sub.style.display = sub.style.display === 'block' ? 'none' : 'block';
                    });
                }
            });
            mobileMenu.appendChild(mobileNavClone);
        }

        document.body.appendChild(mobileMenu);

        toggleBtn.addEventListener('click', function() {
            mobileMenu.style.display = 'block';
        });
    }

    // ------- Blog Slider Carousel (OWL replacement) ------- //
    // responsive: array of [minWidth, itemCount] breakpoints sorted ascending
    function initCarousel(selector, options) {
        var wrapper = document.querySelector(selector);
        if (!wrapper) return;

        // Remove owl-carousel class to prevent OWL CSS from interfering
        wrapper.classList.remove('owl-carousel', 'owl-theme');

        var allItems = Array.from(wrapper.children);
        if (allItems.length === 0) return;

        var opts = options || {};
        var autoplay = opts.autoplay !== false;
        var interval = opts.interval || 5000;
        var margin = opts.margin || 0;
        // responsive: [[minWidth, numItems], ...]
        var responsive = opts.responsive || [[0, 1]];

        var current = 0;
        var visibleCount = 1;

        function getVisibleCount() {
            var w = window.innerWidth;
            var count = 1;
            responsive.forEach(function(bp) {
                if (w >= bp[0]) count = bp[1];
            });
            return Math.min(count, allItems.length);
        }

        // Wrap in container
        var container = document.createElement('div');
        container.style.position = 'relative';
        container.style.overflow = 'hidden';
        wrapper.parentNode.insertBefore(container, wrapper);
        container.appendChild(wrapper);
        wrapper.style.display = 'flex';
        wrapper.style.transition = 'transform 0.4s ease';

        // Nav buttons
        var prevBtn = document.createElement('button');
        prevBtn.innerHTML = "<i class='bx bx-chevron-left'></i>";
        prevBtn.className = 'carousel-nav-btn carousel-prev';
        prevBtn.style.cssText = 'position:absolute;top:50%;left:10px;transform:translateY(-50%);z-index:10;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:38px;height:38px;cursor:pointer;font-size:22px;line-height:1;display:flex;align-items:center;justify-content:center;';

        var nextBtn = document.createElement('button');
        nextBtn.innerHTML = "<i class='bx bx-chevron-right'></i>";
        nextBtn.className = 'carousel-nav-btn carousel-next';
        nextBtn.style.cssText = 'position:absolute;top:50%;right:10px;transform:translateY(-50%);z-index:10;background:rgba(0,0,0,0.5);color:#fff;border:none;border-radius:50%;width:38px;height:38px;cursor:pointer;font-size:22px;line-height:1;display:flex;align-items:center;justify-content:center;';

        container.appendChild(prevBtn);
        container.appendChild(nextBtn);

        function updateLayout() {
            visibleCount = getVisibleCount();
            var itemWidth = 'calc(' + (100 / visibleCount) + '% - ' + (margin * (visibleCount - 1) / visibleCount) + 'px)';
            allItems.forEach(function(item) {
                item.style.minWidth = itemWidth;
                item.style.marginRight = margin + 'px';
                item.style.boxSizing = 'border-box';
            });
            goTo(current);
        }

        function maxIndex() {
            return Math.max(0, allItems.length - visibleCount);
        }

        function goTo(idx) {
            current = Math.max(0, Math.min(idx, maxIndex()));
            var pct = 100 / visibleCount;
            wrapper.style.transform = 'translateX(-' + (current * pct) + '%)';
        }

        prevBtn.addEventListener('click', function() { goTo(current - 1); });
        nextBtn.addEventListener('click', function() {
            if (current >= maxIndex()) { goTo(0); } else { goTo(current + 1); }
        });

        window.addEventListener('resize', updateLayout);
        updateLayout();
        // Re-run layout after a frame to catch any viewport changes during init
        requestAnimationFrame(function() { updateLayout(); });

        if (autoplay) {
            var timer = setInterval(function() {
                if (current >= maxIndex()) { goTo(0); } else { goTo(current + 1); }
            }, interval);
            container.addEventListener('mouseenter', function() { clearInterval(timer); });
            container.addEventListener('mouseleave', function() {
                timer = setInterval(function() {
                    if (current >= maxIndex()) { goTo(0); } else { goTo(current + 1); }
                }, interval);
            });
        }
    }

    initCarousel('.main-blog-slider-item-wrap', {
        autoplay: true, interval: 5000, margin: 0,
        responsive: [[0,1],[576,1],[768,2],[992,3],[1200,3]]
    });
    initCarousel('.banner-slider-wrap', {
        autoplay: false,
        responsive: [[0,1]]
    });
    initCarousel('.single-latest-news-wrap', {
        autoplay: true, interval: 5000, margin: 30,
        responsive: [[0,1],[576,1],[768,2],[992,2],[1200,2]]
    });
    initCarousel('.blog-ost-item-wrap', {
        autoplay: true, interval: 5000,
        responsive: [[0,1]]
    });

    // ------- Go to Top ------- //
    var goTop = document.querySelector('.go-top');
    if (goTop) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) {
                goTop.classList.add('active');
            } else {
                goTop.classList.remove('active');
            }
        });
        goTop.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ------- Tabs ------- //
    var tabContainers = document.querySelectorAll('.tab');
    tabContainers.forEach(function(tab) {
        var tabs = tab.querySelectorAll('ul.tabs li');
        var contents = tab.querySelectorAll('.tab_content .tabs_item');
        if (!tabs.length) return;
        tabs[0].classList.add('current');
        contents.forEach(function(c, i) { c.style.display = i === 0 ? 'block' : 'none'; });
        tabs.forEach(function(tabLi, idx) {
            tabLi.addEventListener('click', function(e) {
                e.preventDefault();
                tabs.forEach(function(t) { t.classList.remove('current'); });
                tabLi.classList.add('current');
                contents.forEach(function(c, i) { c.style.display = i === idx ? 'block' : 'none'; });
            });
        });
    });

    // ------- Input Counter (+/-) ------- //
    document.querySelectorAll('.input-counter').forEach(function(spinner) {
        var input = spinner.querySelector('input[type="text"]');
        var btnUp = spinner.querySelector('.plus-btn');
        var btnDown = spinner.querySelector('.minus-btn');
        if (!input || !btnUp || !btnDown) return;
        var min = parseFloat(input.getAttribute('min')) || 0;
        var max = parseFloat(input.getAttribute('max')) || Infinity;
        btnUp.addEventListener('click', function() {
            var val = parseFloat(input.value) || 0;
            if (val < max) input.value = val + 1;
        });
        btnDown.addEventListener('click', function() {
            var val = parseFloat(input.value) || 0;
            if (val > min) input.value = val - 1;
        });
    });

    // ------- Search Overlay ------- //
    var closeBtn = document.querySelector('.close-btn');
    var searchBtn = document.querySelector('.search-btn');
    var searchOverlay = document.querySelector('.search-overlay');
    if (closeBtn && searchOverlay) {
        closeBtn.addEventListener('click', function() {
            searchOverlay.style.display = 'none';
            if (searchBtn) searchBtn.style.display = '';
            closeBtn.classList.remove('active');
        });
    }
    if (searchBtn && searchOverlay) {
        searchBtn.addEventListener('click', function() {
            searchBtn.style.display = 'none';
            searchOverlay.style.display = 'block';
            if (closeBtn) closeBtn.classList.add('active');
        });
    }

    // ------- Sidebar Modal ------- //
    var burgerMenus = document.querySelectorAll('.burger-menu');
    var sidebarModal = document.querySelector('.sidebar-modal');
    var sidebarClose = document.querySelector('.sidebar-modal-close-btn');
    burgerMenus.forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (sidebarModal) sidebarModal.classList.toggle('active');
        });
    });
    if (sidebarClose && sidebarModal) {
        sidebarClose.addEventListener('click', function() {
            sidebarModal.classList.remove('active');
        });
    }

    // ------- Countdown Timer ------- //
    var timerEl = document.getElementById('days');
    if (timerEl) {
        function makeTimer() {
            var endTime = new Date("november 30, 2028 17:00:00 PDT");
            var timeLeft = (Date.parse(endTime) / 1000) - (Date.parse(new Date()) / 1000);
            var days = Math.floor(timeLeft / 86400);
            var hours = Math.floor((timeLeft - (days * 86400)) / 3600);
            var minutes = Math.floor((timeLeft - (days * 86400) - (hours * 3600)) / 60);
            var seconds = Math.floor(timeLeft - (days * 86400) - (hours * 3600) - (minutes * 60));
            if (hours < 10) hours = "0" + hours;
            if (minutes < 10) minutes = "0" + minutes;
            if (seconds < 10) seconds = "0" + seconds;
            var d = document.getElementById('days');
            var h = document.getElementById('hours');
            var m = document.getElementById('minutes');
            var s = document.getElementById('seconds');
            if (d) d.innerHTML = days + "<span>Days</span>";
            if (h) h.innerHTML = hours + "<span>Hours</span>";
            if (m) m.innerHTML = minutes + "<span>Minutes</span>";
            if (s) s.innerHTML = seconds + "<span>Seconds</span>";
        }
        setInterval(makeTimer, 300);
    }

});
