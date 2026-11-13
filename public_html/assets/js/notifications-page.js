/*
 * /notifications: mark one notification read, or all of them (views/notifications.php).
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
	// Mark single notification as read
	document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var id = this.getAttribute('data-id');
			var row = document.querySelector('.ntf-row[data-id="' + id + '"]');
			joineryApi.post('notification_mark_read', { notification_id: id }).then(function() {
				row.classList.remove('ntf-row-unread');
				var dot = row.querySelector('.ntf-unread-dot');
				if (dot) dot.remove();
				var markBtn = row.querySelector('.ntf-row-mark-btn');
				if (markBtn) markBtn.remove();
			}).catch(function() {});
		});
	});

	// Mark all as read
	var markAllBtn = document.getElementById('mark-all-read-btn');
	if (markAllBtn) {
		markAllBtn.addEventListener('click', function() {
			joineryApi.post('notification_mark_all_read').then(function() {
				document.querySelectorAll('.ntf-row-unread').forEach(function(el) {
					el.classList.remove('ntf-row-unread');
				});
				document.querySelectorAll('.ntf-unread-dot').forEach(function(el) {
					el.remove();
				});
				document.querySelectorAll('.ntf-row-mark-btn').forEach(function(el) {
					el.remove();
				});
			}).catch(function() {});
		});
	}
});
