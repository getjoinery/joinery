<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('post_logic.php'));


	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Blog'
	);
	$page->public_header($hoptions); 
	
	echo PublicPage::BeginPage($post->get('pst_title'));	
	
	?>

        <span><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></span>     
          
        <div>    
            <?php echo $post->get('pst_body'); ?>
        </div>
        
       <!--              	
	
		<div class="typology-social-icons">
							<a href="javascript:void(0);" class="typology-facebook typology-share-item hover-on" data-url="http://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;t=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-facebook"></i></a>							<a href="javascript:void(0);" class="typology-twitter typology-share-item hover-on" data-url="http://twitter.com/intent/tweet?url=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;text=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-twitter"></i></a>							<a href="javascript:void(0);"  class="typology-reddit typology-share-item hover-on" data-url="http://www.reddit.com/submit?url=https%3A%2F%2Fjeremytunnell.com%2Fintegral-theory-mhcand-metamodernism-how-they-all-fit-together%2F&amp;title=Integral+Theory%2C+MHC%2C+and+Metamodernism+%26%238230%3Bhow+they+all+fit+together"><i class="fa fa-reddit-alien"></i></a>					</div>
-->
	        

	<?php 
	if($settings->get_setting('show_comments')){
		?>
			<h3>Comments</h3>
			<div id="comments">
			
		<?php 
	}
	if($settings->get_setting('comments_active')){
		if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()){
		
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
			echo $formwriter->new_form_button('Comment');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
		
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
							
				<ul>
					<?php foreach($comments as $comment){ ?>
					<li>
						<div>					
							<b class="fn"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></b> <span class="says">says:</span>					
						</div>

						<div class="comment-metadata">
								<time><?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></time>
						</div>


						<div>
							<p><?php echo $comment->get_sanitized_comment(); ?></p>
						</div>

									
					</li>
					<?php } ?>
				</ul>
			</div>

			<?php
			}
		}
	}
	
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>