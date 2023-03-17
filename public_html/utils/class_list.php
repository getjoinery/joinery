<?php

	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');

	require_once( __DIR__ . '/../data/activation_codes_class.php');	
	require_once( __DIR__ . '/../data/address_class.php');
	require_once( __DIR__ . '/../data/admin_menus_class.php');
	require_once( __DIR__ . '/../data/bookings_class.php');
	require_once( __DIR__ . '/../data/comments_class.php');
	require_once( __DIR__ . '/../data/content_versions_class.php');
	require_once( __DIR__ . '/../data/coupon_codes_class.php');
	require_once( __DIR__ . '/../data/coupon_code_products_class.php');
	require_once( __DIR__ . '/../data/debug_email_logs_class.php');
	require_once( __DIR__ . '/../data/emails_class.php');
	require_once( __DIR__ . '/../data/email_recipients_class.php');
	require_once( __DIR__ . '/../data/email_templates_class.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_logs_class.php');
	require_once( __DIR__ . '/../data/event_registrants_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');
	require_once( __DIR__ . '/../data/event_session_files_class.php');
	require_once( __DIR__ . '/../data/session_analytics_class.php');
	require_once( __DIR__ . '/../data/event_types_class.php');
	require_once( __DIR__ . '/../data/files_class.php');
	require_once( __DIR__ . '/../data/general_errors_class.php');
	require_once( __DIR__ . '/../data/groups_class.php');
	require_once( __DIR__ . '/../data/group_members_class.php');
	require_once( __DIR__ . '/../data/log_form_errors_class.php');
	require_once( __DIR__ . '/../data/messages_class.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/order_items_class.php');
	require_once( __DIR__ . '/../data/page_contents_class.php');
	require_once( __DIR__ . '/../data/phone_number_class.php');
	require_once( __DIR__ . '/../data/posts_class.php');
	require_once( __DIR__ . '/../data/products_class.php');
	require_once( __DIR__ . '/../data/product_versions_class.php');
	require_once( __DIR__ . '/../data/product_details_class.php');
	require_once( __DIR__ . '/../data/product_groups_class.php');
	require_once( __DIR__ . '/../data/public_menus_class.php');
	require_once( __DIR__ . '/../data/questions_class.php');
	require_once( __DIR__ . '/../data/question_options_class.php');
	require_once( __DIR__ . '/../data/queued_email_class.php');
	//require_once( __DIR__ . '/../data/recurring_mailer_class.php');
	require_once( __DIR__ . '/../data/settings_class.php');	
	require_once( __DIR__ . '/../data/stripe_invoices_class.php');	
	require_once( __DIR__ . '/../data/surveys_class.php');	
	require_once( __DIR__ . '/../data/survey_answers_class.php');	
	require_once( __DIR__ . '/../data/survey_questions_class.php');	
	require_once( __DIR__ . '/../data/urls_class.php');	
	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/videos_class.php');	
	require_once( __DIR__ . '/../data/visitor_events_class.php');	
	require_once( __DIR__ . '/../data/pages_class.php');	
	require_once( __DIR__ . '/../data/components_class.php');
	require_once( __DIR__ . '/../data/product_requirements_class.php');
	require_once( __DIR__ . '/../data/product_requirement_instances_class.php');
	require_once( __DIR__ . '/../data/order_item_requirements_class.php');
	require_once( __DIR__ . '/../data/email_recipient_groups_class.php');
	require_once( __DIR__ . '/../data/contact_types_class.php');

	$classes = array(
			'Address',
			'AdminMenu', 
			'Booking',
			'Comment',
			'ContentVersion',
			'CouponCode',
			'CouponCodeProduct',
			'DebugEmailLog',
			'Email',
			'EmailRecipient',
			'EmailTemplateStore',
			'Event',
			'EventLog',
			'EventRegistrant',
			'EventSession',
			'EventSessionFile',
			'SessionAnalytic',
			'EventType',
			'File',
			'GeneralError',
			'Group',
			'GroupMember',
			'FormError',
			'Message',
			'Order',
			'OrderItem',
			'PageContent',
			'PhoneNumber',
			'Post',
			'Product',
			'ProductVersion',
			'ProductDetail',
			'ProductGroup',
			'PublicMenu',
			'Question',
			'QuestionOption',
			'QueuedEmail',
			'Setting',
			'StripeInvoice',
			'Survey',
			'SurveyAnswer',
			'SurveyQuestion',
			'Url',
			'User',
			'ActivationCode',
			'VisitorEvent',
			'Video',
			'Page',
			'Component', 
			'ProductRequirement',
			'ProductRequirementInstance',
			'OrderItemRequirement',
			'EmailRecipientGroup',
			'ContactType',
		);			

	
?>

