<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	//require_once (LibraryFunctions::get_logic_file_path('event_logic.php'));
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/bookings_class.php');	
	
	$booking_id = LibraryFunctions::fetch_variable('booking_id', NULL,1,'booking id');

	//TURNED OFF FOR NON ADMINS
	$session->check_permission(10);

	$session = SessionControl::get_instance();
	$session->set_return();

	$page = new PublicPageTW();
	$hoptions = array(
		'title' => 'Book',
		'description' => 'Book',
		'banner' => 'Book',
		'submenu' => 'Book',
	);
	$page->public_header($hoptions);
	
	$booking = new Booking($booking_id, TRUE);
	$client_user = new User($booking->get('bkn_usr_user_id_client'), TRUE);
	?>
			<section class="contact-page-area section-gap">
				<div class="container">
				<?php
				//if($product_detail->get('prd_type') == ProductDetail::VISIT_OFFICE){
	
					echo '<h3>Book a visit</h3>'; 

				
					
									echo '<!-- Calendly inline widget begin -->
				<div class="calendly-inline-widget" data-url="https://calendly.com/jeremy-tunnell/1-hour-appointment?primary_color=69be00&name='.str_replace(' ', '%20', $client_user->display_name()).'&email='.$client_user->get('usr_email').'&salesforce_uuid='.$booking->key.'" style="min-width:320px;height:630px;"></div>
				<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>
				<!-- Calendly inline widget end -->';
				/*
				}
				else if($product_detail->get('prd_type') == ProductDetail::VISIT_ONLINE){
					echo '<h3>Book a visit</h3><p>You have one <strong>visit</strong> ready to schedule.</p>'; 
					echo '<!-- Calendly inline widget begin -->
				<div class="calendly-inline-widget" data-url="https://calendly.com/empowered-health-tn/online-visit?primary_color=69be00&name='.str_replace(' ', '%20', $user->display_name()).'&email='.$user->get('usr_email').'&salesforce_uuid='.$product_detail->key.'" style="min-width:320px;height:630px;"></div>
				<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js"></script>
				<!-- Calendly inline widget end -->';
				
				}
				else if($product_detail->get('prd_type') == ProductDetail::MEMBER_YEARLY_VISIT){
					echo '<h3>Book a yearly physical</h3><p>You have one <strong>annual physical</strong> ready to schedule.</p>'; 
					echo '<!-- Calendly inline widget begin -->
					<div class="calendly-inline-widget" data-url="https://calendly.com/empowered-health-tn/annual?primary_color=69be00&name='.str_replace(' ', '%20', $user->display_name()).'&email='.$user->get('usr_email').'&salesforce_uuid='.$product_detail->key.'" style="min-width:320px;height:630px;"></div>
					<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js"></script>
					<!-- Calendly inline widget end -->';					
				}
				else if($product_detail->get('prd_type') == ProductDetail::MEMBER_WELLNESS_VISIT){
					echo '<h3>Book a wellness visit</h3><p>You have one <strong>wellness visit</strong> ready to schedule.</p>'; 
					echo '<!-- Calendly inline widget begin -->
					<div class="calendly-inline-widget" data-url="https://calendly.com/empowered-health-tn/wellness?primary_color=69be00&name='.str_replace(' ', '%20', $user->display_name()).'&email='.$user->get('usr_email').'&salesforce_uuid='.$product_detail->key.'" style="min-width:320px;height:630px;"></div>
					<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js"></script>
					<!-- Calendly inline widget end -->';					
				}			
*/
				

				?>		
							
				</div>
			</section>

<?php

	$page->public_footer(array('track'=>TRUE));
?>