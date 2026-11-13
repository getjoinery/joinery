/*
 * A post's comments (views/post.php): the Reply button under a comment shows
 * or hides its form.
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function(){
	document.querySelectorAll('.commentbutton').forEach(function(btn){
		btn.addEventListener('click', function(){
			var container = document.getElementById(this.id + 'container');
			if (container) {
				container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
			}
		});
	});
});
