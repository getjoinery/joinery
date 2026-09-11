<?php
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));

	header("Content-Type: application/xml; charset=UTF-8");

	$records = SeoPageMetadata::enumerate_public_paths();

	// Left-join SEO rows so noindex paths can be skipped and lastmod can fall back to spm_modify_time
	$dblink = DbConnector::get_instance()->get_db_link();
	$rows_by_path = array();
	$q = $dblink->query("SELECT spm_path, spm_noindex, spm_modify_time
	                     FROM spm_seo_page_metadata
	                     WHERE spm_delete_time IS NULL");
	while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
		$rows_by_path[$row['spm_path']] = $row;
	}

	echo "<?xml version='1.0' encoding='UTF-8'?>\n";
	echo "<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>\n";

	foreach ($records as $rec) {
		$path = $rec['path'];
		$seo  = $rows_by_path[$path] ?? null;
		if ($seo && $seo['spm_noindex']) {
			continue;
		}

		$loc = LibraryFunctions::get_absolute_url($path);

		$lastmod = $rec['modify_time'] ?? ($seo['spm_modify_time'] ?? null);
		$lastmod_str = $lastmod ? substr($lastmod, 0, 10) : date('Y-m-d');

		echo "    <url>\n";
		echo "        <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
		echo "        <lastmod>" . htmlspecialchars($lastmod_str, ENT_XML1, 'UTF-8') . "</lastmod>\n";
		echo "        <changefreq>monthly</changefreq>\n";
		echo "        <priority>" . ($path === '/' ? '1.0' : '0.7') . "</priority>\n";
		echo "    </url>\n";
	}

	echo "</urlset>\n";
?>
