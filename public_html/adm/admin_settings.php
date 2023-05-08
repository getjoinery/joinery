<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();

	if($_POST){
		if($settings->get_setting('preview_image') != $_POST['preview_image']){
			//AUTO INCREMENT THE PREVIEW IMAGE INDEX IF IT HAS CHANGED
			$search_criteria = array();
			$search_criteria['setting_name'] = 'preview_image_increment';
			$user_settings = new MultiSetting(
				$search_criteria,
				NULL,
				NULL,
				NULL,
				NULL
			);
			$user_settings->load();	
			foreach($user_settings as $user_setting) {
				if($user_setting->get('stg_name') == 'preview_image_increment'){
					$user_setting->set('stg_value', $settings->get_setting('preview_image_increment') + 1);
					$user_setting->set('stg_update_time', 'NOW()'); 
					$user_setting->set('stg_usr_user_id', $session->get_user_id());
					$user_setting->prepare();
					$user_setting->save();					
				}
			}			
		}

		$search_criteria = array();
		//$search_criteria['setting_like'] = $searchterm;
		$user_settings = new MultiSetting(
			$search_criteria,
			NULL,
			NULL,
			NULL,
			NULL);
		$user_settings->load();		 

		foreach($user_settings as $user_setting) {
			if(isset($_POST[$user_setting->get('stg_name')])){
				$user_setting->set('stg_value', $_POST[$user_setting->get('stg_name')]);
				$user_setting->set('stg_update_time', 'NOW()'); 
				$user_setting->set('stg_usr_user_id', $session->get_user_id());
				$user_setting->prepare();
				$user_setting->save();
			}
		}				

	}
	
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'page_title' => 'Settings',
		'readable_title' => 'Settings',
		'breadcrumbs' => array(
			'Settings'=>'', 
		),
		'session' => $session,
	)
	);	
	



	$pageoptions['altlinks'] = array('New Setting'=>'/admin/admin_setting_edit');
	$pageoptions['altlinks'] += array('Public Menu'=>'/admin/admin_public_menu');
	$pageoptions['altlinks'] += array('Admin Menu'=>'/admin/admin_admin_menu'); 
	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['stg_value']['required']['value'] = 'true';
	$validation_rules['stg_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_settings');
	
	echo '<h3>General Settings</h3>';
	
	echo $formwriter->textbox('Custom CSS', 'custom_css', 'ctrlHolder', 10, 80, $settings->get_setting('custom_css'), '', 'no');
	
	echo $formwriter->textinput("Preview image (for facebook, google, etc)", 'preview_image', "ctrlHolder", 20, $settings->get_setting('preview_image'), "" , 255, "");


	$optionvals = array("Use built in tracking"=>'internal', 'Use custom tracking' => 'custom');
	echo $formwriter->dropinput("Visit tracking", "register_active", "ctrlHolder", $optionvals, $settings->get_setting('register_active'), '', FALSE);	
	echo $formwriter->textinput("Tracking code", "tracking_code", "ctrlHolder", 20, $settings->get_setting('tracking_code'), "" , 255, "");	
	
	
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Registration active", "register_active", "ctrlHolder", $optionvals, $settings->get_setting('register_active'), '', FALSE);	

	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Default timezone", "default_timezone", "ctrlHolder", $optionvals, $settings->get_setting('default_timezone'), '', FALSE); 

	echo $formwriter->textinput("Nickname display as (blank for no nicknames)", "nickname_display_as", "ctrlHolder", 20, $settings->get_setting('nickname_display_as'), "" , 255, "");	


	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Require email activation to log on", "activation_required_login", "ctrlHolder", $optionvals, $settings->get_setting('activation_required_login'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Newsletter active", "newsletter_active", "ctrlHolder", $optionvals, $settings->get_setting('newsletter_active'), '', FALSE);	
	
	echo $formwriter->textinput("Emails to receive subscription notifications (separate with comma)", "subscription_notification_emails", "ctrlHolder", 20, $settings->get_setting('subscription_notification_emails'), "" , 255, "");	

	echo $formwriter->textinput("Emails to receive one time purchase notifications (separate with comma)", "single_purchase_notification_emails", "ctrlHolder", 20, $settings->get_setting('single_purchase_notification_emails'), "" , 255, "");	

	$optionvals = array("US Dollar"=>'usd', 'Euro' => 'eur'); 
	echo $formwriter->dropinput("Site Currency", "site_currency", "ctrlHolder", $optionvals, $settings->get_setting('site_currency'), '', FALSE);	

	$optionvals = array("Stripe Regular"=>'stripe_regular', 'Stripe Checkout' => 'stripe_checkout'); 
	echo $formwriter->dropinput("Checkout Type", "checkout_type", "ctrlHolder", $optionvals, $settings->get_setting('checkout_type'), '', FALSE);	
	
	echo $formwriter->textbox('Robots.txt entry', 'robots_text', 'ctrlHolder', 10, 80, $settings->get_setting('robots_text'), '', 'no');

	echo '<h3>Survey Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Survey module active", "surveys_active", "ctrlHolder", $optionvals, $settings->get_setting('surveys_active'), '', FALSE);	

	echo '<h3>Blog Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Blog module active", "blog_active", "ctrlHolder", $optionvals, $settings->get_setting('blog_active'), '', FALSE);

	$optionvals = array("/"=>'/', '/post' => '/post');
	echo $formwriter->dropinput("Default blog subdirectory", "blog_subdirectory", "ctrlHolder", $optionvals, $settings->get_setting('blog_subdirectory'), '', FALSE);


	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Show comments", "show_comments", "ctrlHolder", $optionvals, $settings->get_setting('show_comments'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Allow comments", "comments_active", "ctrlHolder", $optionvals, $settings->get_setting('comments_active'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Allow comments from unregistered users", "comments_unregistered_users", "ctrlHolder", $optionvals, $settings->get_setting('comments_unregistered_users'), '', FALSE);	

	$optionvals = array("Approved"=>'approved', 'Unapproved' => 'unapproved');
	echo $formwriter->dropinput("Default comment status", "default_comment_status", "ctrlHolder", $optionvals, $settings->get_setting('default_comment_status'), '', FALSE);

	echo $formwriter->textinput("Emails to receive comment notifications (separate with comma)", "comment_notification_emails", "ctrlHolder", 20, $settings->get_setting('comment_notification_emails'), "" , 255, "");		

	echo $formwriter->textinput("Comment anti spam word (blank for none)", "anti_spam_answer_comments", "ctrlHolder", 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use captcha on comments", "use_captcha_comments", "ctrlHolder", $optionvals, $settings->get_setting('use_captcha_comments'), '', FALSE);	

	
 
	echo '<hr>';
 
 	echo '<h3>Spam Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use form honeypots", "use_honeypot", "ctrlHolder", $optionvals, $settings->get_setting('use_honeypot'), '', FALSE);	

	echo $formwriter->textinput("Anti spam word (blank for none)", "anti_spam_answer", "ctrlHolder", 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Use captcha", "use_captcha", "ctrlHolder", $optionvals, $settings->get_setting('use_captcha'), '', FALSE);	
	
	echo '<hr>';

 	echo '<h3>Social Settings</h3>';

	echo $formwriter->textinput("Facebook link", "social_facebook_link", "ctrlHolder", 20, $settings->get_setting('social_facebook_link'), "" , 255, "");	

	echo $formwriter->textinput("Instagram link", "social_instagram_link", "ctrlHolder", 20, $settings->get_setting('social_instagram_link'), "" , 255, "");
		
	echo $formwriter->textinput("Soundcloud link", "social_soundcloud_link", "ctrlHolder", 20, $settings->get_setting('social_soundcloud_link'), "" , 255, "");
			
	echo $formwriter->textinput("Spotify link", "social_spotify_link", "ctrlHolder", 20, $settings->get_setting('social_spotify_link'), "" , 255, "");
				
	echo $formwriter->textinput("Youtube link", "social_youtube_link", "ctrlHolder", 20, $settings->get_setting('social_youtube_link'), "" , 255, "");
					
	echo $formwriter->textinput("Mixcloud link", "social_mixcloud_link", "ctrlHolder", 20, $settings->get_setting('social_mixcloud_link'), "" , 255, "");

	echo $formwriter->textinput("Discord link", "social_discord_link", "ctrlHolder", 20, $settings->get_setting('social_discord_link'), "" , 255, "");
	
	echo $formwriter->textinput("Google link", "social_google_link", "ctrlHolder", 20, $settings->get_setting('social_google_link'), "" , 255, "");
	
	echo $formwriter->textinput("Linkedin link", "social_linkedin_link", "ctrlHolder", 20, $settings->get_setting('social_linkedin_link'), "" , 255, "");
	
	echo $formwriter->textinput("Pinterest link", "social_pinterest_link", "ctrlHolder", 20, $settings->get_setting('social_pinterest_link'), "" , 255, "");
	
	echo $formwriter->textinput("Stack Overflow link", "social_stack_link", "ctrlHolder", 20, $settings->get_setting('social_stack_link'), "" , 255, "");
	
	echo $formwriter->textinput("Telegram link", "social_telegram_link", "ctrlHolder", 20, $settings->get_setting('social_telegram_link'), "" , 255, "");
	
	echo $formwriter->textinput("Tiktok link", "social_tiktok_link", "ctrlHolder", 20, $settings->get_setting('social_tiktok_link'), "" , 255, "");
	
	echo $formwriter->textinput("Snapchat link", "social_snapchat_link", "ctrlHolder", 20, $settings->get_setting('social_snapchat_link'), "" , 255, "");
	
	echo $formwriter->textinput("Slack link", "social_slack_link", "ctrlHolder", 20, $settings->get_setting('social_slack_link'), "" , 255, "");
	echo $formwriter->textinput("Github link", "social_github_link", "ctrlHolder", 20, $settings->get_setting('social_github_link'), "" , 255, "");
	echo $formwriter->textinput("Reddit link", "social_reddit_link", "ctrlHolder", 20, $settings->get_setting('social_reddit_link'), "" , 255, "");
	echo $formwriter->textinput("Whatsapp link", "social_whatsapp_link", "ctrlHolder", 20, $settings->get_setting('social_whatsapp_link'), "" , 255, "");
	echo $formwriter->textinput("Twitch link", "social_twitch_link", "ctrlHolder", 20, $settings->get_setting('social_twitch_link'), "" , 255, "");

	echo '<h3>Booking Settings</h3>';

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Booking module active", "bookings_active", "ctrlHolder", $optionvals, $settings->get_setting('bookings_active'), '', FALSE);

	echo $formwriter->textbox('Calendly api token', 'calendly_api_token', 'ctrlHolder', 10, 80, $settings->get_setting('calendly_api_token'), '', 'no');

	echo $formwriter->textinput("Calendly organization uri", "calendly_organization_uri", "ctrlHolder", 20, $settings->get_setting('calendly_organization_uri'), "" , 255, "");

 	echo '<h3>Events Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Event module active", "events_active", "ctrlHolder", $optionvals, $settings->get_setting('events_active'), '', FALSE);


	
 	echo '<h3>Product Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Product module active", "products_active", "ctrlHolder", $optionvals, $settings->get_setting('products_active'), '', FALSE);


	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("List regular products on product index", "products_list_items_active", "ctrlHolder", $optionvals, $settings->get_setting('products_list_items_active'), '', FALSE);	
	
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("List event products on product index", "products_list_events_active", "ctrlHolder", $optionvals, $settings->get_setting('products_list_events_active'), '', FALSE);	

	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Allow coupon codes", "coupons_active", "ctrlHolder", $optionvals, $settings->get_setting('coupons_active'), '', FALSE);
	
	echo '<hr>';

	echo '<h3>File Hosting Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("File hosting module active", "files_active", "ctrlHolder", $optionvals, $settings->get_setting('files_active'), '', FALSE);	

	echo '<h3>Video Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Video module active", "videos_active", "ctrlHolder", $optionvals, $settings->get_setting('videos_active'), '', FALSE);

	echo '<h3>CMS Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("CMS module active", "page_contents_active", "ctrlHolder", $optionvals, $settings->get_setting('page_contents_active'), '', FALSE);

	echo '<h3>Url Rewrite Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Url rewrite module active", "urls_active", "ctrlHolder", $optionvals, $settings->get_setting('urls_active'), '', FALSE);


	echo '<h3>Email Settings</h3>';
	$optionvals = array("Yes"=>'1', 'No' => '0');
	echo $formwriter->dropinput("Email module active", "emails_active", "ctrlHolder", $optionvals, $settings->get_setting('emails_active'), '', FALSE);	

	$templates = new MultiMailingList(
		array('deleted' => false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$outer_optionvals = $templates->get_dropdown_array();
	echo $formwriter->dropinput("Default mailing list", "default_mailing_list", "ctrlHolder", $outer_optionvals, $settings->get_setting('default_mailing_list'), '', TRUE);	
	
	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_OUTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$outer_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$inner_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_FOOTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$footer_optionvals = $templates->get_dropdown_array();

	echo $formwriter->dropinput("Bulk email outer template", "bulk_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('bulk_outer_template'), '', FALSE);	
	echo $formwriter->dropinput("Bulk email footer", "bulk_footer", "ctrlHolder", $footer_optionvals, $settings->get_setting('bulk_footer'), '', FALSE);	
	echo $formwriter->dropinput("Individual email inner template", "individual_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('individual_email_inner_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email footer template", "group_email_footer_template", "ctrlHolder", $footer_optionvals, $settings->get_setting('group_email_footer_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email outer template", "group_email_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('group_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Group email inner template", "group_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('group_email_inner_template'), '', FALSE);
	echo $formwriter->dropinput("Event email footer template", "event_email_footer_template", "ctrlHolder", $footer_optionvals, $settings->get_setting('event_email_footer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email outer template", "event_email_outer_template", "ctrlHolder", $outer_optionvals, $settings->get_setting('event_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email inner template", "event_email_inner_template", "ctrlHolder", $inner_optionvals, $settings->get_setting('event_email_inner_template'), '', FALSE);
	
	//$optionvals = array("General"=>'general', 'Emails' => 'emails');
	//echo $formwriter->dropinput("Setting group", "stg_group_name", "ctrlHolder", $optionvals, $setting->get('stg_group_name'), '', FALSE);


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();


	$page->admin_footer();

?>
