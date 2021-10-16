<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('post_logic.php'));


	$page = new PublicPage();
	$hoptions = array(
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions); 
	
	$pageoptions['subtitle'] = LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'). ' - '  . 'By '.$author->display_name();
	echo PublicPage::BeginPage($post->get('pst_title'), $pageoptions);	

?>

		<!-- Blog Post section -->
		<div class="section padding-top-20">
			<div class="container">
				<div class="row">
					<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
						
						<?php echo $post->get('pst_body'); ?>
						
						<!-- Post Tags / Share -->
						<div class="row margin-top-50">
							<div class="col-6">
								<h6 class="font-family-tertiary font-small font-weight-normal uppercase">Tags</h6>
								<ul class="list-inline-sm">
									<?php
									foreach ($tags as $tag){
										echo '<li><a class="text-link-1" href="/blog/tag/'.urlencode($tag).'">'.$tag.'</a></li>';
									} 
									?>

								</ul>
							</div>
							<!--
							<div class="col-6 text-right">
								<h6 class="font-family-tertiary font-small font-weight-normal uppercase">Share On</h6>
								<ul class="list-inline">
									<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
									<li><a href="#"><i class="fab fa-twitter"></i></a></li>
									<li><a href="#"><i class="fab fa-google-plus-g"></i></a></li>
								</ul>
							</div>
							-->
						</div>
						<!-- end Post Tags / Share -->
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<!-- end Blog Post section -->

		<!-- Post Author section -->
		<div class="section bg-grey-lighter">
			<div class="container text-center">
				<div class="row">
					<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2 col-lg-6 offset-lg-3">
						<!--<img class="img-circle-lg margin-bottom-20" src="../assets/images/img-circle-large.jpg">-->
						<h5 class="font-weight-normal"><?php echo $author->display_name(); ?></h5>
						<!--<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. </p>-->
						<!-- Social links -->
						<!--
						<ul class="list-inline margin-top-20">
							<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
							<li><a href="#"><i class="fab fa-twitter"></i></a></li>
							<li><a href="#"><i class="fab fa-pinterest"></i></a></li>
							<li><a href="#"><i class="fab fa-instagram"></i></a></li>
						</ul>-->
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<!-- end Post Author section -->


		<?php

	
	
	if($settings->get_setting('comments_active')){
		if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()){
		
		?>
		<!-- Write Comment section -->
		<div class="section border-top">
			<div class="container">
				<div class="row">
					<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
						<h4 class="margin-bottom-50 text-center">Write a Comment</h4>
						<div class="text-right">
							
								<?php
								$formwriter = new FormWriterPublic("form1", TRUE);
								$validation_rules = array();
								$validation_rules['cmt']['required']['value'] = 'true';
								$validation_rules['cmt']['minlength']['value'] = 20;
								$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
								$validation_rules['name']['required']['value'] = 'true';
								$validation_rules['name']['minlength']['value'] = 2;
								$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules, 'blog');
								echo $formwriter->set_validate($validation_rules);			
								
								echo $formwriter->begin_form("uniForm", "post", $_SERVER['REQUEST_URI']);

								echo $formwriter->textinput("Name", "name", "ctrlHolder", 20, NULL , "",255, "");	
								//echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, @$form_fields->usr_last_name, "" , 255, "");
								//echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 20, '', "" , 255, "");
								echo $formwriter->textbox('Comment', 'cmt', 'ctrlHolder', 5, 80, NULL, '', '');
								
								if(!$session->get_user_id()){
									echo $formwriter->antispam_question_input('blog');
									echo $formwriter->honeypot_hidden_input();	
									echo $formwriter->honeypot_hidden_input('Comment', 'comment');	
									echo $formwriter->captcha_hidden_input('blog');
								}

								echo $formwriter->start_buttons();
								echo $formwriter->new_form_button('Comment', 'button button-lg button-dark');
								echo $formwriter->end_buttons();
								echo $formwriter->end_form();
								?>
							</div>
						</div>
					</div><!-- end row -->
				</div><!-- end container -->
			</div>
			<!-- end Write Comment section -->
			<?php 
		
		}


		if($settings->get_setting('show_comments')){			
			$comments = new MultiComment(
				array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false),
				array('comment_id'=>'DESC'),
				NULL,
				NULL);	
			$numcomments = $comments->count_all();	
			$comments->load();	

			if($numcomments){
				?>
				<!-- Comments section -->
				<div class="section">
					<div class="container">
						<div class="row">
							<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
								<h4 class="margin-bottom-50 text-center">Comments</h4>
								<?php
								foreach($comments as $comment){ 
									echo '								<div class="comment-box">
									<div class="comment-user-avatar">
										<i class="fa fa-user"></i>
									</div>
									<div class="comment-content">
										<span class="comment-time">'. LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York').'</span>
										<h6 class="font-weight-normal">'.htmlspecialchars($comment->get('cmt_author_name')).'</h6>
										<p>'.$comment->get_sanitized_comment().'</p>
									</div>
								</div>';
								} 
								?>
							</div>
						</div><!-- end row -->
					</div><!-- end container -->
				</div>
				<!-- end Comments section -->
<?php				
			}
		}
	}
	
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>