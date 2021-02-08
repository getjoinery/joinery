<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

class LibraryFunctions {


	static function SentenceCase($string) { 
		$sentences = preg_split('/([.?!]+)/', $string, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE); 
		$new_string = ''; 
		foreach ($sentences as $key => $sentence) { 
			$new_string .= ($key & 1) == 0? 
				ucfirst(strtolower(trim($sentence))) : 
				$sentence.' '; 
		} 
		return trim($new_string); 
	}

	static function Pluralize($amount, $word) {
		if ($amount == 1) {
			return $amount . ' ' . $word;
		} else {
			return $amount . ' ' . $word . 's';
		}
	}

	static function bool_to_english($input, $truevalue, $falsevalue){
		if($input == TRUE){
			return $truevalue;
		}
		else{
			return $falsevalue;
		}
	}

	static function datetoISO8601($date){
		$datearr = explode('/',$date);

		if(count($datearr) == 1){
			$datearr = explode('-',$date);
		}

		if(count($datearr) == 1){
			return FALSE;
		}

		$newdate = $datearr[2]. '-' .$datearr[0]. '-' .$datearr[1];
		return $newdate;
	}
	
	static function display_404_page(){
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		$site_template = $settings->get_setting('site_template');
		$theme_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/404.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'] . '/views/404.php';

		header("HTTP/1.0 404 Not Found");
		if(file_exists($theme_file)){
			//WE WANT A FILE PATH
			require_once($theme_file);
			exit();
		}
		else if(file_exists($base_file)){
			//WE WANT A FILE PATH
			require_once($base_file);
			exit();
		}
		else{
			echo 'Could not find Error 404 template file.';	
			exit();
		}

	}		
	
	static function get_theme_includes_path(){
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		$site_template = $settings->get_setting('site_template');
		
		$theme_dir = $siteDir.'/theme/'.$site_template.'/includes';
		return $theme_dir;
	}	
	
	static function get_logic_file_path($filename, $path_format='system'){
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		$site_template = $settings->get_setting('site_template');
		
		$theme_file = $siteDir.'/theme/'.$site_template.'/logic/'.$filename;
		$main_file = $siteDir.'/logic/'.$filename;

		if(file_exists($theme_file)){
			if($path_format == 'system'){
				//WE WANT A FILE PATH
				return $theme_file;
			}
			else{
				//WE WANT A URL
				return '/theme/'.$site_template.'/logic/'.basename($filename, '.php');
			}
		}
		else if(file_exists($main_file)){
			if($path_format == 'system'){
				//WE WANT A FILE PATH
				return $main_file;
			}
			else{
				//WE WANT A URL
				return '/logic/'.basename($filename, '.php');
			}
		}
		else{
			throw new SystemDisplayablePermanentError('Could not find the specified logic file: '. $filename);					
		}
	}

	static function titleUrlSafe($title){
		// Transforms a title to be used in url
		$title = preg_replace('/[^0-9a-zA-Z-]+/', '-', $title);
		$title = preg_replace('/-+/', '-', $title);
		// Make sure the string can't start or end with a dash
		$title = preg_replace('/(^-)|(-$)/', '', $title);
		return $title;
	}

	static function Redirect($new_page) {
		header('HTTP/1.1 301 Moved Permanently');
		header('Location: ' . $new_page);
		exit;
	}

	static function IsValidEmail($email) {
		return preg_match('/^[A-Z0-9._%+\\-\\#!$%&\'*\/=?^_`{}|~]+@[A-Z0-9.-]+\.[A-Z]{2,10}$/i', $email) > 0;
	}


	static function GenerateUrl($event_id, $title, $citystate) {
		return '/' . LibraryFunctions::titleUrlSafe($title) . '-' .
			LibraryFunctions::titleUrlSafe($citystate) .
			'/event/' . $event_id;
	}
	
	//CONVERT NESTED OBJECT TO PHP ARRAY
	static function objToArray($obj, &$arr){ 

		if(!is_object($obj) && !is_array($obj)){
			$arr = $obj;
			return $arr;
		}

		foreach ($obj as $key => $value)
		{
			if (!empty($value))
			{
				$arr[$key] = array();
				LibraryFunctions::objToArray($value, $arr[$key]);
			}
			else
			{
				$arr[$key] = $value;
			}
		}
		return $arr;
	}



	static function DatetimeIntoDaysAgo($dt) {
		return intval(time() / 86400) - intval($dt->format('U') / 86400);
	}

	static function ToUTF8($input, $ignore_invalid_chars=TRUE) {
		// First take the string and convert it from our ISO-8859-1 encoding to UTF-8
		$value = iconv('ISO-8859-1', 'UTF-8' . ($ignore_invalid_chars ? '//IGNORE' : ''), $input);

		if ($ignore_invalid_chars) {
			// Then using these functions (from http://webcollab.sourceforge.net/unicode.html)
			// remove all invalid UTF-8 byte sequences
			$value = preg_replace(
				'/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]'.
				'|[\x00-\x7F][\x80-\xBF]+' .
				'|([\xC0\xC1]|[\xF0-\xFF])[\x80-\xBF]*' .
				'|[\xC2-\xDF]((?![\x80-\xBF])|[\x80-\xBF]{2,})' .
				'|[\xE0-\xEF](([\x80-\xBF](?![\x80-\xBF]))|(?![\x80-\xBF]{2})|[\x80-\xBF]{3,})/S',
				'', $value );

			$value = preg_replace(
				'/\xE0[\x80-\x9F][\x80-\xBF]'.
				'|\xED[\xA0-\xBF][\x80-\xBF]/S', '', $value );
		}

		return $value;
	}

	static function ArrayToUTF8($input) {
		array_walk_recursive(
			$input,
			function(&$array_item) {
				if (gettype($array_item) === 'string') {
					$array_item = LibraryFunctions::ToUTF8($array_item, TRUE);
				}
			});
		return $input;
	}


	static function Sanitize($value) {
		return strip_tags($value);
	}

	static function HammerCheck($page, $hourly_limit) {
		$hour = intval(time() / 3600);
		$hammer_key = md5(
			'hammer_check_' . $page . '_' . $_SERVER['REMOTE_ADDR'] . '_' . $hour);
		$hour_count = apc_fetch($hammer_key);
		if ($hour_count) {
			if ($hour_count > $hourly_limit) {
				return TRUE;
			}
			apc_store($hammer_key, $hour_count + 1, 3600);
			return FALSE;
		}
		apc_add($hammer_key, 1, 3600);
		return FALSE;
	}


	static function GetFileLock($locknames, $lock_dir='/var/run/') {
		// returns true if the lock can be established
		// $locknames can be a string or an array
		// if it's an array, the function returns true if any
		// of the locks can be established

		if (!is_array($locknames)) {
			$locknames = array($locknames);
		}

		foreach ($locknames as $lockname) {
			$lockfile = $lock_dir . $lockname;

			$global_lock_file_key = '_global_GetFileLock_' . $lockfile;

			if (!file_exists($lockfile)) {
				$GLOBALS[$global_lock_file_key] = fopen($lockfile, 'w');
			} else {
				$GLOBALS[$global_lock_file_key] = fopen($lockfile, 'r');
			}

			if ($GLOBALS[$global_lock_file_key] !== FALSE && flock($GLOBALS[$global_lock_file_key], LOCK_EX | LOCK_NB)) {
				return $GLOBALS[$global_lock_file_key];
			}
		}

		return FALSE;
	}

	static function ReleaseFileLock($lock) {
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	static function VariableLengthHash($str, $len, $salt=NULL) {
		if (!$salt) {
			return substr(sha1($str . 'p5TrupraCrust3me9ac5atH3veTus2fravA9ruvekupRATre9Huc24rekanAtre5'), 0, $len);
		}
		return substr(sha1($str . $salt), 0, $len);
	}

	static function encode($id, $salt=NULL) {
		if (!is_numeric($id) or $id < 1) {return FALSE;}
		$id = (int)$id;
		if ($id > pow(2,31)) {return FALSE;}
		$segment1 = self::VariableLengthHash($id,10,$salt);
		$segment2 = self::VariableLengthHash($segment1,8,$salt);
		$dec      = (int)base_convert($segment2,16,10);
		$dec      = ($dec>$id)?$dec-$id:$dec+$id;
		$segment2 = base_convert($dec,10,16);
		$segment2 = str_pad($segment2,8,'0',STR_PAD_LEFT);
		$segment3 = self::VariableLengthHash($segment1.$segment2,2,$salt);
		$hex      = $segment1.$segment2.$segment3;
		$bin      = pack('H*',$hex);
		$oid      = base64_encode($bin);
		$oid      = str_replace(array('+','/','='),array('$',':',''),$oid);
		return $oid;
	} 

	static function decode($oid, $salt=NULL) {
		if (!preg_match('/^[A-Z0-9\:\$]{12,15}$/i',$oid)) {return 0;}
		$oid      = str_replace(array('$',':'),array('+','/'),$oid);
		$bin      = base64_decode($oid);
		$hex      = unpack('H*',$bin); $hex = $hex[1];
		if (!preg_match('/^[0-9a-f]{20}$/',$hex)) {return 0;}
		$segment1 = substr($hex,0,10);
		$segment2 = substr($hex,10,8);
		$segment3 = substr($hex,18,2);
		$exp2     = self::VariableLengthHash($segment1,8,$salt);
		$exp3     = self::VariableLengthHash($segment1.$segment2,2,$salt);
		if ($segment3 != $exp3) {return 0;}
		$v1       = (int)base_convert($segment2,16,10);
		$v2       = (int)base_convert($exp2,16,10);
		$id       = abs($v1-$v2);
		return $id;
	}

	static function EncodeWithChecksum($key) {
		$key |= $key << 20;
		$checksum = $key % 1024;
		$key |= $checksum << 52;
		return $key;
	}

	static function DecodeWithChecksum($code) {
   		$checksum = $code >> 52;
		if ((($code & 0xFFFFFFFFFFFF) % 1024) != $checksum) {
			return NULL;
		}
		return ($code & 0xFFFFFFFF00000) >> 20;
	}

	static function write_to_log($type, $entry){

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();


		$sql = "INSERT INTO slg_system_logs (slg_type, slg_log_entry) VALUES (:slg_type, :slg_log_entry)";
		try{

			$q = $dblink->prepare($sql);
			$q->bindValue(':slg_type', $type, PDO::PARAM_STR);
			$q->bindValue(':slg_log_entry', $entry, PDO::PARAM_STR);
			$q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return TRUE;
	}



	static function GetLocationData($zip_code=NULL, $city=NULL, $state=NULL) {
		return FALSE;
		/*
		$sql = NULL;
		if ($zip_code) {
			$sql = "SELECT x(zip_code_proj_m), y(zip_code_proj_m), zip_longitude, zip_latitude, zip_timezone
				FROM zips.zip_codes
				WHERE zip_code_id = ? LIMIT 1";
			$bind_params[] = array($zip_code, PDO::PARAM_INT);
			$display_address = $zip_code;
		} else if ($city && $state) {
			$sql = "SELECT x(zip_code_proj_m), y(zip_code_proj_m), zip_longitude, zip_latitude, zip_timezone
				FROM zips.zip_codes
				WHERE zip_city = ? AND zip_state = ? LIMIT 1";
			$bind_params[] = array($city, PDO::PARAM_STR);
			$bind_params[] = array($state, PDO::PARAM_STR);
			$display_address = $city . ', ' . $state;
		}

		if ($sql === NULL) {
			return FALSE;
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		try {
			$q = $dblink->prepare($sql);
			for($i=1;$i<=count($bind_params);$i++) {
				$q->bindValue($i, $bind_params[$i-1][0], $bind_params[$i-1][1]);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		$user_coords = $q->fetch();

		if ($user_coords) {
			return array(
				'x_coord' => $user_coords->x,
				'y_coord' => $user_coords->y,
				'lat_coord' => $user_coords->zip_latitude,
				'lon_coord' => $user_coords->zip_longitude,
				'disp_addr' => $display_address,
				'timezone' => $user_coords->zip_timezone
			);
		}
		return FALSE;
		*/
	}

	static function str_rand($length=8) {
		$code = md5(uniqid('', TRUE));
		return substr($code, 0, $length);
	}

	static function random_string($length=16) {
		// Because the str_rand function only uses 0-9A-F chars
		$chars = 'abcdefghijklmnopqrstuvwxwz0123456789';
		$string = '';
		for ($i = 0; $i < $length; $i++) {
			$rand_key = mt_rand(0, strlen($chars));
			$string  .= substr($chars, $rand_key, 1);
		}
		return str_shuffle($string);
	}

	static function timezone_offset_to_name($offset) {

		//TODO
	}

	static function any_state_to_abbr($state) {
		if (strlen($state) == 2) {
			return strtoupper($state);
		}

		return self::state_to_abbr(ucwords(strtolower($state)));
	}

	static function state_to_abbr($fullstate) {
		require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
		$abbrev = array_search($fullstate, Address::$states);
		return $abbrev;
	}

	static function getCityStateFromIP($ip){
		/*
		$ipnum = ip2long($ip);

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$sql = "
			SELECT region,city FROM geoip.locations
			WHERE id =
				(SELECT location_id FROM geoip.blocks
				WHERE start_ip <= :ip AND :ip <= end_ip LIMIT 1)
				AND city != ''";
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(':ip', $ipnum, PDO::PARAM_INT);
			$q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		$q->setFetchMode(PDO::FETCH_OBJ);

		$ipinfo = $q->fetch();

		if ($ipinfo)	{
			return array(ucwords(strtolower($ipinfo->city)), LibraryFunctions::any_state_to_abbr($ipinfo->region));
		}
		*/

		return FALSE;
	}

	static function array_to_object($array = array()) {
		if (!empty($array)) {
			$data = false;

			foreach ($array as $akey => $aval) {
				$data -> {$akey} = $aval;
			}

			return $data;
		}

		return false;
	}

	static function htmlToText($temphtml) {

		$tempplain = $temphtml;
		$search = "/<style>.*<\/style>/smU";
		$tempplain = preg_replace($search, "", $tempplain);
		$tempplain = str_replace("<br>", "\n", "$tempplain");
		$tempplain = str_replace("<br />", "\n", "$tempplain");
		$tempplain = str_replace("</p>", "\n", "$tempplain");
		$tempplain = str_replace("<BR>", "\n", "$tempplain");
		$tempplain = str_replace("<BR />", "\n", "$tempplain");
		$tempplain = str_replace("</P>", "\n", "$tempplain");
		$tempplain = str_replace("&nbsp;", " ", "$tempplain");
		$tempplain = strip_tags($tempplain);

		return($tempplain);

	}

	static function texttoHTML($temptext){

		return( str_replace( "\n","<br />", $temptext));

	}

	static function fix_price_string($price_string) {
		return trim(str_replace(',', '.', str_replace('$', '', $price_string)));
	}

	//converts display time (HH:MM am/pm) to server time (HH:MM, 24 hour)
	static function toDBTime($timeconv){

		if(is_null($timeconv) || $timeconv == ""){
			return("00:00:00");
		}

		$amsnip = "";
		$pmsnip = "";

		$timeconv = str_replace("AM", "am", $timeconv);
		$timeconv = str_replace("PM", "pm", $timeconv);

		//FIX FOR 12 AM AND 12 PM
		if($timeconv == "12:00 am" || $timeconv == "12:00am"){
			return ("00:00:00");
		}

		if($timeconv == "12:00 pm" || $timeconv == "12:00pm"){
			return ("12:00:00");
		}

		$amsnip = strstr($timeconv, "am");
		$pmsnip = strstr($timeconv, "pm");


		if($amsnip == "am"){
			$timeconv = str_replace($amsnip, "", $timeconv);

			$hours = trim(strtok($timeconv, ":"));
			$mins = trim(strtok(":"));


			if($hours < 10){
				$hours = '0'.$hours;
			}
			$timeconv = $hours . ":" . $mins . ":00";
		}
		else if($pmsnip == "pm"){
			$timeconv = str_replace($pmsnip, "", $timeconv);
			$timeconv = trim($timeconv);

			$hours = trim(strtok($timeconv, ":"));
			if($hours != 12){
				$hours = $hours + 12;
			}
			$mins = trim(strtok(":"));

			$timeconv = $hours . ":" . $mins . ":00";
		}
		else{
			return FALSE;
		}

		return $timeconv;
	}

	static function getTimezoneFromPoint($lat, $long){


		$ch =curl_init();
		$url =  "http://www.earthtools.org/timezone/$lat/$long";
		curl_setopt($ch, CURLOPT_TIMEOUT, 3000);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($ch);
		curl_close($ch);
		$pattern = '/<offset>(.*)<\/offset>/';
		preg_match($pattern, $data, $matches);
		return($matches[1]);

	}

	static function TransformLatLonToProjected($lat, $lon) {
		$sql = 'SELECT
		x(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?),4269),2163)),
		y(ST_Transform(ST_SetSRID(ST_MakePoint(?, ?),4269),2163))';

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $lon, PDO::PARAM_STR);
			$q->bindValue(2, $lat, PDO::PARAM_STR);
			$q->bindValue(3, $lon, PDO::PARAM_STR);
			$q->bindValue(4, $lat, PDO::PARAM_STR);
			$q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		$result = $q->fetch();
		if ($result) {
			return array($result['x'], $result['y']);
		}
		return FALSE;
	}

	static function GetTimezoneFromZipCode($zip_code) {
		$sql = "SELECT zip_timezone FROM zips.zip_codes WHERE zip_code_id = ? LIMIT 1";

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $zip_code, PDO::PARAM_INT);
			$q->execute();
		} catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		$result = $q->fetch();
		if ($result) {
			return $result['zip_timezone'];
		}
		return FALSE;
	}

	//GET CURRENT TIME OBJECT IN SPECIFIED TIMEZONE
	static function get_current_time_obj($tz){
		
		$dt = new DateTime('now', new DateTimeZone($tz)); //first argument "must" be a string
		
		/*
		require_once("Date.php");
		date_default_timezone_set($tz);
		$d = new Date();
		*/

		return ($dt);

	}

	//GET TIME OBJECT IN SPECIFIED TIMEZONE
	static function get_time_obj($time, $tz){
		
		$dt = new DateTime($time, new DateTimeZone($tz)); //first argument "must" be a string
		
		/*
		require_once("Date.php");
		date_default_timezone_set($tz);
		$d = new Date();
		*/

		return $dt;

	}


	//GET CURRENT TIMEZONE ABBREVIATION FOR GIVEN TIME AND TIMEZONE
	static function get_time_abbr($tz, $time){
		
		$dt = new DateTime($time, new DateTimeZone($tz)); //first argument "must" be a string
		
		return $dt->format('T') ;
		/*
		require_once("Date.php");

		$d = new Date();
		$d->setDate($time);
		$t = new Date_TimeZone($tz);
		$abbr = $t->getShortName($d);

		return ($abbr);
		*/

	}

	//GET CURRENT TIME IN SPECIFIED TIMEZONE
	static function get_current_time($tz, $format='Y-m-d, H:i:s'){
		
		$dt = new DateTime('now', new DateTimeZone($tz)); //first argument "must" be a string
		
		return $dt->format($format) ;		
		
		/*
		require_once("Date.php");
		date_default_timezone_set($tz);
		$d = new Date();

		return ($d->format($format));
		*/

	}

	//GET TIME IN NEW FORMAT
	static function reformat_time($time, $format='Y-m-d, H:i:s'){
		if(is_null($time)){
			return FALSE;
		}

		$dt = new DateTime($time, new DateTimeZone('UTC')); //first argument "must" be a string
		
		return $dt->format($format) ;	

		/*
		require_once("Date.php");

		$d = new Date();
		$d->setDate($time);

		return ($d->format($format));
		*/

	}

	static function format_date_and_time($date, $time, $session) {
		if ($date && $time) {
			return LibraryFunctions::convert_time(
				LibraryFunctions::datetoISO8601($date) . ' ' . LibraryFunctions::toDBTime($time),
				$session->get_timezone(), 'UTC');
		} else if ($date) {
			return LibraryFunctions::convert_time(
				LibraryFunctions::datetoISO8601($date) . ' ' . LibraryFunctions::toDBTime('12:00am'),
				$session->get_timezone(), 'UTC');
		}
	}

	//CONVERT TIME FROM ONE TIMEZONE TO ANOTHER
	static function convert_time($starttime, $fromtz, $totz, $format='M j, Y g:i a T'){ 
		if(is_null($starttime)){
			return FALSE;
		}
		
		$dt = new DateTime($starttime, new DateTimeZone($fromtz)); //first argument "must" be a string
		
		$dt->setTimezone(new DateTimeZone($totz));
		
		return $dt->format($format) ;		
	}
	
	//RETURN NEW TIME X DAYS FROM INPUT TIME
	static function time_shift($starttime, $days=7, $format='M j, Y g:i a T'){ 
		if(is_null($starttime)){
			return FALSE;
		}
		
		$dt = new DateTime($starttime); //first argument "must" be a string
		$interval = 'P'.$days.'D';
		$dt->add(new DateInterval($interval));
		
		return $dt->format($format) ;		
		
	}	
	
	
	

	//RETURN A NEW DATETIME THAT IS 'SPAN' AHEAD OF THE ONE PASSED IN
	/*
	static function add_span_to_time($starttime, $spantext, $format='%Y-%m-%d %H:%M:%S'){
		if(is_null($starttime)){
			return FALSE;
		}
		require_once("Date.php");

		$d = new Date();
		$d->setDate($starttime);

		$timespan = new Date_Span($spantext);
		$d->addSpan($timespan);

		return ($d->format($format));

	}
	*/

	//RETURN DIFFERENCE BETWEEN TWO DATES
	/*
	static function diff_mins($starttime, $endtime, $format = '%h'){

		$s = new DateTime($starttime,new DateTimeZone('UTC'));
		$e = new DateTime($endtime,new DateTimeZone('UTC'));
		$diff = $s->diff($e, TRUE);
		return $diff->format($format);

	}
	*/

	//RETURN LAT/LONG FOR CURRENT USER
	static function get_current_lat_lon(){
		$session = SessionControl::get_instance();

		$location_data = $session->get_location_data();
		if ($location_data) {
			$userll->lat = $location_data['lat_coord'];
			$userll->lon = $location_data['lon_coord'];
			return $userll;
		}

		return FALSE;
	}

	// Get center and bounds lat/lon for an array of lats and lons
	static function get_bounds_from_array($x_y_array) {
		$bounds['center']['lat'] = NULL;
		$bounds['center']['lon'] = NULL;
		$bounds['lat']['min'] = NULL;
		$bounds['lat']['max'] = NULL;
		$bounds['lon']['min'] = NULL;
		$bounds['lon']['max'] = NULL;

		$latsum = 0;
		$lonsum = 0;
		$pointcount = 0;

		foreach($x_y_array as $lat_lon) {
			list($x, $y) = $lat_lon;
			$latsum += $y;
			$lonsum += $x;
			++$pointcount;

			if(is_null($bounds['lat']['min']) || $bounds['lat']['min'] > $y) {
				$bounds['lat']['min'] = $y;
			}
			if(is_null($bounds['lat']['max']) || $bounds['lat']['max'] < $y) {
				$bounds['lat']['max'] = $y;
			}
			if(is_null($bounds['lon']['min']) || $bounds['lon']['min'] > $x) {
				$bounds['lon']['min'] = $x;
			}
			if(is_null($bounds['lon']['max']) || $bounds['lon']['max'] < $x) {
				$bounds['lon']['max'] = $x;
			}
		}

		$bounds['numpoints'] = $pointcount;
		$bounds['center']['lat'] = $latsum / $pointcount;
		$bounds['center']['lon'] = $lonsum / $pointcount;

		$lat_fudge = ($bounds['lat']['max'] - $bounds['lat']['min']) * .1;
		$lon_fudge = ($bounds['lon']['max'] - $bounds['lon']['min']) * .1;
		// Fudge all of the edges by 5% or so
		$bounds['lat']['min'] -= $lat_fudge;
		$bounds['lat']['max'] += $lat_fudge;
		$bounds['lon']['min'] -= $lon_fudge;
		$bounds['lon']['max'] += $lon_fudge;

		return $bounds;
	}

	//RETURN CENTER AND BOUNDS LAT/LONG FOR RESULTS AND CURRENT USER
	static function get_bounds_lat_lon($results, $userll){
		if(!$userll && count($results) == 0){
			return FALSE;
		}

		//GET THE MAP BOUNDS
		$bounds = array();
		if($userll){
			$bounds['center']['lat'] = $userll->lat;
			$bounds['center']['lon'] = $userll->lon;
			$bounds['lat']['min'] = $userll->lat;
			$bounds['lat']['max'] = $userll->lat;
			$bounds['lon']['min'] = $userll->lon;
			$bounds['lon']['max'] = $userll->lon;
		}
		else{
			$bounds['center']['lat'] = NULL;
			$bounds['center']['lon'] = NULL;
			$bounds['lat']['min'] = NULL;
			$bounds['lat']['max'] = NULL;
			$bounds['lon']['min'] = NULL;
			$bounds['lon']['max'] = NULL;
		}

		$latsum=0;
		$lonsum=0;
		$pointcount=0;
		foreach($results as $result) {
			if($result->usa_privacy > 1){
				$x = $result->x_priv;
				$y = $result->y_priv;
			}
			else {
				$x = $result->x;
				$y = $result->y;
			}

			$latsum += $y;
			$lonsum += $x;
			++$pointcount;

			if(is_null($bounds['lat']['min']) || $bounds['lat']['min'] > $y) {
				$bounds['lat']['min'] = $y;
			}
			if(is_null($bounds['lat']['max']) || $bounds['lat']['max'] < $y) {
				$bounds['lat']['max'] = $y;
			}
			if(is_null($bounds['lon']['min']) || $bounds['lon']['min'] > $x) {
				$bounds['lon']['min'] = $x;
			}
			if(is_null($bounds['lon']['max']) || $bounds['lon']['max'] < $x) {
				$bounds['lon']['max'] = $x;
			}
		}

		if (!$pointcount) {
			return $bounds;
		}

		$bounds['numpoints'] = $pointcount;
		$bounds['center']['lat'] = $latsum / $pointcount;
		$bounds['center']['lon'] = $lonsum / $pointcount;

		$lat_fudge = ($bounds['lat']['max'] - $bounds['lat']['min']) * .1;
		$lon_fudge = ($bounds['lon']['max'] - $bounds['lon']['min']) * .1;
		// Fudge all of the edges by 5% or so
		$bounds['lat']['min'] -= $lat_fudge;
		$bounds['lat']['max'] += $lat_fudge;
		$bounds['lon']['min'] -= $lon_fudge;
		$bounds['lon']['max'] += $lon_fudge;

		return $bounds;
	}



	static function check_required($haystack, $needles) {
		foreach($needles as $needle => $error) {
			if (!array_key_exists($needle, $haystack) || !$haystack[$needle]) {
				throw new SystemDisplayableError($error);
			}
		}
	}

	/*********************************************************************
	//FETCH A VARIABLE FROM $_GET, $_POST, OR LOCAL SCOPE
	$varname - Name of var to fetch.
	$defaultvalue - If not found, will be returned as variable value.
	$required - 1 or 0.  If 1, error will be thrown if variable not found.
	$errortext - Text of error if $required.

	*********************************************************************/
	static function fetch_variable($varname, $defaultvalue, $required=FALSE, $errortext='Some information needed for this page is not present.', $safemode=TRUE){

		if(isset($GLOBALS[$varname])){
			if($safemode){
				return strip_tags($GLOBALS[$varname]);
			}
			else{
				return $GLOBALS[$varname];
			}
		}

		if(isset($_REQUEST[$varname])){
			if($safemode){
				return strip_tags($_REQUEST[$varname]);
			}
			else{
				return $_REQUEST[$varname];
			}
			
		}

		if ($required==1){
			throw new SystemDisplayablePermanentError($errortext);
		}

		return $defaultvalue;

	}

	/*********************************************************************
	//FETCH A VARIABLE FROM ARRAY PASSED INTO A FUNCTION
	$source - Name of source array
	$varname - Name of var to fetch.
	$defaultvalue - If not found, will be returned as variable value.
	$required - 1 or 0.  If 1, error will be thrown if variable not found.
	$errortext - Text of error if $required.

	*********************************************************************/
	static function fetch_variable_local($source, $varname, $defaultvalue, $required=FALSE, $errortext=''){
		if(isset($source[$varname])){
			return $source[$varname];
		}

		if ($required==1) {
			throw new SystemDisplayableError($errortext);
		}

		return $defaultvalue;

	}

	/*********************************************************************
	Edit_Table

	Adds a row or updates a row in a table based on whether that row already exists.


	INPUT:
	$dbhelper - Database helper object;  	$dbhelper = new DbConnector();
	$dblink - Database connection object;   $dblink = $dbhelper->get_db_link();
	$tablename - Name of the table to be updated.  Note, this variable is not filtered for SQL injection.
	$p_keys - Associative array of primary keys to the table in $keyname=>$keyval form.  If empty or NULL, row will be added.
	$rowdata - Associative array of column names and values to update in the table in $colname=>$colval form.
	$use_transaction - To avoid race conditions, to get the last inserted id, this function uses a transaction.  A value of '1' here
		will use a transaction.  If you are wrapping more than one edit_table call in a transaction, pass '0' here.
	$debug - If set to 1, prints out sql.

	NOTES:  Variables with the value "-NOUPDATE-" will not be updated or inserted.

	RETURNS:
	If edit, returns p_keys array.
	If add, returns the new sequence number that corresponds to the new row.  If sequence doesn't exist, returns -1;

	**********************************************************************/

    static function edit_table($dbhelper, $dblink, $tablename, $p_keys, $rowdata, $use_transaction, $debug=0){
		if($debug){
			echo '<pre>';
			echo "Table: $tablename\n";
			//print_r($p_keys);
			//print_r($rowdata);
			foreach ($rowdata as $col=>$val){
				echo "[$col]=>";
				if(is_null($val)) {
					echo 'NULL';
				}
				else if($val === '') {
					echo "''";
				}
				else if($val === FALSE) {
					echo "FALSE";
				}
				else if($val === TRUE) {
					echo "TRUE";
				}
				else  {
					echo "$val";
				}
				echo "\n";
			}
			if(is_null($p_keys)){
				echo 'pkeys is null ' . "\n";
			}
			echo 'Number of Keys: '. count($p_keys) . "\n";
		}


		if($use_transaction && !$debug){
			$dblink->beginTransaction();
		}


    	$numkeys = count($p_keys);

    	if(count($rowdata) == 0){
    		return FALSE;
    	}

		$dataphrase='';
    	if($numkeys == 0 || is_null($p_keys)){
    		$op = 'add';
    		$sql = 'INSERT INTO ' . $tablename . ' ';

    		$colphrase="";
    		$valphrase="";
    		foreach($rowdata as $column_name=>$column_val){
    			if((string)$column_val != "-NOUPDATE-"){
    				$colphrase .= $column_name . ',';
    					$valphrase .= ':' . $column_name . ',';

    			}
    		}

    		$colphrase[strlen($colphrase)-1] = ' ';
    		$valphrase[strlen($valphrase)-1] = ' ';

    		$sql .= '(' . $colphrase . ') VALUES (' . $valphrase . ') ';
    	}
    	else{
    		$op = 'edit';
    		$sql = 'UPDATE ' . $tablename . ' SET ';

    		foreach($rowdata as $column_name=>$column_val){
    			if((string)$column_val != "-NOUPDATE-"){
    					$sql .= $column_name . '=:' . $column_name . ',';
    			}
    		}

    		$sql[strlen($sql)-1] = ' ';

    	}

		//ADD WHERE CLAUSE
		if($op == 'edit'){
			$sql .= 'WHERE ';
			foreach($p_keys as $pname=>$pvalue){
				$sql .= $pname . '=:' . $pname . ' ';
				$sql .= ' AND ';
			}
			//REMOVE THE LAST ' AND '
			$sql = substr($sql, 0, strlen($sql)-5);
		}

		//GET COLUMN METADATA
		$columnsql = "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name ='$tablename'";
		$results = $dblink->query($columnsql);
		$column_meta = array();
		while ($row = $results->fetch(PDO::FETCH_OBJ)){
			$column_meta[$row->column_name]['data_type'] = $row->data_type;
			$column_meta[$row->column_name]['is_nullable'] = $row->is_nullable;
		}


		//BIND VALUES AND PREPARE STATEMENT
		$q = $dblink->prepare($sql);

		foreach($rowdata as $column_name=>$column_val){
			if((string)$column_val != "-NOUPDATE-"){
				if($column_meta[$column_name]['data_type'] == 'integer' || $column_meta[$column_name]['data_type'] == 'smallint'){
					$q->bindValue(":$column_name", $column_val, PDO::PARAM_INT);
				}
				else if($column_meta[$column_name]['data_type'] == 'boolean'){
					if($column_val===NULL){
						//BUG FIX, TEMPORARY
						if($column_meta[$column_name]['is_nullable'] == 'YES') {
							$q->bindValue(":$column_name", NULL, PDO::PARAM_BOOL);
						} else {
							$q->bindValue(":$column_name", FALSE, PDO::PARAM_BOOL);
						}
					}
					else if($column_val==TRUE){
						$q->bindValue(":$column_name", TRUE, PDO::PARAM_BOOL);
					}
					else if($column_val==FALSE){
						$q->bindValue(":$column_name", FALSE, PDO::PARAM_BOOL);
					}
				}
				else{
					$q->bindValue(":$column_name", $column_val, PDO::PARAM_STR);
				}
			}
    	}

		if($op == 'edit'){
			foreach($p_keys as $pname=>$pvalue){
				$pbindcol = '$p_keys[\'' . $pname . '\']';
				if($column_meta[$pname]['data_type'] == 'integer' || $column_meta[$pname]['data_type'] == 'smallint'){
					$q->bindValue(":$pname", $pvalue, PDO::PARAM_INT);
				}
				else{
					$q->bindValue(":$pname", $pvalue, PDO::PARAM_STR);
				}
			}
		}

		if($debug){

			echo $q->debugDumpParams();
			echo '</pre>';
			exit();
		}

		$q->execute();
		if($op == 'edit'){
			if($use_transaction){
				$dblink->commit();
			}
			return $p_keys;
		}
		else{
			$seq = $tablename . '_' . substr($tablename, 0, strlen($tablename)-1) . '_id_seq';
			$pkeyname = substr($tablename, 0, strlen($tablename)-1) . '_id';

			//CHECK TO SEE IF SEQUENCE EXISTS
			$columnsql = "SELECT COUNT(*) FROM information_schema.sequences WHERE sequence_name ='$seq'";
			$results = $dblink->query($columnsql);
			$seq_exists = $results->fetch(PDO::FETCH_OBJ);

			if($seq_exists->count == 1){
				$returnval = array($pkeyname => $dblink->lastInsertId($seq));
			}
			else{
				//RETURNS TRUE ON ADD IF THERE IS NO SERIAL VALUE PRESENT (THIS NEEDS TO BE ALTERED TO RETURN THE PKEY)
				$returnval = TRUE;
			}

			if($use_transaction){
				$dblink->commit();
			}
			return($returnval);
		}

    }


	/*
		Paul's Simple Diff Algorithm v 0.1
		(C) Paul Butler 2007 <http://www.paulbutler.org/>
		May be used and distributed under the zlib/libpng license.

		Given two arrays, the function diff will return an array of the changes.
		I won't describe the format of the array, but it will be obvious
		if you use print_r() on the result of a diff on some test data.

		htmlDiff is a wrapper for the diff command, it takes two strings and
		returns the differences in HTML. The tags used are <ins> and <del>,
		which can easily be styled with CSS.
	*/

	static function diff($old, $new){
		$maxlen=0;
		foreach($old as $oindex => $ovalue){
			$nkeys = array_keys($new, $ovalue);
			foreach($nkeys as $nindex){
				$matrix[$oindex][$nindex] = isset($matrix[$oindex - 1][$nindex - 1]) ?
					$matrix[$oindex - 1][$nindex - 1] + 1 : 1;
				if($matrix[$oindex][$nindex] > $maxlen){
					$maxlen = $matrix[$oindex][$nindex];
					$omax = $oindex + 1 - $maxlen;
					$nmax = $nindex + 1 - $maxlen;
				}
			}
		}
		if($maxlen == 0) return array(array('d'=>$old, 'i'=>$new));
		return array_merge(
			LibraryFunctions::diff(array_slice($old, 0, $omax), array_slice($new, 0, $nmax)),
			array_slice($new, $nmax, $maxlen),
			LibraryFunctions::diff(array_slice($old, $omax + $maxlen), array_slice($new, $nmax + $maxlen)));
	}

	static function htmlDiff($old, $new){
		$ret='';
		$diff = LibraryFunctions::diff(explode(' ', $old), explode(' ', $new));
		foreach($diff as $k){
			if(is_array($k))
				$ret .= (!empty($k['d'])?"<del>".implode(' ',$k['d'])."</del> ":'').
					(!empty($k['i'])?"<ins>".implode(' ',$k['i'])."</ins> ":'');
			else $ret .= $k . ' ';
		}
		return $ret;
	}
}
?>
