/* Linka HTML5 - Vanilla JavaScript */

document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle
    const menuToggle = document.querySelector('.menu-toggle');
    const mainNav = document.querySelector('.main-nav');

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            mainNav.classList.toggle('active');
        });
    }

    // Search functionality
    const searchBtn = document.querySelector('.search-btn');
    const closeBtn = document.querySelector('.close-btn');
    const searchOverlay = document.querySelector('.search-overlay');

    if (searchBtn && searchOverlay) {
        searchBtn.addEventListener('click', function() {
            searchOverlay.classList.add('active');
        });
    }

    if (closeBtn && searchOverlay) {
        closeBtn.addEventListener('click', function() {
            searchOverlay.classList.remove('active');
        });
    }

    // Search form submission
    const searchForm = document.querySelector('.search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const query = document.querySelector('.search-input').value;
            if (query) {
                console.log('Searching for:', query);
                // Redirect to search results (would be handled by backend)
            }
        });
    }

    // Go to top button
    const goTopBtn = document.querySelector('.go-top');
    if (goTopBtn) {
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                goTopBtn.classList.add('active');
            } else {
                goTopBtn.classList.remove('active');
            }
        });

        goTopBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    // Close mobile menu when link clicked
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                // Don't close if it's a dropdown toggle
                if (!this.parentElement.querySelector('.dropdown-menu')) {
                    if (mainNav) mainNav.classList.remove('active');
                }
            }
        });
    });

    // Close search overlay on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && searchOverlay) {
            searchOverlay.classList.remove('active');
        }
    });

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#') {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
});
