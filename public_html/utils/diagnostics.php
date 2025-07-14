<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/SessionControl.php');	
	
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
	
	if($_REQUEST['password'] != 'setupinfo'){
		echo 'Bad access password';
		exit;
	}
#
# This is a test program for the portable PHP password hashing framework.
#
# Written by Solar Designer and placed in the public domain.
# See PasswordHash.php for more information.
#

error_reporting(E_ALL | E_STRICT);

require "../includes/PasswordHash.php";

header("Content-type: text/plain");


echo 'TESTING MCRYPT AVAILABILITY';

if(defined("MCRYPT_MODE_ECB")){
	echo 'Mcrypt is available';
}
else{
	echo 'Mcrypt is not available';
}






echo "TESTING ENCRYPTION\n";


$ok = 0;

# Try to use stronger but system-specific hashes, with a possible fallback to
# the weaker portable hashes.
$t_hasher = new PasswordHash(8, FALSE);

$correct = "test12345";
$hash = $t_hasher->HashPassword($correct);

print "Hash: " . $hash . "\n";

$check = $t_hasher->CheckPassword($correct, $hash);
if ($check) $ok++;
print "Check correct: '" . $check . "' (should be '1')\n";

$wrong = "test12346";
$check = $t_hasher->CheckPassword($wrong, $hash);
if (!$check) $ok++;
print "Check wrong: '" . $check . "' (should be '0' or '')\n";

unset($t_hasher);

# Force the use of weaker portable hashes.
$t_hasher = new PasswordHash(8, TRUE);

$hash = $t_hasher->HashPassword($correct);

print "Hash: " . $hash . "\n";

$check = $t_hasher->CheckPassword($correct, $hash);
if ($check) $ok++;
print "Check correct: '" . $check . "' (should be '1')\n";

$check = $t_hasher->CheckPassword($wrong, $hash);
if (!$check) $ok++;
print "Check wrong: '" . $check . "' (should be '0' or '')\n";

# A correct portable hash for "test12345".
# Please note the use of single quotes to ensure that the dollar signs will
# be interpreted literally.  Of course, a real application making use of the
# framework won't store password hashes within a PHP source file anyway.
# We only do this for testing.
$hash = '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0';

print "Hash: " . $hash . "\n";

$check = $t_hasher->CheckPassword($correct, $hash);
if ($check) $ok++;
print "Check correct: '" . $check . "' (should be '1')\n";

$check = $t_hasher->CheckPassword($wrong, $hash);
if (!$check) $ok++;
print "Check wrong: '" . $check . "' (should be '0' or '')\n";

if ($ok == 6)
	print "All tests have PASSED\n";
else
	print "Some tests have FAILED\n";

echo "\n\n\n";
echo "TESTING PASSWORD HASHING\n";
$password = 'thispassword';
echo "Password: ".$password."\n";
$hashedpw = password_hash($password, PASSWORD_BCRYPT);
echo "Hashed password: ".$hashedpw."\n";
$verified = password_verify($password, $hashedpw);
print_r(password_get_info($hashedpw));

if($verified){
	echo "CHECK PASSED\n";
}
else{
	echo "CHECK FAILED\n";
}

?>


<?php
echo "TESTING PDO DRIVER\n";

$dbusername = $settings->get_setting('dbusername');
$dbname = $settings->get_setting('dbname');
$dbpassword = $settings->get_setting('dbpassword');
foreach(PDO::getAvailableDrivers() as $driver)
    {
    echo 'Driver: '.$driver."\n";
    }

echo "TESTING DB CONNECTION\n";
echo 'Connecting to '.$dbname.' with user '.$dbusername.' and password ending '.substr($dbpassword, -3).".\n";
try {
    $db = new PDO("pgsql:dbname=".$dbname.";host=localhost", $dbusername, $dbpassword );
    echo "PDO connection object created";
    }
catch(PDOException $e)
    {
    echo $e->getMessage();
    }


echo "\n\n\n\n";
echo "TESTING TIME FUNCTIONS\n";
//$session = SessionControl::get_instance();
$tz = 'UTC';
$tz2 = 'America/New_York';
$time = '2020-03-12 13:00:11.184756'."\n";
echo 'Input time: '. $time;
echo 'Converted from UTC to UTC: '.LibraryFunctions::convert_time($time, 'UTC', 'UTC'). "\n";
echo 'Converted from UTC to America/New_York: '.LibraryFunctions::convert_time($time, 'UTC', $tz2). "\n";


$dt = new DateTime($time, new DateTimeZone($tz)); //first argument "must" be a string
echo 'Formatted input time: '.$dt->format('d.m.Y, H:i:s e T');
echo "\n";

$dt->setTimezone(new DateTimeZone($tz2));

echo 'Input time with timezone: '.$dt->format('d.m.Y, H:i:s e T') ;



echo "\n\n\n\n\n";
	echo phpinfo(INFO_GENERAL);


?>