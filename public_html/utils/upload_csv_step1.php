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
	$promoid=$_GET['promoid'];
	$getvars = "?promoid=$promoid";
}
else if(isset($_GET['emailid'])){
	$emailid=$_GET['emailid'];
	$getvars = "?emailid=$emailid";
}
else{
	echo 'you must pass the proper id.';
	exit();
}


$max_size = 5000000; // the max. size for uploading

if(isset($_POST['ignore_1stline'])){
	$ignore_1stline = $_POST['ignore_1stline'];
	$getvars .= "&ignore_1stline=$ignore_1stline";
}
else{
	$ignore_1stline = 0;
}

echo '<div id="contents">';
	echo '<h1>Upload CSV</h1>';


if($_POST) {

	//DEAL WITH THE UPLOADED FILE
	$my_upload = new file_upload;

	require_once(__DIR__ . '/../includes/PathHelper.php');
	$my_upload->upload_dir = PathHelper::getAbsolutePath("/admin/upload/"); // "files" is the folder for the uploaded files (you have to create this folder)
	$my_upload->extensions = array(".csv"); // specify the allowed extensions here
	$my_upload->max_length_filename = 100; // change this value to fit your field length in your database (standard 100)
	$my_upload->rename_file = false;
	$my_upload->replace = y;

	//REMOVE APOSTROPHES AND QUOTATION MARKS
	$_FILES['upload']['name'] = remove_magic_quotes($_FILES['upload']['name']);

	$_FILES['upload']['name'] = str_replace("'", "", $_FILES['upload']['name']);
	$_FILES['upload']['name'] = str_replace('"', '', $_FILES['upload']['name']);



	//print_r($_FILES);

	$my_upload->the_temp_file = $_FILES['upload']['tmp_name'];
	$my_upload->the_file = $_FILES['upload']['name'];
	$my_upload->http_error = $_FILES['upload']['error'];

	echo $my_upload->show_error_string();

	if ($my_upload->upload()) {

		$filelocation = $my_upload->upload_dir . $my_upload->the_file;

		$handle = fopen($filelocation, "r");
		if($handle === FALSE){
			echo 'The upload failed or the file failed to open.';
			exit();
		}
		$currentline = fgetcsv($handle, 1000, ",");
		fclose($handle);

		printf ('<form method="post" class="form" action="upload_csv_step2.php%s" accept-charset="ISO-8859-1">', $getvars);
			echo "<fieldset><h4>Please match the fields in the uploaded file with the database fields.</h4>";
				echo '<div class="fields full">';


		//BUILD THE FIELD LIST
		$optionvals = array();
		$tmpQuery = "SHOW COLUMNS FROM emails_recipients";
		$tempresult = $connector->query($tmpQuery);
		while (($temprow = mysql_fetch_object($tempresult))){
			if($temprow->Field != "emails_recipientid" && $temprow->Field != "promoid" && $temprow->Field != "emailid" && $temprow->Field != "sent" && $temprow->Field != "cancel"){
				$optionvalentry = array($temprow->Field => $temprow->Field);
				array_push_associative($optionvals, $optionvalentry);
			}
		}

		//ADD AN IGNORE OPTION
		$optionvalentry = array("IGNORE" => "IGNORE");
		array_push_associative($optionvals, $optionvalentry);

		foreach ($currentline as $currentword){
			dropinput("<b>$currentword</b> matches with", "fieldlist[]", $optionvals, "IGNORE");
		}

		hiddeninput("filelocation", $filelocation);

		echo '</div>';
		echo '</fieldset>';


		printf('<input type="submit" value="submit">');
		printf('</form>');


		//unlink($filelocation);
	}
}
else{

	printf('<form name="form1" class="form" enctype="multipart/form-data" method="post" action="%s%s">', $_SERVER['PHP_SELF'], $getvars);
			echo "<fieldset><h4>Upload a csv mailing list. Max. filesize = $max_size bytes.  Only .csv files accepted.</h4>";
				echo '<div class="fields full">';

	  				hiddeninput("MAX_FILE_SIZE", $max_size);
	  				fileinput("Select csv file to upload", "upload", 32, $retrievekey);

	  				checkboxinput("Ignore the first line of the file", "ignore_1stline", NULL, 1, $retrievekey);


				echo '</div>';
			echo '</fieldset>';

	echo '<input type="submit" name="Submit" value="Upload">
	</form>';

	echo '<div style="clear:both; margin-top:40px;"><p><strong>Note:</strong><br />Salutation uses the format Dear X: where X will be determined in this order:</p>
	<ol>
	<li>If you upload data into the "recipient_nickname" field, X will be the value in "recipient_nickname".</li>
	<li>If (1) fails and you have uploaded data into the "recipient_firstname" field, X will be the value in "recipient_firstname".</li>
	<li>If (2) fails and you have uploaded data into the "recipient_prefix" AND "recipient_lastname" field, X will be "recipient_prefix recipient_lastname".</li>
	<li>If none of the above are true, there will be no salutation at all.</li>
	</ol></div>
	';

}

echo '</div>';
GenerateHTMLFooter();
?>









