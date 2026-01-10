<?php

	// Validate Urbit point input (numeric 0-4294967295 or @p format)
	$raw_input = trim($_REQUEST['pointnum'] ?? '');
	if ($raw_input === '') {
		http_response_code(400);
		die('Missing pointnum parameter.');
	}
	if (is_numeric($raw_input)) {
		$num = (int)$raw_input;
		if ($num < 0 || $num > 4294967295) {
			http_response_code(400);
			die('Invalid point number. Must be 0-4294967295.');
		}
		$pointnum = (string)$num;
	} elseif (preg_match('/^~[a-z]{3}$/', $raw_input) ||
	          preg_match('/^~[a-z]{6}$/', $raw_input) ||
	          preg_match('/^~[a-z]{6}-[a-z]{6}$/', $raw_input)) {
		$pointnum = escapeshellarg($raw_input);
	} else {
		http_response_code(400);
		die('Invalid point format. Use numeric ID or @p name (e.g., ~zod, ~marzod, ~sampel-palnet).');
	}

	echo '<h3>Unspawned children of point '. htmlspecialchars($raw_input, ENT_QUOTES, 'UTF-8') .'</h3>';

	$results= json_decode(exec('node /var/www/html/test/node/get-unspawned-children.js '.$pointnum));
	
	echo count($results). ' results<br><br>';
	
	foreach ($results as $result){
		echo $result.'<br>';
	}
	

?>