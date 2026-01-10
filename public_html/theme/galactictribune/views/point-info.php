<?php

	// Validate Urbit point input (numeric 0-4294967295 or @p format)
	$raw_input = trim($_REQUEST['pointnum'] ?? '');
	if ($raw_input === '') {
		echo 'Enter a point to query.';
		exit();
	}

	// Validate and sanitize input
	if (is_numeric($raw_input)) {
		$num = (int)$raw_input;
		if ($num < 0 || $num > 4294967295) {
			http_response_code(400);
			die('Invalid point number. Must be 0-4294967295.');
		}
		$query_number = (string)$num;
		$safe_number = $query_number;
		// Get name from number
		$results = json_decode(exec('node /var/www/html/test/node/number-to-name.js '.$safe_number));
		$query_name = $results;
		$safe_name = escapeshellarg($query_name);
	} elseif (preg_match('/^~[a-z]{3}$/', $raw_input) ||
	          preg_match('/^~[a-z]{6}$/', $raw_input) ||
	          preg_match('/^~[a-z]{6}-[a-z]{6}$/', $raw_input)) {
		$query_name = $raw_input;
		$safe_name = escapeshellarg($raw_input);
		// Get number from name
		$results = json_decode(exec('node /var/www/html/test/node/name-to-number.js '.$safe_name));
		$query_number = (int)$results;
		$safe_number = (string)$query_number;
	} else {
		http_response_code(400);
		die('Invalid point format. Use numeric ID or @p name (e.g., ~zod, ~marzod, ~sampel-palnet).');
	}


	echo '<h3>Info for point '. htmlspecialchars($query_name, ENT_QUOTES, 'UTF-8') . ' (' . (int)$query_number .')</h3>';


	$results = json_decode(exec('node /var/www/html/test/node/point-type.js '.$safe_name));
	echo 'Type: '. htmlspecialchars($results, ENT_QUOTES, 'UTF-8') .'<br>';

	$results = json_decode(exec('node /var/www/html/test/node/point-info.js '.$safe_number));

	echo 'Active: '. ($results->active ? 'yes' : 'no').'<br>';
	echo 'Owner: '. htmlspecialchars($results->owner, ENT_QUOTES, 'UTF-8') .'<br>';
	$sponsor = json_decode(exec('node /var/www/html/test/node/number-to-name.js '. (int)$results->sponsor));
	echo 'Sponsor: '. htmlspecialchars($sponsor, ENT_QUOTES, 'UTF-8') . '('. (int)$results->sponsor .')<br>';

	$results = json_decode(exec('node /var/www/html/test/node/get-spawn-count.js '.$safe_number));

	echo '# points spawned: '. (int)$results .'<br>';


	$results = json_decode(exec('node /var/www/html/test/node/has-been-linked.js '.$safe_number));

	echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';

	$results = json_decode(exec('node /var/www/html/test/node/is-live.js '.$safe_number));

	echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
?>