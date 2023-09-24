    <?php
	require_once( __DIR__ . '/../includes/Globalvars.php');

		$public_key = 'public_fq9l8bwujz45ejq2';
		$secret_key = 'test1';

		$access_token = '';	
		$curl=curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => 'https://jeremytunnell.net/api/v1/user/41',
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'DELETE',
		  CURLOPT_HTTPHEADER => array(
			"public_key: ".$public_key,
			"secret_key: ".$secret_key,
		  ),
		));	


		$response = curl_exec($curl);
		curl_close($curl);
		print_r( json_decode($response, true));
		exit;
?>