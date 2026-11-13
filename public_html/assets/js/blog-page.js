/*
 * The blog's pages (views/blog.php, views/post.php): the sidebar's tabs, and
 * the Reply button under each comment showing its form.
 *
 * @version 1.0
 */
// Sidebar tabs (views/blog.php)
// Simple tab toggle for blog sidebar
document.querySelectorAll('.tabs-nav .tab-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tab = this.dataset.tab;
        document.querySelectorAll('.tabs-nav .tab-link').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('[data-tab-content]').forEach(function(c) {
            c.classList.toggle('active', c.dataset.tabContent === tab);
        });
    });
});
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.commentbutton').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var container = document.getElementById(this.id + 'container');
            if (container) container.hidden = !container.hidden;
        });
    });
});
