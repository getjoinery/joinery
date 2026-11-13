/*
 * A post's comments (views/post.php): a comment's reply link shows or hides
 * its form.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.comment-reply-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var commentId = this.getAttribute('data-comment-id');
            var formContainer = document.getElementById('reply-form-' + commentId);
            if (formContainer) {
                formContainer.style.display = formContainer.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
});
