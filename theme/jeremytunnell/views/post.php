<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/ThemeHelper.php');
	// LibraryFunctions is now guaranteed available - line removed
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	
	ThemeHelper::includeThemeFile('logic/post_logic.php');
	$page_vars = post_logic($_GET, $_POST, $post);
	$post = $page_vars['post'];
	$session = $page_vars['session'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions); 
	
	?>


				<div class="section-content">
    <article id="post-746" class="typology-post typology-single-post post-746 post type-post status-publish format-standard hentry category-uncategorized">

        
            <header class="entry-header">

                <h1 class="entry-title entry-title-cover-empty"><?php echo $post->get('pst_title'); ?></h1>
                 
                    <div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="/page/about">Jeremy Tunnell</a></span></span></div>
					<!--<div class="meta-item meta-category">In <a href="https://jeremytunnell.com/category/uncategorized/" rel="category tag">Uncategorized</a></div>-->
					<div class="meta-item meta-date"><span class="updated"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></span></div>
					<!--<div class="meta-item meta-rtime">15 Min read</div>--></div>
                
                                    <div class="post-letter"><?php echo $post->get('pst_title')[0]; ?></div>
                
            </header>

                
        <div class="entry-content clearfix">
                        
            
            <?php echo $post->get('pst_body'); ?>



                        
            
        </div>
        
       <!--              	
	
		<div class="typology-social-icons">
							<a href="javascript:void(0);" class="typology-facebook typology-share-item hover-on" data-url="http://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;t=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-facebook"></i></a>							<a href="javascript:void(0);" class="typology-twitter typology-share-item hover-on" data-url="http://twitter.com/intent/tweet?url=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;text=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-twitter"></i></a>							<a href="javascript:void(0);"  class="typology-reddit typology-share-item hover-on" data-url="http://www.reddit.com/submit?url=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;title=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-reddit-alien"></i></a>					</div>
-->
	        
    </article>
</div>
			



	<div class="section-head"><h3 class="section-title h6">About the author</h3></div>	
	
		<div class="section-content typology-author">
				
			<div class="container">

				<div class="col-lg-2">
					<img alt="" src="/theme/jeremytunnell/images/jeremy-100.jpg" class="avatar avatar-100 photo" height="100" width="100">				</div>

				<div class="col-lg-10">

					<h5 class="typology-author-box-title">Jeremy Tunnell</h5>
					<div class="typology-author-desc">I study meditation and write some software.
											</div>

					<div class="typology-author-links">
						<a class="typology-button-social hover-on" href="/">View all posts</a><a href="/" target="_blank" class="typology-icon-social hover-on fa fa-link"></a>					</div>

				</div>

			</div>

		</div>
	<?php 
	if($settings->get_setting('show_comments')){
	?>	
	
				
		<div class="section-head"><h3 class="section-title h6">Comments</h3></div>
		<div id="comments" class="section-content typology-comments">
	<?php 
	}
	if($settings->get_setting('comments_active')){
		if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()){
		
			if($new_comment){
				echo 'Your comment has been submitted.';
			}
			else{
				$formwriter = $page->getFormWriter("form1");
				
				$validation_rules = array();
				$validation_rules['cmt']['required']['value'] = 'true';
				$validation_rules['cmt']['minlength']['value'] = 20;
				$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
				$validation_rules['name']['required']['value'] = 'true';
				$validation_rules['name']['minlength']['value'] = 2;
				$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');
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
				echo $formwriter->new_form_button('Comment');
				echo $formwriter->end_buttons();
				echo $formwriter->end_form();
			}
		}


	
		if($settings->get_setting('show_comments')){
				
				$comments = new MultiComment(
					array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false),
					array('comment_id'=>'ASC'),
					NULL,
					NULL);	
				$numcomments = $comments->count_all();	
				$comments->load();	

		

			if($numcomments){
			?>
							
				<ul class="comment-list">
					<?php foreach($comments as $comment){ ?>
<li id="comment-7" class="comment even thread-even depth-1 parent">
				<article id="div-comment-7" class="comment-body">
					<footer class="comment-meta">
						<div class="comment-author vcard">
							<?php
							if($comment->get('cmt_usr_user_id') == 1){
								echo '<img alt="" src="'.PathHelper::getThemeFilePath('jeremy.jpg', 'assets/images', 'web', 'jeremytunnell').'" class="avatar avatar-80 photo" height="80" width="80">';
							}
							else{
								echo '<img alt="" src="'.PathHelper::getThemeFilePath('blank-avatar.png', 'assets/images', 'web', 'jeremytunnell').'" class="avatar avatar-80 photo" height="80" width="80">';
							}
							?>
							<b class="fn"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></b> <span class="says">says:</span>					</div>

						<div class="comment-metadata">
							<a href="">
								<time>
									<?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></time>
							</a>
												</div>

										</footer>

					<div class="comment-content">
						<p><?php echo htmlspecialchars($comment->get('cmt_body')); ?></p>
					</div>
<!--
					<div class="reply"><a rel="nofollow" class="comment-reply-link" href="../why-do-people-think-clouds-are-so-interesting/index.html" data-commentid="7" data-postid="131" data-belowelement="div-comment-7" data-respondelement="respond" aria-label="Reply">Reply</a></div>	-->		</article>
				<!--
				<ul class="children">
					<li id="comment-8" class="comment odd alt depth-2">
						<article id="div-comment-8" class="comment-body">
							<footer class="comment-meta">
								<div class="comment-author vcard"><img alt="" src="https://secure.gravatar.com/avatar/6af16c1d813067139a1a29d683d1265c?s=80&d=mm&r=g" srcset="https://secure.gravatar.com/avatar/6af16c1d813067139a1a29d683d1265c?s=160&d=mm&r=g 2x" class="avatar avatar-80 photo" height="80" width="80">						<b class="fn">Madison Barnett</b> <span class="says">says:</span>					</div>

								<div class="comment-metadata">
									<a href="../why-do-people-think-clouds-are-so-interesting/#comment-8">
										<time datetime="2017-03-13T09:27:36+00:00">
											March 13, 2017 at 9:27 am							</time>
									</a>
								</div>

							</footer>

							<div class="comment-content">
								<p>Holisticly architect granular partnerships vis-a-vis 24/7 bandwidth. Dramatically plagiarize premier applications before distinctive information. Rapidiously engineer multimedia.</p>
							</div>

							<div class="reply"><a rel="nofollow" class="comment-reply-link" href="../why-do-people-think-clouds-are-so-interesting/index.html" data-commentid="8" data-postid="131" data-belowelement="div-comment-8" data-respondelement="respond" aria-label="Reply to Madison Barnett">Reply</a></div>			</article>
					</li>
				</ul>
				-->
			</li>
					<?php } ?>
				</ul>
			</div>

	<div class="typology-pagination typology-comments-pagination">
	</div>
			<?php
			}
		}
	}
	?>
</div>

<?php

	$page->public_footer(array('track'=>TRUE));
?>