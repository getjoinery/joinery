<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('post_logic.php'));

	$page_vars = post_logic($_GET, $_POST, $post);
	$post = $page_vars['post'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage();	
	echo PublicPage::BeginPanel();
?>


    <div class="text-lg max-w-prose mx-auto">
      <h1>
        <span class="block text-base text-center text-indigo-600 font-semibold tracking-wide uppercase">Blog</span>
        <span class="mt-2 mb-4 block text-3xl text-center leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl"><?php echo $post->get('pst_title'); ?></span>
      </h1>
				<p class="text-base text-gray-500 text-center">
					<?php echo $page_vars['author']->display_name().' at '; ?>
				  <time datetime="2020-03-16"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</p>
	<div class="flow-root text-center">
		<?php
		foreach ($page_vars['tags'] as $tag){
			echo '<a href="/blog/tag/'.urlencode($tag).'" class="inline-block p-1">
			<span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">'.$tag.'</span>
			</a>';			
		}
		?>				   
	</div> 
      <!--<p class="mt-8 text-xl text-gray-500 leading-8">Aliquet nec orci mattis amet quisque ullamcorper neque, nibh sem. At arcu, sit dui mi, nibh dui, diam eget aliquam. Quisque id at vitae feugiat egestas ac. Diam nulla orci at in viverra scelerisque eget. Eleifend egestas fringilla sapien.</p>-->
    
    <div class="mt-6 prose prose-indigo prose-lg text-gray-500 mx-auto">
      <?php echo $post->get('pst_body'); ?>
    </div>
	
	<?php if($page_vars['settings']->get_setting('blog_footer_text')){
	?>
	<div class="mt-6 prose prose-indigo prose-lg text-gray-500 mx-auto">
      <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
    </div>
	<?php
	}
	?>

      <h3>
        <span class="mt-2 mb-4 block text-xl text-center leading-8 font-extrabold tracking-tight text-gray-900 sm:text-xl">Add Comment</span>
      </h3>

		<?php

	if($page_vars['settings']->get_setting('comments_active')){
		if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()){
		
		?>

							
								<?php
								$settings = Globalvars::get_instance();
								$formwriter = LibraryFunctions::get_formwriter_object('form1', $settings->get_setting('form_style'));
								$validation_rules = array();
								$validation_rules['cmt']['required']['value'] = 'true';
								$validation_rules['cmt']['minlength']['value'] = 20;
								$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
								$validation_rules['name']['required']['value'] = 'true';
								$validation_rules['name']['minlength']['value'] = 2;
								$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');
								echo $formwriter->set_validate($validation_rules);			
								

								echo $formwriter->begin_form("", "post", $_SERVER['REQUEST_URI'], true);

								echo $formwriter->textinput("Name", "name", NULL, 20, NULL , "",255, "");	
								//echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 20, @$form_fields->usr_last_name, "" , 255, "");
								//echo $formwriter->textinput("Email", "usr_email", NULL, 20, '', "" , 255, "");
								echo $formwriter->textbox('Comment', 'cmt', NULL, 3, 80, NULL, '', '');
								
								if(!$page_vars['session']->get_user_id()){
									echo $formwriter->antispam_question_input('blog');
									echo $formwriter->honeypot_hidden_input();	
									echo $formwriter->honeypot_hidden_input('Comment', 'comment');	
									echo $formwriter->captcha_hidden_input('blog');
								}


								echo $formwriter->start_buttons('flex justify-end sm:col-span-6');
								echo $formwriter->new_form_button('Comment', 'secondary');
								echo $formwriter->end_buttons();
								echo $formwriter->end_form(true);
								?>

			<?php 
		
		}


		if($page_vars['settings']->get_setting('show_comments')){			
						

			if($page_vars['numcomments']){
				?>
				<script>
				$(document).ready(function(){
					$('.commentbutton').click(function(){
						var cid = $(this).attr('id');
						$('#' + cid + 'container').toggle(500);
				  });
				});
				</script>
				<!-- Comments section -->
			  <h3>
				<span class="mt-2 mb-4 block text-xl text-center leading-8 font-extrabold tracking-tight text-gray-900 sm:text-xl">Comments</span>
			  </h3>
				<div class="flow-root">
				  <ul role="list" class="-mb-8">
								
					<?php
					foreach($page_vars['comments'] as $comment){ 	
						echo '
						<li>
						  <div class="relative pb-8 mt-4">
							<span class="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
							<div class="relative flex items-start space-x-3">
							  <div class="relative">
								<img class="h-10 w-10 rounded-full bg-gray-400 flex items-center justify-center ring-8 ring-white" src="/includes/images/blank-avatar.png" alt="">

								<span class="absolute -bottom-0.5 -right-1 bg-white rounded-tl px-0.5 py-px">
								  <!-- Heroicon name: solid/chat-alt -->
								  <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
									<path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2zM7 8H5v2h2V8zm2 0h2v2H9V8zm6 0h-2v2h2V8z" clip-rule="evenodd" />
								  </svg>
								</span>
							  </div>
							  <div class="min-w-0 flex-1">
								<div>
								  <div class="text-sm">
									<a href="#" class="font-medium text-gray-900">'.htmlspecialchars($comment->get('cmt_author_name')).'</a>
								  </div>
								  <p class="mt-0.5 text-sm text-gray-500">'. LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York').'</p>
								</div>
								<div class="mt-2 text-sm text-gray-700">
								  <div>'.$comment->get_sanitized_comment().'
								  <br /><br /><button id="comment'.$comment->key.'" class="commentbutton">Reply to this comment >></button>';
								  
							
									if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()){
											echo '<div id="comment'.$comment->key.'container" style="display:none;">';
											$settings = Globalvars::get_instance();
											$formwriter = LibraryFunctions::get_formwriter_object('form'.$comment->key, $settings->get_setting('form_style'));
	
											$validation_rules = array();
											$validation_rules['cmt']['required']['value'] = 'true';
											$validation_rules['cmt']['minlength']['value'] = 20;
											$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
											$validation_rules['name']['required']['value'] = 'true';
											$validation_rules['name']['minlength']['value'] = 2;
											$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');
											echo $formwriter->set_validate($validation_rules);			
											

											echo $formwriter->begin_form('form'.$comment->key, "post", $_SERVER['REQUEST_URI'], true);
											echo $formwriter->hiddeninput('cmt_comment_id_parent', $comment->key);
											echo $formwriter->textinput("Your name", "name", NULL, 20, NULL , "",255, "");	
											//echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 20, @$form_fields->usr_last_name, "" , 255, "");
											//echo $formwriter->textinput("Email", "usr_email", NULL, 20, '', "" , 255, "");
											echo $formwriter->textbox('Your reply', 'cmt', NULL, 3, 80, NULL, '', '');
											
											if(!$page_vars['session']->get_user_id()){
												echo $formwriter->antispam_question_input('blog');
												echo $formwriter->honeypot_hidden_input();	
												echo $formwriter->honeypot_hidden_input('Comment', 'comment');	
												echo $formwriter->captcha_hidden_input('blog');
											}


											echo $formwriter->start_buttons('flex justify-end sm:col-span-6');
											echo $formwriter->new_form_button('Comment', 'secondary');
											echo $formwriter->end_buttons();
											echo $formwriter->end_form(true);
											echo '</div>';
										}
									 


									$replies = new MultiComment(
										array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'parent_id'=>$comment->key),
										array('comment_id'=>'DESC'),
										NULL,
										NULL);	 
									$numreplies = $replies->count_all();
									

									if($numreplies){
										$replies->load();
										echo '<ul role="list" class="-mb-8">';
										foreach($replies as $reply){ 
											if($reply->get('cmt_comment_id_parent') == $comment->key){
												echo '
												
												<li>
												  <div class="relative pb-8 mt-4">
													<span class="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
													<div class="relative flex items-start space-x-3">
													  <div class="relative">
														<img class="h-10 w-10 rounded-full bg-gray-400 flex items-center justify-center ring-8 ring-white" src="/includes/images/blank-avatar.png" alt="">

														<span class="absolute -bottom-0.5 -right-1 bg-white rounded-tl px-0.5 py-px">
														  <!-- Heroicon name: solid/chat-alt -->
														  <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
															<path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2zM7 8H5v2h2V8zm2 0h2v2H9V8zm6 0h-2v2h2V8z" clip-rule="evenodd" />
														  </svg>
														</span>
													  </div>
													  <div class="min-w-0 flex-1">
														<div>
														  <div class="text-sm">
															<a href="#" class="font-medium text-gray-900">'.htmlspecialchars($reply->get('cmt_author_name')).'</a>
														  </div>
														  <p class="mt-0.5 text-sm text-gray-500">'. LibraryFunctions::convert_time($reply->get('cmt_created_time'), 'UTC', 'America/New_York').'</p>
														</div>
														<div class="mt-2 text-sm text-gray-700">
														  <div>'.$reply->get_sanitized_comment().'</div>
														</div>
													  </div>
													</div>
												  </div>
												</li>';
											}
										}
										echo '</ul>';
									}
									
								  echo '</div>
								</div>
							  </div>
							</div>
						  </div>
						</li>

						<!--
						<li>
						  <div class="relative pb-8">
							<span class="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
							<div class="relative flex items-start space-x-3">
							  <div>
								<div class="relative px-1">
								  <div class="h-8 w-8 bg-gray-100 rounded-full ring-8 ring-white flex items-center justify-center">
								  
									<svg class="h-5 w-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
									  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd" />
									</svg>
								  </div>
								</div>
							  </div>
							  <div class="min-w-0 flex-1 py-1.5">
								<div class="text-sm text-gray-500">
								  <a href="#" class="font-medium text-gray-900">Hilary Mahy</a>
								  assigned
								  <a href="#" class="font-medium text-gray-900">Kristin Watson</a>
								  <span class="whitespace-nowrap">2d ago</span>
								</div>
							  </div>
							</div>
						  </div>
						</li>-->
						';
					}

					?>

					</ul><!-- end container -->
				</div>
				<!-- end Comments section -->
<?php				
			}
		}
	}
	?>
	</div>
	<?php
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>