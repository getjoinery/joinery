(function () {
    "use strict";

    /* 1. Preloader */
    window.addEventListener('load', function () {
        var preloader = document.querySelector('.preloader');
        if (preloader) preloader.style.display = 'none';
    });
    document.querySelectorAll('.preloaderCls').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var preloader = document.querySelector('.preloader');
            if (preloader) preloader.style.display = 'none';
        });
    });

    /* 2. Mobile Menu */
    var menuWrapper = document.querySelector('.th-menu-wrapper');
    if (menuWrapper) {
        // Add submenu classes and expand togglers
        menuWrapper.querySelectorAll('li').forEach(function (li) {
            var submenu = li.querySelector('ul');
            if (submenu) {
                submenu.classList.add('th-submenu');
                submenu.style.display = 'none';
                li.classList.add('th-item-has-children');
                var link = li.querySelector(':scope > a');
                if (link) {
                    var expander = document.createElement('span');
                    expander.className = 'th-mean-expand';
                    link.appendChild(expander);
                }
            }
        });

        function closeMenu() {
            menuWrapper.classList.remove('th-body-visible');
            menuWrapper.querySelectorAll('.th-submenu.th-open').forEach(function (sub) {
                sub.classList.remove('th-open');
                sub.style.display = 'none';
                sub.parentElement.classList.remove('th-active');
            });
        }

        document.querySelectorAll('.th-menu-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                menuWrapper.classList.toggle('th-body-visible');
            });
        });

        // Click on overlay (outside the inner div) closes menu
        menuWrapper.addEventListener('click', closeMenu);
        var innerMenu = menuWrapper.querySelector('.th-mobile-menu');
        if (innerMenu) {
            innerMenu.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        // Submenu expand/collapse
        menuWrapper.querySelectorAll('.th-mean-expand').forEach(function (expander) {
            expander.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var parentLi = this.closest('li');
                var submenu = parentLi && parentLi.querySelector('.th-submenu');
                if (!submenu) return;
                var isOpen = submenu.classList.contains('th-open');
                submenu.classList.toggle('th-open', !isOpen);
                submenu.style.display = isOpen ? 'none' : 'block';
                parentLi.classList.toggle('th-active', !isOpen);
            });
        });
    }

    /* 3. Sticky Header */
    var stickyWrapper = document.querySelector('.sticky-wrapper');
    if (stickyWrapper) {
        window.addEventListener('scroll', function () {
            stickyWrapper.classList.toggle('sticky', window.scrollY > 500);
        });
    }

    /* 4. Scroll To Top with SVG progress circle */
    var scrollTopBtn = document.querySelector('.scroll-top');
    var progressPath = document.querySelector('.scroll-top path');
    if (scrollTopBtn && progressPath) {
        var pathLength = progressPath.getTotalLength();
        progressPath.style.transition = 'none';
        progressPath.style.strokeDasharray = pathLength + ' ' + pathLength;
        progressPath.style.strokeDashoffset = pathLength;
        progressPath.getBoundingClientRect();
        progressPath.style.transition = 'stroke-dashoffset 10ms linear';

        function updateScrollProgress() {
            var scroll = window.scrollY;
            var height = document.documentElement.scrollHeight - window.innerHeight;
            progressPath.style.strokeDashoffset = pathLength - (scroll * pathLength / height);
            scrollTopBtn.classList.toggle('show', scroll > 50);
        }
        window.addEventListener('scroll', updateScrollProgress);
        updateScrollProgress();

        scrollTopBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* 5. Background image / color / mask from data attributes */
    document.querySelectorAll('[data-bg-src]').forEach(function (el) {
        el.style.backgroundImage = 'url(' + el.getAttribute('data-bg-src') + ')';
        el.classList.add('background-image');
        el.removeAttribute('data-bg-src');
    });
    document.querySelectorAll('[data-bg-color]').forEach(function (el) {
        el.style.backgroundColor = el.getAttribute('data-bg-color');
        el.removeAttribute('data-bg-color');
    });
    document.querySelectorAll('[data-border]').forEach(function (el) {
        el.style.setProperty('--th-border-color', el.getAttribute('data-border'));
    });
    document.querySelectorAll('[data-mask-src]').forEach(function (el) {
        var mask = el.getAttribute('data-mask-src');
        el.style.maskImage = 'url(' + mask + ')';
        el.style.webkitMaskImage = 'url(' + mask + ')';
        el.classList.add('bg-mask');
        el.removeAttribute('data-mask-src');
    });

    /* 6. Counter number animation (triggered on scroll into view) */
    var counterEls = document.querySelectorAll('.counter-number');
    if (counterEls.length) {
        function animateCounter(el) {
            var target = parseFloat(el.textContent.replace(/[^0-9.]/g, ''));
            if (isNaN(target)) return;
            var startTime = null;
            var duration = 1500;
            function step(ts) {
                if (!startTime) startTime = ts;
                var progress = Math.min((ts - startTime) / duration, 1);
                el.textContent = Math.round(progress * target);
                if (progress < 1) requestAnimationFrame(step);
                else el.textContent = target;
            }
            requestAnimationFrame(step);
        }
        if ('IntersectionObserver' in window) {
            var counterObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        animateCounter(entry.target);
                        counterObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            counterEls.forEach(function (el) { counterObserver.observe(el); });
        } else {
            counterEls.forEach(animateCounter);
        }
    }

    /* 7. Popup video (YouTube) using <dialog> */
    document.querySelectorAll('.popup-video').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var href = this.getAttribute('href') || this.href;
            var embedUrl = href.replace('watch?v=', 'embed/') + '?autoplay=1&rel=0';
            var dialog = document.createElement('dialog');
            dialog.style.cssText = 'padding:0;border:none;background:#000;max-width:90vw;width:860px;border-radius:8px;';
            dialog.innerHTML =
                '<form method="dialog" style="position:absolute;top:8px;right:12px;z-index:1">' +
                '<button style="background:rgba(0,0,0,.6);border:none;color:#fff;font-size:28px;line-height:1;cursor:pointer;border-radius:50%;width:36px;height:36px;">&times;</button>' +
                '</form>' +
                '<iframe src="' + embedUrl + '" width="100%" height="480" frameborder="0" allow="autoplay;fullscreen" allowfullscreen style="display:block"></iframe>';
            document.body.appendChild(dialog);
            dialog.showModal();
            dialog.addEventListener('close', function () { dialog.remove(); });
        });
    });

    /* 8. Pricing switch (monthly / yearly) */
    var filtMonthly = document.getElementById('filt-monthly');
    var filtYearly = document.getElementById('filt-yearly');
    var switcher = document.getElementById('switcher');
    var monthly = document.getElementById('monthly');
    var yearly = document.getElementById('yearly');

    if (filtMonthly && filtYearly && switcher && monthly && yearly) {
        filtMonthly.addEventListener('click', function () {
            switcher.checked = false;
            filtMonthly.classList.add('toggler--is-active');
            filtYearly.classList.remove('toggler--is-active');
            monthly.classList.remove('hide');
            yearly.classList.add('hide');
        });
        filtYearly.addEventListener('click', function () {
            switcher.checked = true;
            filtYearly.classList.add('toggler--is-active');
            filtMonthly.classList.remove('toggler--is-active');
            monthly.classList.add('hide');
            yearly.classList.remove('hide');
        });
        switcher.addEventListener('click', function () {
            filtYearly.classList.toggle('toggler--is-active');
            filtMonthly.classList.toggle('toggler--is-active');
            monthly.classList.toggle('hide');
            yearly.classList.toggle('hide');
        });
    }

    /* 9. Animation classes from data-ani / data-ani-delay attributes */
    document.querySelectorAll('[data-ani]').forEach(function (el) {
        el.classList.add(el.getAttribute('data-ani'));
    });
    document.querySelectorAll('[data-ani-delay]').forEach(function (el) {
        el.style.animationDelay = el.getAttribute('data-ani-delay');
    });

    /* 10. Shape mockup: position shapes from data attributes */
    document.querySelectorAll('.shape-mockup').forEach(function (el) {
        ['top', 'right', 'bottom', 'left'].forEach(function (pos) {
            var val = el.getAttribute('data-' + pos);
            if (val) { el.style[pos] = val; el.removeAttribute('data-' + pos); }
        });
        if (el.parentElement) el.parentElement.classList.add('shape-mockup-wrap');
    });

    /* 11. Tilt effect on hover (replaces tilt.js) */
    document.querySelectorAll('.tilt-active').forEach(function (el) {
        el.style.transition = 'transform 0.1s ease';
        el.addEventListener('mousemove', function (e) {
            var rect = el.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width - 0.5;
            var y = (e.clientY - rect.top) / rect.height - 0.5;
            el.style.transform = 'perspective(1000px) rotateY(' + (x * 7) + 'deg) rotateX(' + (-y * 7) + 'deg)';
        });
        el.addEventListener('mouseleave', function () {
            el.style.transform = 'perspective(1000px) rotateY(0deg) rotateX(0deg)';
        });
    });

    /* 12. CSS custom property color overrides from data attributes */
    document.querySelectorAll('[theme-color]').forEach(function (el) {
        el.style.setProperty('--theme-color', el.getAttribute('theme-color'));
        el.removeAttribute('theme-color');
    });
    document.querySelectorAll('[title-color]').forEach(function (el) {
        el.style.setProperty('--title-color', el.getAttribute('title-color'));
        el.removeAttribute('title-color');
    });

})();
