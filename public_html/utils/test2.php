<?php
	/*require_once('../includes/Globalvars.php');
	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');	
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';	
	require_once($siteDir.'/includes/Activation.php');
	require_once($siteDir.'/includes/ShoppingCart.php');
		require_once($siteDir . '/data/files_class.php');
	require_once($siteDir . '/data/orders_class.php');
	require_once($siteDir . '/data/products_class.php');
	require_once($siteDir . '/data/posts_class.php');
	require_once($siteDir . '/data/users_class.php');
	require_once($siteDir . '/data/groups_class.php');
	require_once($siteDir . '/data/product_details_class.php');
	require_once($siteDir . '/data/event_registrants_class.php');
	require_once($siteDir . '/includes/EmailTemplate.php');
	require_once($siteDir . '/data/email_templates_class.php');
*/
	require_once($siteDir . '/includes/ControlDHelper.php');



	//LOAD ALL CLASSES 
	$path = $_SERVER['DOCUMENT_ROOT']."/data";
	$classNames = array();
	if ($handle = opendir($path)) {
		while (false !== ($file = readdir($handle))) {
			if ('.' === $file) continue;
			if ('..' === $file) continue;
			$filepath = $path.'/'.$file;
			
			$file_parts = pathinfo($file);
			if($file_parts['extension'] == 'php'){
				if(file_exists($filepath)){
					if (str_contains($file, '_class')) {					
						require_once($filepath);
						
						
						$fileContent = file_get_contents($filepath);
						$tokens = token_get_all($fileContent);

						//$classNames = [];
						for ($i = 0; $i < count($tokens); $i++) {
							if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
								$classNames[] = $tokens[$i + 2][1];
							}
						}						
						
					}
				}
			}
		}
		closedir($handle);
	}
	
	
	
	$plugins = LibraryFunctions::list_plugins();
	$pluginClasses = array();
	foreach($plugins as $plugin){
		$plugin_data_dir = $_SERVER['DOCUMENT_ROOT'].'/plugins/'.$plugin.'/data';

		if ($handle = opendir($plugin_data_dir)) {
			while (false !== ($file = readdir($handle))) {
				if ('.' === $file) continue;
				if ('..' === $file) continue;
				$filepath = $plugin_data_dir.'/'.$file;
				$file_parts = pathinfo($file);
				if($file_parts['extension'] == 'php'){
					if (str_contains($file, '_class')) {
						require_once($filepath);

						$fileContent = file_get_contents($filepath);
						$tokens = token_get_all($fileContent);

						//$classNames = [];
						for ($i = 0; $i < count($tokens); $i++) {
							if ($tokens[$i][0] === T_CLASS && $tokens[$i + 2][0] === T_STRING) {
								$pluginClasses[] = $tokens[$i + 2][1];
							}
						}	
					}
				}
			}
			closedir($handle);
		}
	}	
	
	foreach($pluginClasses as $class){
		if(isset($class::$tablename)){
			echo $class."<br>\n";
		}
		
	}
exit;











exit;





    $cd = new ControlDHelper();
	
	$data = array('name'=>'Testprofile6');
	print_r($cd->listSchedules());
	exit;
	//$result = $cd->createProfile($data);
	
	

	//prevent_deactivation_pin
	$success = $result['success'];
	$profile_key = $result['body']['profiles'][0]['PK'];
	$profile_key = '665222jfk3qk';
	$device_id = 'qbe2yv8az8';
	$org_id = '2116jfkhk4';

	$data = array(
		'prevent_deactivation_pin' => '1234'
		);
	print_r($cd->modifyDevice($device_id, $data));

exit;


//print_r($cd->listRuleFolders($profile_key));
//print_r($cd->createRuleFolder($profile_key, 'test1', 1, 0));
//print_r($cd->getRuleFolderIdFromName($profile_key, 'test1'));
//print_r($cd->deleteRuleFolder($profile_key, 'test', 1));
// print_r($cd->listRules($profile_key, 0));
// print_r($cd->deleteRule($profile_key, 'drudgereport.com'));
 //print_r($cd->createRule($profile_key, 1, array('drudgereport.com'), 0));
// print_r($cd->listRules($profile_key, 0));
  //print_r($cd->listServicesOnProfile($profile_key));
	//print_r($cd->modifyProfileFilter($profile_key, 'ads', 0));
	//print_r($cd->modifyProfileFilter($profile_key, 'ads_small', 1));
	
	
	//print_r($cd->listNativeFilters($profile_key));
	//print_r($cd->deleteDevice('h5xtge0va1'));
	//print_r($cd->listProfiles());

	

exit;
	use Mailgun\Mailgun;
	
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';
use MailchimpAPI\Mailchimp;
exit;

$user = new User(47, True);
$results = User::CheckForDuplicate($user, array('usr_first_name', 'usr_email_is_verified'));
print_r($results);
exit;
		$post = new Post(47, TRUE);
		$post->set('pst_usr_user_id',a);
		
				
		
		$post->prepare();
		$post->save();
		$post->load();
exit;

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		/*
		$p_keys = array('odi_order_item_id' => 122);
		$rowdata = array('param1' => 11);
		
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'usa_users_addrsaaa', $p_keys, $rowdata, FALSE, 0);
*/
			
		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "SELECT usa_users_addr_id FROM usa_users_addrs
			WHERE usa_usr_user_id = :usr_user_id AND usa_is_default = TRUE";

		try{
			$q = $dblink->prepare($sql);
			//$q->bindValue(':usr_user_id', 111, PDO::PARAM_INT);
			$dbhelper->bind_value(':usr_user_id', 111, PDO::PARAM_INT);
			$dbhelper->execute_query();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		

echo 'turned off';
exit;
/*
error_reporting(0);
function sub()
{
$sub=6-1;
echo "The sub= ".$sub;
}
div();


	//if($_GET('page') >= 1){}
	exit();
	
	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session_id = $_GET['session_id'];
	
	print_r($_SERVER["REQUEST_URI"]);
	$ext = pathinfo(strtolower($_SERVER["REQUEST_URI"]), PATHINFO_EXTENSION); // Using strtolower to overcome case sensitive
	print_r($ext);
	
	exit();
	require_once($siteDir . '/includes/calendar-links/Link.php');
	require_once($siteDir . '/includes/calendar-links/Generator.php');
	require_once($siteDir . '/includes/calendar-links/Generators/Google.php');
	require_once($siteDir . '/includes/calendar-links/Generators/Ics.php');
	use Spatie\CalendarLinks\Link;
	$from = DateTime::createFromFormat('Y-m-d H:i', '2018-02-01 09:00');
$to = DateTime::createFromFormat('Y-m-d H:i', '2018-02-01 18:00');

$link = Link::create('Sebastian\'s birthday', $from, $to)
    ->description('Cookies & cocktails!')
    ->address('Kruikstraat 22, 2018 Antwerpen');

// Generate a link to create an event on Google calendar
echo '<a href="'.$link->google().'">google</a>';

// Generate a link to create an event on Yahoo calendar
//echo $link->yahoo();

// Generate a link to create an event on outlook.com calendar
//echo $link->webOutlook();

// Generate a data uri for an ics file (for iCal & Outlook)
echo $link->ics();

// Generate a data uri using arbitrary generator:
echo $link->formatWith(new \Your\Generator());
	
	
	
	
	
exit();
	
	
		$events = new MultiEvent(
		array(),
		NULL,
		NULL,
		NULL);
		$events->load();	
		
		foreach($events as $event){
			echo $event->get('evt_name').'<br>';
			echo $event->create_url(). '<br>';
			$event->set('evt_link', $event->create_url());
			$event->save();
		}
	
	
	
	exit();


			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>'plain_html.html'), NULL, NULL, NULL);
			$templates->load();

			if($this_template = $templates->get(0)){
				$outer_template = $this_template->get('emt_body');
			}
			else{
				throw new EmailTemplateError('We could not find the template '. $inner_template);
			}				
		
	print_r($outer_template);
	exit();
	
	
	$settings = Globalvars::get_instance();

	require_once($siteDir.'/includes/AcuityScheduling.php');
exit();

	$settings = Globalvars::get_instance();
	$acuity = new AcuityScheduling(array(
	  'userId' => $settings->get_setting('acuity_user_id'),
	  'apiKey' => $settings->get_setting('acuity_api_key')
	));

	$appointments = $acuity->request('/appointments?email=amthao15@gmail.com');
	
	foreach($appointments as $appointment){
		echo $appointment['firstName'] . ' ' . $appointment['lastName'] . '('.$appointment['email'].')';
		echo '<br />';
		echo $appointment['type'] . ' ' . $appointment['calendar']. ' ' . $appointment['location'];
		echo '<br />';
		$dt = new DateTime($appointment['datetime']);
		//echo $dt->format('M j, Y g:i a T');
		echo LibraryFunctions::convert_time($dt->format('M j, Y g:i a'), $dt->format('T'), $session->get_timezone());
		echo '<br />';
	}
	echo '*';
	print_r($appointments);
	exit();


	$settings = Globalvars::get_instance();
	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();


	
	$email = 'noahrseltzer@gmail.com';
	echo LibraryFunctions::IsValidEmail($email);
	
	exit();
	
	
	
	$settings = Globalvars::get_instance();

	for ($x=0; $x<=2700; $x+=50){
		echo $x.'<br>';
		$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));
		$return = $mailchimp
		->lists('9ec43a9fbf')
		->members()
		->get([
			"count" => "50", 
			"offset" => $x
		]);
		$results = $return->deserialize();
		foreach ($results->members as $result){ 
			$user = User::GetByEmail($result->email_address);
			if($user){
				if($result->status == 'subscribed'){
					
					$user->save();
				}
				else{
					
					$user->save();			
				}
			}
		}
	}
	exit();
	*/
	
	$user= new User(1, TRUE);
	
	$billing_users = new MultiUser(
	array(),
	NULL,
	500, 
	2500);
	$billing_users->load();
	
	

	
	

	exit();
	?>
	
	
	
<!DOCTYPE html>
<html>
<head>
 <title>Tutorial selectDB remote ajax using jquery,php and mysql by seegatesite.com</title>
 <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" rel="stylesheet" />
</head>
<body>
 <h4>Tutorial selectDB remote ajax using jquery,php and mysql by <a href="https://seegatesite.com"></a>seegatesite.com</a></h4>
 <div>
 <select class="select2"></select>
 </div>
 <script
 src="https://code.jquery.com/jquery-2.2.4.js"
 integrity="sha256-iT6Q9iMJYuQiMWNd9lDyBUStIq/8PuOW33aOqmvFpqI="
 crossorigin="anonymous"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>
 <script type="text/javascript">
 $(".select2").select2({
 placeholder: "Search country here...",
 width: '175px',
 ajax: {
 url: "ajax.php",
 dataType: 'json',
 delay: 250,
 data: function (params) {
 return {
           q: params.term, // search term
           page: params.page
       };
   },
   processResults: function (data, params) {
    params.page = params.page || 1;
    return {
    results: data.items,
    pagination: {
    more: (params.page * 30) < data.total_count
    }
    };
   },
   cache: false
 },
 // escapeMarkup: function (markup) { return markup; }, // let our custom formatter work
 minimumInputLength: 1,
 templateResult: formatRepo,
 templateSelection: formatRepoSelection
 });
 
 function formatRepo (repo) {
 if (repo.loading) return repo.text;
 return repo.desc;
 }
 
 function formatRepoSelection (repo) {
 return repo.desc || repo.text;
 }
 </script>
</body>
</html>
	

  <input class='form-control col-lg-5 itemSearch' type='text' placeholder='select item' />
  <script type="text/javascript"> 
  
$(".itemSearch").select2({
    tags: true,
    multiple: true,
    tokenSeparators: [',', ' '],
    minimumInputLength: 2,
    minimumResultsForSearch: 10,
    ajax: {
        url: URL,
        dataType: "json",
        type: "GET",
        data: function (params) {

            var queryParameters = {
                term: params.term
            }
            return queryParameters;
        },
        processResults: function (data) {
            return {
                results: $.map(data, function (item) {
                    return {
                        text: item.tag_value,
                        id: item.tag_id
                    }
                })
            };
        }
    }
});
	</script>
	<?php
	
	
	
	
	
	
	
	
	
	
	
	exit();
	
	$billing_users = new MultiUser(
	array(),
	NULL,
	10, 
	2000
		);
	$billing_users->load();
	
	
	
	foreach ($billing_users as $billing_user){
		
		$osearch_criteria = array();
		$osearch_criteria['user_id'] = $billing_user->key;
		//$search_criteria['deleted'] = FALSE;

		//CHECK ON STRIPE 
		if($billing_user->get('usr_stripe_customer_id')){
			$stripe_id = $billing_user->get('usr_stripe_customer_id');
			echo ' found ';

			$orders = new MultiOrder(
				$osearch_criteria,
				array('ord_order_id'=>'DESC'),
				NULL,
				NULL);
			$numorders = $orders->count_all();
			echo $billing_user->key;
			if($numorders){
				$stripe_customer = \Stripe\Customer::update(
				$stripe_id,
				[
					//'name' => $billing_user->get('usr_first_name'). ' ' . $billing_user->get('usr_last_name'),
					'description' => $billing_user->get('usr_first_name'). ' ' . $billing_user->get('usr_last_name') . ' ('.$billing_user->get('usr_email').')',
				]);
				echo ' updated <br />';
			}
			else{
				//echo ' searching ';
				//$stripe_customer = \Stripe\Customer::all(["email" => $billing_user->get('usr_email')]);
				//$stripe_id = $stripe_customer['data'][0]['id'];
				
				//if($stripe_id){	
				//	$billing_user->set('usr_stripe_customer_id', $stripe_id);
				//	$billing_user->save();	
				//}			
			}

		}

		//echo '<br />';
	}
	
	//$stripe_customer = \Stripe\Customer::all(["email" => $billing_user->get('usr_email')]);
	//print_r($stripe_customer);
	
	
	
	
	
	
	
	
	
	
	
	
	exit();
	
	$cl = new CartLog(NULL);
	$cl->set('cls_vse_visitor_id', $session->get_uniqid());
	$cl->set('cls_usr_user_id_logged_in', $session->get_user_id());
	$cl->set('cls_usr_user_id_billing', $session->get_user_id());
	$cl->set('cls_file', $_SERVER['PHP_SELF']);
	$cl->set('cls_os', SessionControl::getOS());
	$cl->set('cls_browser', SessionControl::getBrowser());
	$cl->set('cls_context', print_r($cart, true));
	$cl->prepare();
	$cl->save();

	
	
	
	
	
	
	
exit();
echo 'starting';
			$file =	new File(143, TRUE);
			$file->permanent_delete();
			//$file->delete_resized();
			//$file->resize();
echo 'done';

exit();

try
{
	$old_path = '/var/www/html/uploads/test.jpg';
	$new_path = '/var/www/html/uploads/test1.jpg';
	$img = new Imagick($old_path);
	$img->thumbnailImage(500 , 500 , TRUE);
	$img->writeImage($new_path);
	
	$count++;
}
catch(Exception $e)
{
	echo 'Caught exception: ',  $e->getMessage(), '\n';
	$error++;
}
exit();

	/*
	$settings = Globalvars::get_instance();
	$mg = new Mailgun($settings->get_setting('mailgun_api_key'));
	$domain = $settings->get_setting('mailgun_domain');
 
 
 				$email = new EmailTemplate('blank_template');
				$email->add_recipient('jeremy.tunnell+3@gmail.com', 'Jeremy 3');
				$email->add_recipient('jeremy@jeremytunnell.com', 'Jeremy');
				$email->fill_template(array(
					'subject' => 'test',
					'body' => 'test email',
				));
				
				$email->email_from = 'info@integralzen.org';
				$email->email_from_name = 'IZ';
				$result = $email->send();
				
				print_r($result);
 */

	exit();


	\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));

	$search_criteria['disabled'] = FALSE;
	$users = new MultiUser($search_criteria, NULL, 400,2400);

	$numrecords = $users->count_all();
	$users->load();
	
	foreach($users as $user){
		$results = \Stripe\Customer::all(["email" => $user->get("usr_email")]);
		if(count($results) > 1){
			$numsubs = 0;
			foreach($results as $result){
				$subs = \Stripe\Subscription::all(['limit' => 5, 'customer' => $result['id'], 'status' => 'all']);
				foreach($subs as $sub) {
					$numsubs++;
				}
			}
			if($numsubs > 0){
				echo $user->display_name(). ': '.$numsubs.'<br />';
			}

		}
	}
	echo 'done, last user:' . $user->key;





exit();


	$session = SessionControl::get_instance();
	$session->check_permission(8);


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'page_title' => 'Edit Event',
		'readable_title' => 'Edit Event',
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "Edit Event";
	//$page->begin_box($pageoptions);
	?>
	<button class="uk-button uk-button-default" type="button">Hover</button>
<div uk-dropdown>
    <ul class="uk-nav uk-dropdown-nav">
        <li class="uk-active"><a href="#">Active</a></li>
        <li><a href="#">Item</a></li>
        <li class="uk-nav-header">Header</li>
        <li><a href="#">Item</a></li>
        <li><a href="#">Item</a></li>
        <li class="uk-nav-divider"></li>
        <li><a href="#">Item</a></li>
    </ul>
</div>
	<?php
	echo '<div>test</div>';

	//$page->end_box();

	$page->admin_footer();

?>