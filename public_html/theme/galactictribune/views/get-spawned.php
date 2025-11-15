<?php
	
	echo '<h3>Spawned children of point '. (int)$_REQUEST['pointnum'] .'</h3>';
	
	$results= json_decode(exec('node /var/www/html/test/node/get-spawned.js '.$_REQUEST['pointnum']));
	echo count($results). ' results<br><br>';
	
	foreach ($results as $result){
		echo $result.'<br>';
	}
	

?>