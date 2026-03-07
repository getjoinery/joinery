/* ============================================================
   Canvas HTML5 - Vanilla JS
   Minimal JS for components that truly need it
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {

    // ===== Mobile menu toggle =====
    const toggle = document.querySelector('.menu-toggle');
    const nav = document.querySelector('.nav-links');
    if (toggle && nav) {
        toggle.addEventListener('click', () => nav.classList.toggle('open'));
    }

    // ===== Sticky header on scroll =====
    const header = document.querySelector('.site-header:not(.header-light)');
    if (header) {
        const hero = document.querySelector('.page-hero, .hero-section');
        const threshold = hero ? hero.offsetHeight - 80 : 200;
        window.addEventListener('scroll', () => {
            header.classList.toggle('sticky', window.scrollY > threshold);
        }, { passive: true });
    }

    // ===== Testimonial slider =====
    document.querySelectorAll('.testimonial-slider').forEach(slider => {
        const slides = slider.querySelector('.testimonial-slides');
        const dots = slider.querySelectorAll('.testimonial-dot');
        if (!slides || !dots.length) return;
        let current = 0;
        const total = dots.length;
        const goTo = (i) => {
            current = ((i % total) + total) % total;
            slides.style.transform = `translateX(-${current * 100}%)`;
            dots.forEach((d, j) => d.classList.toggle('active', j === current));
        };
        dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));
        setInterval(() => goTo(current + 1), 5000);
    });

    // ===== Animated counters =====
    const counters = document.querySelectorAll('[data-count]');
    if (counters.length) {
        const animateCounter = (el) => {
            const target = parseInt(el.dataset.count, 10);
            const suffix = el.dataset.suffix || '';
            const comma = el.dataset.comma === 'true';
            const duration = 2000;
            const start = performance.now();
            const tick = (now) => {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
                const value = Math.floor(eased * target);
                el.textContent = (comma ? value.toLocaleString() : value) + suffix;
                if (progress < 1) requestAnimationFrame(tick);
                else el.textContent = (comma ? target.toLocaleString() : target) + suffix;
            };
            requestAnimationFrame(tick);
        };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) { animateCounter(e.target); observer.unobserve(e.target); }
            });
        }, { threshold: 0.5 });
        counters.forEach(c => observer.observe(c));
    }

    // ===== Skill bar animation =====
    document.querySelectorAll('.skill-fill').forEach(bar => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) { bar.style.width = targetWidth; observer.unobserve(bar); }
            });
        }, { threshold: 0.5 });
        observer.observe(bar);
    });

    // ===== Tabs =====
    document.querySelectorAll('.tabs-nav').forEach(nav => {
        const links = nav.querySelectorAll('.tab-link');
        const container = nav.closest('.tabs-wrapper') || nav.parentElement;
        links.forEach(link => {
            link.addEventListener('click', () => {
                const target = link.dataset.tab;
                links.forEach(l => l.classList.remove('active'));
                link.classList.add('active');
                container.querySelectorAll('.tab-content').forEach(c => {
                    c.classList.toggle('active', c.id === target);
                });
            });
        });
    });

    // ===== Accordion =====
    document.querySelectorAll('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            const item = header.closest('.accordion-item');
            const accordion = item.closest('.accordion');
            // Close others in same accordion
            accordion.querySelectorAll('.accordion-item').forEach(i => {
                if (i !== item) i.classList.remove('active');
            });
            item.classList.toggle('active');
        });
    });

    // ===== Alert dismiss =====
    document.querySelectorAll('.alert-close').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('.alert').style.display = 'none';
        });
    });

    // ===== Modal =====
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const modal = document.getElementById(trigger.dataset.modal);
            if (modal) modal.classList.add('active');
        });
    });
    document.querySelectorAll('.modal-close, .modal-overlay').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target === el) {
                el.closest('.modal-overlay')?.classList.remove('active');
            }
        });
    });

    // ===== Smooth scroll for anchor links =====
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', (e) => {
            const target = document.querySelector(a.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ===== Progress bar animation =====
    document.querySelectorAll('.progress-bar').forEach(bar => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) { bar.style.width = targetWidth; observer.unobserve(bar); }
            });
        }, { threshold: 0.3 });
        observer.observe(bar);
    });

    // ===== Portfolio filter (simple show/hide) =====
    document.querySelectorAll('.portfolio-filter').forEach(filter => {
        const buttons = filter.querySelectorAll('button');
        const grid = filter.nextElementSibling;
        if (!grid) return;
        const items = grid.querySelectorAll('.portfolio-item, [data-category]');
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const cat = btn.dataset.filter;
                items.forEach(item => {
                    if (cat === '*' || item.dataset.category === cat) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    });
});
