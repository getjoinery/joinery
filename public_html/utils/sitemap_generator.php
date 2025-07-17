<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
PathHelper::requireOnce('includes/LibraryFunctions.php');

PathHelper::requireOnce('data/events_class.php');
PathHelper::requireOnce('data/address_class.php');
PathHelper::requireOnce('data/users_class.php');

echo 'feature turned off';
exit();

//THIS IS UNFINISHED

$text = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">';


$todaysdate = date("Y-m-d",time());


$sort = 'start_time';
$sdirection = 'ASC';
$searchterm = LibraryFunctions::fetch_variable('searchterm', NULL, 0, '');

$searches = array();
$searches['deleted'] = FALSE;
$searches['visibility'] = 1;
$events = new MultiEvent(
	$searches,
	array($sort=>$sdirection),
	$numperpage,
	$offset,
	'AND');
$events->load();	

foreach ($events as $event){
	$text .= '<url><loc>'.$event->get_url().'</loc><lastmod>$row->publish_date</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>';
}


//PUBS ITEMS
$Query = "SELECT * FROM pubs_items WHERE display=1";
$result = $connector->query($Query);

while (($row = mysql_fetch_object($result))){
	$fulldir = $webdir . "/pubs_item_view.php?pubs_itemid=" . $row->pubs_itemid;
	$fulldir = trim($fulldir);
	$date_updated = split(" ", $row->time_updated);
	$text .= "<url><loc>$fulldir</loc><lastmod>$date_updated[0]</lastmod><changefreq>monthly</changefreq><priority>0.8</priority></url>";
}


//EVENTS
$Query = "SELECT * FROM events WHERE display=1";
$result = $connector->query($Query);

while (($row = mysql_fetch_object($result))){
	$fulldir = $webdir . "/events_view.php?eventid=" . $row->eventid;
	$fulldir = trim($fulldir);
	$text .= "<url><loc>$fulldir</loc><changefreq>monthly</changefreq><priority>0.8</priority></url>";
}


//STATIC PAGES
$Query = "SELECT * FROM pages_searchindexs WHERE noindex=0";
$result = $connector->query($Query);

while (($row = mysql_fetch_object($result))){
	$fulldir = $webdir . $row->page_path;
	$fulldir = trim($fulldir);
	$text .= "<url><loc>$fulldir</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>";
}

$text .= "</urlset>";

//CONVERT OUR ISO-8869-1 CONTENT TO UTF-8 TO MEET SITEMAP GUIDELINES
utf8_encode(htmlspecialchars($text));

//START THE EXPORT FILE
$filename = "sitemap.xml";
$fullpath = "../" . $filename;
$outputfile = fopen ($fullpath,"w");

if(fwrite ($outputfile, $text) == FALSE){
	echo "Write Error for $fullpath";
	exit();
}

fclose($outputfile);

$runtime = date("Y-m-d G:i:s",time());
$Query = "INSERT INTO log_regular_script_updates VALUES ('GoogleSitemap', '$runtime', '')";
$connector->query($Query);

echo 'done';

?>
