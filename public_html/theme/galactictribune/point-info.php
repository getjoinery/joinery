<?php

	if(is_numeric($_REQUEST['pointnum'])){
		$query_number = (int)$_REQUEST['pointnum'];
		$results= json_decode(exec('node /var/www/html/test/node/number-to-name.js '.$query_number));
		$query_name = $results;
	}
	else if ($_REQUEST['pointnum']) {
		$query_name = $_REQUEST['pointnum'];
		$results= json_decode(exec('node /var/www/html/test/node/name-to-number.js '.$query_name));
		$query_number = $results;		
	}
	else{
		echo 'Enter a point to query.';
		exit();
	}
	
	
	echo '<h3>Info for point '. $query_name . ' (' . $query_number .')</h3>';
	
	
	$results= json_decode(exec('node /var/www/html/test/node/point-type.js '.$query_name));
	echo 'Type: '.$results.'<br>';
	
	$results= json_decode(exec('node /var/www/html/test/node/point-info.js '.$query_number));
	
	echo 'Active: '. ($results->active ? 'yes' : 'no').'<br>';
	echo 'Owner: '. $results->owner.'<br>';
	$sponsor= json_decode(exec('node /var/www/html/test/node/number-to-name.js '.$results->sponsor));
	echo 'Sponsor: '. $sponsor . '('.$results->sponsor.')<br>';
	
	$results= json_decode(exec('node /var/www/html/test/node/get-spawn-count.js '.$query_number));

	echo '# points spawned: '. $results.'<br>';
	

	$results= json_decode(exec('node /var/www/html/test/node/has-been-linked.js '.$query_number));

	echo 'Has been booted: '. ($results ? 'yes' : 'no').'<br>';
	
	$results= json_decode(exec('node /var/www/html/test/node/is-live.js '.$query_number));

	echo 'Is Live: '. ($results ? 'yes' : 'no').'<br>';
?>