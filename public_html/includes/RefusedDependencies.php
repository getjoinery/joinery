<?php
/**
 * RefusedDependencies - what a plugin or theme may not ask the platform to
 * install, and why.
 *
 * A manifest's requires.extensions and requires.packages are installed as
 * root at every root moment (utils/list_dependencies.php,
 * maintenance_scripts/install_tools/_install_declared_dependencies.sh). That
 * is a supported way to put software on a machine, so the few things the
 * platform has decided it does not run are named here, once, and refused at
 * both ends: the resolver drops them, and activation says no.
 *
 * ImageMagick and GraphicsMagick: they read hundreds of file formats and hand
 * files to other programs, which is where their remote-code bugs have come
 * from. Every image the site opens goes through GD, in the parser jail
 * (docs/document_text.md § Images); an extension loaded in the web worker
 * would be a second, unfenced way to open a stranger's picture
 * (specs/imagemagick_package_removal.md).
 *
 * No dependencies and no settings: the resolver runs where there is no site,
 * and requires this file by path.
 *
 * @version 1.0
 */
class RefusedDependencies {

	/** Extension name => why it is refused. */
	const EXTENSIONS = array(
		'imagick' => 'ImageMagick is not run on this platform: images are opened by GD, in the parser jail',
		'gmagick' => 'GraphicsMagick is not run on this platform: images are opened by GD, in the parser jail',
	);

	/** apt package names refused, as one pattern on the lowercased name. */
	const PACKAGE_PATTERN = '/^(?:php[0-9.]*-(?:imagick|gmagick)|imagemagick(?:-.*)?|graphicsmagick(?:-.*)?|libmagick.*|libgraphicsmagick.*)$/D';

	/** Why this extension is refused, or null when it is not. */
	public static function extension($name): ?string {
		$name = strtolower(trim((string)$name));
		return self::EXTENSIONS[$name] ?? null;
	}

	/** Why this system package is refused, or null when it is not. */
	public static function package($name): ?string {
		$name = strtolower(trim((string)$name));
		if ($name !== '' && preg_match(self::PACKAGE_PATTERN, $name)) {
			return 'ImageMagick and GraphicsMagick are not run on this platform: images are opened by GD, in the parser jail';
		}
		return null;
	}
}
