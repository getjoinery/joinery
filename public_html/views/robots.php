<?php
header('Content-Type: text/plain');
echo "User-agent: *\r\n";
echo "Disallow: /admin/\r\n";
echo "Disallow: /ajax/\r\n";
echo "Disallow: /analytics/\r\n";
echo "Disallow: /data/\r\n";
echo "Disallow: /logic/\r\n";
echo "Disallow: /includes/\r\n";
echo "Disallow: /page_scripts/\r\n";
echo "Disallow: /phpincludes/\r\n";
echo "Disallow: /profile/\r\n";
echo "Disallow: /template/\r\n";
echo "Disallow: /test/\r\n";
echo "Disallow: /theme/\r\n";
echo "Disallow: /utils/\r\n";
echo "Disallow: /wp-content/\r\n";
echo "Disallow: /uploads/\r\n";
?>
