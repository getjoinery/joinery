<?php
//REQUIRE THE OBJECT FILES AND THE TEMPLATES
require_once('./includes/DbConnector.php');
require_once('./includes/templates.php');
require_once('./includes/forminput_new.php');
require_once('./includes/login_class.php');
require_once('./includes/upload_class.php');
require_once('./includes/Validator.php');


$login = new CheckLogin();
$login->CheckRegular(4);

$connector = new DbConnector();

GenerateHeader(2);

if(isset($_GET['promoid'])){
	$promoid = (int)$_GET['promoid'];
	$getvars = "?promoid=$promoid";
}
else if(isset($_GET['emailid'])){
	$emailid = (int)$_GET['emailid'];
	$getvars = "?emailid=$emailid";
}
else{
	echo 'you must pass the proper id.';
	exit();
}

$count=0;
$ignore=0;
if($_GET['ignore_1stline'] == 1){
	$ignore=1;
}



echo '<div id="contents">';
	echo '<h1>Upload CSV</h1>';


$filelocation = $_POST['filelocation'];
$fieldlist = $_POST['fieldlist'];


$handle = fopen($filelocation, "r");
if($handle === FALSE){
	echo 'The upload failed or the file failed to open.';
	exit();
}

//RUN A CHECK ON THE UPLOADED FILE AND ERROR OUT IF ANY LINE DOESN'T MATCH THE LENGTH OF OTHER LINES
//IT USES THE LENGTH OF THE FIRST LINE AS THE BENCHMARK, SO IF LINE 1 IS INCORRECT IT WILL TELL YOU THAT
//EVERY OTHER LINE IS BAD
$numfieldscheck=NULL;
$linenum=1;
$badlinenums = array();
while (($currentline = fgetcsv($handle, 1000, ",")) !== FALSE) {

	$numfields = count($currentline);
	if(is_null($numfieldscheck)){
		$numfieldscheck = $numfields;
	}
	else{
		if($numfieldscheck != $numfields){
			array_push($badlinenums, $linenum);
		}
	}
	$linenum++;
}

if(count($badlinenums) > 0){
	echo '<p><b>The csv file you uploaded is invalid.  The following lines do not match the length of the others.  You can try to fix this problem by removing all of the columns from the csv file except the ones you want to upload (or copy and paste those columns into a new csv file)</b></p>';
	echo '<ul>';
	foreach($badlinenums as $badlinenum){
		echo "<li>Line $badlinenum does not match</li>";
	}
	echo '</ul>';
	exit();
}

//MANDATORY FIELDS
if(!in_array('recipient_email', $fieldlist)){
	echo 'Sorry, you must upload an email address field.';
	exit();
}




echo 'Passed file checks...<br />';


$handle = fopen($filelocation, "r");
if($handle === FALSE){
	echo 'The upload failed or the file failed to open.';
	exit();
}

while (($currentline = fgetcsv($handle, 1000, ",")) !== FALSE) {


	if($ignore == 0){

		//CHECK THE NUMBER OF ENTRIES
		$numfields = count($currentline);

		$dbqueryarray = array();
		for ($i=0;$i<$numfields;$i++) {
			//echo $fieldlist[$i] . "->" . $currentline[$i]. "<br />";
			$nextdbadd = array($fieldlist[$i]=>$currentline[$i]);
			array_push_associative($dbqueryarray, $nextdbadd);

		}


		$count++;
		$insertQuery = "INSERT IGNORE emails_recipients (";

		foreach($dbqueryarray as $field=>$fieldval){
			if($field != "IGNORE"){
				$insertQuery .= $field . ',';
			}
		}


		if(isset($_GET['promoid'])){
			$insertQuery .= promoid . ',';
		}
		else if(isset($_GET['emailid'])){
			$insertQuery .= emailid . ',';
		}

		$insertQuery .= ") VALUES (";

		foreach($dbqueryarray as $field=>$fieldval){
			if($field != "IGNORE"){

				//CHECK FOR EMAIL IN EACH ROW
				if($field == "recipient_email"){
					if(!strpos($fieldval, '@')){
						echo "Row $count has invalid email: $fieldval , skipping.<br>";
						continue(2);
					}
				}

				$fieldval= addslashes ($fieldval);

				$insertQuery .= "'" . $fieldval . "',";
			}
		}

		if(isset($_GET['promoid'])){
			$insertQuery .= "'" . $promoid . "',";
		}
		else if(isset($_GET['emailid'])){
			$insertQuery .= "'" . $emailid . "',";
		}



		$insertQuery .= ')';

		$insertQuery = str_replace(',)', ')', $insertQuery);
		$connector->query($insertQuery);
	}
	$ignore=0;

}
fclose($handle);


if(isset($_GET['promoid'])){
	$Query = "UPDATE promos SET send_status='recipients_uploaded' WHERE promoid=$promoid";
}
else if(isset($_GET['emailid'])){
	$Query = "UPDATE emails SET send_status='recipients_uploaded' WHERE emailid=$emailid";
}


$connector->query($Query);

echo "<p>$count uploaded.</p>";
unlink($filelocation);

echo '</div>';

GenerateHTMLFooter();
//}
?>









