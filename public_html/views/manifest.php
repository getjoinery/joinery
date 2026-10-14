<?php
/**
 * /manifest.webmanifest — the site's web app manifest, linked from every page
 * (PublicPageBase::global_includes_top). A site added to an iPhone or iPad
 * Home Screen opens as its own app only when its manifest says standalone,
 * and only there can it receive push notifications (docs/push_notifications.md).
 *
 * @version 1.0.0
 */

$settings = Globalvars::get_instance();
$site_name = trim((string)$settings->get_setting('site_name')) ?: 'Joinery';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode(array(
	'name'             => $site_name,
	'short_name'       => $site_name,
	'start_url'        => '/',
	'scope'            => '/',
	'display'          => 'standalone',
	'background_color' => '#ffffff',
	'theme_color'      => '#ffffff',
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
