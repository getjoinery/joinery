<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

$session = SessionControl::get_instance();

if($_SESSION['permission'] == 10){
	echo '		<style type="text/css">
	table.example3 {background-color:transparent;border-collapse:collapse;width:100%;}
	table.example3 th, table.example3 td {text-align:center;border:1px solid black;padding:5px;}
	table.example3 th {background-color:AntiqueWhite;}
	table.example3 td:first-child {width:20%;}
	</style>';
	echo '<div id="admin_panel" style="display:none;">';
	echo '<table class = "example3"><th colspan=2>Session</th>';
	foreach($_SESSION as $sname=>$svar){
		echo '<tr><td>'.$sname . '</td><td>';
		if(is_array($svar)){
			print_r($svar);
		}
		else if(is_object($svar)){
			var_dump($svar);
		}
		else{
			echo $svar;
		}
		echo '</td></tr>';
	}
	echo '</table><br /><table class = "example3"><th colspan=2>Request</th>';
	foreach($_REQUEST as $sname=>$svar){
		echo '<tr><td>'.$sname . '</td><td>';
		if(is_array($svar)){
			print_r($svar);
		}
		else if(is_object($svar)){
			var_dump($svar);
		}
		else{
			echo $svar;
		}
		echo '</td></tr>';
	}		
	echo '</table></div>';
	
	echo '<script>

	$(document).ready(function() {
	$("#admintoggle").click(function () {
	$("#admin_panel").toggle()
	});
	});
	</script>';		
}
?>