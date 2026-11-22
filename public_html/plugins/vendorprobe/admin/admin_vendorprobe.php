<?php
/** @joinery-admin level=10 */
/**
 * admin_vendorprobe — says whether this plugin's own library loaded, and from
 * where. The live check for plugin_own_vendor reads it. Plain text, no chrome.
 *
 * @version 1.0.0
 */

header('Content-type: text/plain');

$interface = 'Psr\\Cache\\CacheItemPoolInterface';
if (!interface_exists($interface)) {
	echo "not loaded: " . $interface . "\n";
	return;
}
$file = (string)(new ReflectionClass($interface))->getFileName();
$base = rtrim(PathHelper::getBasePath(), '/');
echo "loaded: " . $interface . "\n";
echo "from: " . (strpos($file, $base . '/') === 0 ? substr($file, strlen($base) + 1) : $file) . "\n";
