<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	ThemeHelper::includeThemeFile('logic/event_waiting_list_logic.php');
	
	$event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.', TRUE, 'int');
	$page_vars = event_waiting_list_logic($_GET, $_POST, $event_id);
	$event = $page_vars['event'];
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Waiting List',
		'description' => ''
	);
	$page->public_header($hoptions);
	
	$options = array();
	$options['subtitle'] = 'Add yourself to the waiting list, and we will notify you as soon as registration is available.';
	echo PublicPage::BeginPage('Waiting list for '.$event->get('evt_name'), $options);
?>

<!-- Canvas Event Waiting List Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8 col-xl-7">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2">Waiting List</h1>
						<h3 class="h5 text-primary mb-3"><?php echo $event->get('evt_name'); ?></h3>
						<p class="text-muted">Add yourself to the waiting list, and we will notify you as soon as registration is available.</p>
					</div>

					<?php if($page_vars['display_message']): ?>
						<!-- Success/Error Message -->
						<div class="alert alert-<?php echo $page_vars['message_type'] == 'error' ? 'danger' : ($page_vars['message_type'] == 'success' ? 'success' : 'info'); ?> rounded-4 shadow-sm" role="alert">
							<h6 class="alert-heading mb-2">Success</h6>
							<?php echo $page_vars['display_message']; ?>
						</div>
						
						<div class="text-center mt-4">
							<a href="/events" class="btn btn-primary rounded-pill">
								<i class="icon-calendar me-2"></i>View All Events
							</a>
						</div>
					<?php else: ?>
						
						<!-- Waiting List Form -->
						<div class="card shadow-sm rounded-4 border-0">
							<div class="card-body p-4 p-lg-5">
								<?php
								$settings = Globalvars::get_instance();
								$formwriter = $page->getFormWriter('form1');
								$validation_rules = array();
								$validation_rules['usr_first_name']['required']['value'] = 'true';
								$validation_rules['usr_first_name']['minlength']['value'] = 1;
								$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
								$validation_rules['usr_first_name']['maxlength']['value'] = 32;
								$validation_rules['usr_last_name']['required']['value'] = 'true';
								$validation_rules['usr_last_name']['minlength']['value'] = 2;
								$validation_rules['usr_last_name']['maxlength']['value'] = 32;
								$validation_rules['privacy']['required']['value'] = 'true';
								$validation_rules['usr_email']['required']['value'] = 'true';
								$validation_rules['usr_email']['email']['value'] = 'true';
								$validation_rules['usr_email']['maxlength']['value'] = 64;
								$validation_rules = $formwriter->antispam_question_validate($validation_rules);
								echo $formwriter->set_validate($validation_rules);		
								
								echo $formwriter->begin_form("", "post", "/event_waiting_list");
								echo '<input type="hidden" name="event_id" value="'. $event->key .'" />';
								?>

								<?php if($page_vars['session']->get_user_id()): ?>
									<!-- Logged in user -->
									<div class="text-center mb-4">
										<div class="text-primary mb-3">
											<i class="icon-user-check display-4"></i>
										</div>
										<h5 class="mb-3">Join Waiting List</h5>
										<p class="text-muted">Click the button below to be added to this waiting list.</p>
									</div>
								<?php else: ?>
									<!-- Guest user form -->
									<div class="mb-4">
										<h5 class="mb-3">Your Information</h5>
										<div class="row g-3">
											<div class="col-md-6">
												<label for="usr_first_name" class="form-label fw-semibold">First Name</label>
												<input type="text" 
													   name="usr_first_name" 
													   id="usr_first_name" 
													   class="form-control rounded-pill" 
													   placeholder="Enter your first name" 
													   maxlength="32" />
											</div>
											<div class="col-md-6">
												<label for="usr_last_name" class="form-label fw-semibold">Last Name</label>
												<input type="text" 
													   name="usr_last_name" 
													   id="usr_last_name" 
													   class="form-control rounded-pill" 
													   placeholder="Enter your last name" 
													   maxlength="32" />
											</div>
										</div>

										<?php 
										$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
										if($nickname_display): 
										?>
										<div class="mt-3">
											<label for="usr_nickname" class="form-label fw-semibold"><?php echo $nickname_display; ?></label>
											<input type="text" 
												   name="usr_nickname" 
												   id="usr_nickname" 
												   class="form-control rounded-pill" 
												   placeholder="Enter your <?php echo strtolower($nickname_display); ?>" 
												   maxlength="32" />
										</div>
										<?php endif; ?>

										<div class="mt-3">
											<label for="usr_email" class="form-label fw-semibold">Email Address</label>
											<input type="email" 
												   name="usr_email" 
												   id="usr_email" 
												   class="form-control rounded-pill" 
												   placeholder="Enter your email address" 
												   maxlength="64" />
										</div>

										<div class="mt-3">
											<label for="usr_timezone" class="form-label fw-semibold">Your Timezone</label>
											<?php
											$optionvals = Address::get_timezone_drop_array();
											$default_timezone = $page_vars['settings']->get_setting('default_timezone');
											?>
											<select name="usr_timezone" id="usr_timezone" class="form-select rounded-pill">
												<?php foreach($optionvals as $val => $label): ?>
													<option value="<?php echo $val; ?>" <?php echo ($val == $default_timezone) ? 'selected' : ''; ?>>
														<?php echo $label; ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<!-- Checkboxes -->
										<div class="mt-4">
											<div class="form-check mb-2">
												<input type="checkbox" 
													   name="privacy" 
													   id="privacy" 
													   class="form-check-input" 
													   value="1" />
												<label for="privacy" class="form-check-label">
													I consent to the privacy policy.
												</label>
											</div>
											
											<div class="form-check">
												<input type="checkbox" 
													   name="newsletter" 
													   id="newsletter" 
													   class="form-check-input" 
													   value="1" />
												<label for="newsletter" class="form-check-label">
													Add me to the newsletter
												</label>
											</div>
										</div>

										<!-- Anti-spam -->
										<div class="mt-4">
											<?php 
											echo $formwriter->antispam_question_input();
											echo $formwriter->honeypot_hidden_input();
											echo $formwriter->captcha_hidden_input();
											?>
										</div>
									</div>
								<?php endif; ?>

								<div class="d-grid">
									<button type="submit" class="btn btn-warning btn-lg rounded-pill">
										<i class="icon-clock me-2"></i>Add Me to the Waiting List
									</button>
								</div>

								<?php echo $formwriter->end_form(); ?>
							</div>
						</div>

					<?php endif; ?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE));
?>