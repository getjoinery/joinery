/**
 * Joinery service worker: shows push notifications.
 *
 * Served from the site root (serve.php static route) so its scope is the
 * whole site. A push carries JSON {title, body, link, tag} (PushSender::render);
 * the notification opens `link` when clicked, focusing a tab already on the
 * site when there is one.
 *
 * Nothing else lives here: no caching, no offline pages.
 *
 * See docs/push_notifications.md.
 *
 * @version 1.0.0
 */
'use strict';

self.addEventListener('install', function () {
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
	var data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch (e) {
		data = { body: event.data ? event.data.text() : '' };
	}
	var title = data.title || self.location.hostname;
	var options = {
		body: data.body || '',
		data: { link: data.link || '/' },
	};
	if (data.tag) {
		// A newer notification with the same tag replaces the older one; renotify
		// still alerts for it.
		options.tag = data.tag;
		options.renotify = true;
	}
	event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var link = (event.notification.data && event.notification.data.link) || '/';
	var target = new URL(link, self.location.origin);
	// Only ever open this site's own pages.
	if (target.origin !== self.location.origin) {
		target = new URL('/', self.location.origin);
	}
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
			for (var i = 0; i < windows.length; i++) {
				var w = windows[i];
				if (new URL(w.url).origin === target.origin && 'navigate' in w) {
					return w.focus().then(function (focused) {
						return focused.navigate(target.href);
					});
				}
			}
			return self.clients.openWindow(target.href);
		})
	);
});
