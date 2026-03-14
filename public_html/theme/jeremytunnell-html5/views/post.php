<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

	$page_vars = process_logic(post_logic($_GET, $_POST, $post));
	$post = $page_vars['post'];
	$session = $page_vars['session'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions);

	$first_letter = mb_substr($post->get('pst_title'), 0, 1);
?>

	<div class="section-content">
		<article>
			<header class="entry-header" style="max-width: var(--content-inner); margin: 0 auto 3rem; text-align: center; padding-top: 2rem; position: relative;">
				<div class="post-dropcap"><?php echo htmlspecialchars($first_letter); ?></div>
				<h1 class="page-title"><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>
				<div class="post-meta">
					<span>By</span>
					<a href="/page/about">Jeremy Tunnell</a>
					<span class="sep">/</span>
					<span>on <?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></span>
				</div>
			</header>
			<div class="entry-content">
				<?php echo $post->get('pst_body'); ?>
			</div>
		</article>
	</div>

	<!-- About the Author -->
	<div class="section-heading">
		<h3>About The Author</h3>
	</div>
	<div class="section-heading-divider"></div>

	<div class="author-box">
		<div class="author-box-avatar">
			<img alt="Jeremy Tunnell" src="/theme/jeremytunnell-html5/assets/images/jeremy-100.jpg" class="avatar" height="80" width="80" style="border-radius: 50%;">
		</div>
		<div class="author-box-info">
			<h5>Jeremy Tunnell</h5>
			<div class="author-box-bio">I study meditation and write some software.</div>
			<div class="author-box-links">
				<a href="/">View all posts</a>
			</div>
		</div>
	</div>

<?php
	$settings = Globalvars::get_instance();
	if($settings->get_setting('show_comments')){
?>
	<!-- Comments -->
	<div class="section-heading">
		<h3>Comments</h3>
	</div>
	<div class="section-heading-divider"></div>
<?php
	}

	if($settings->get_setting('comments_active')){
		if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()){

			if($new_comment){
				echo '<div class="section-content"><p>Your comment has been submitted.</p></div>';
			} else {
?>
	<div class="comments-section">
		<?php
				$formwriter = $page->getFormWriter("form1", [
					'action' => $_SERVER['REQUEST_URI']
				]);

				$formwriter->antispam_question_validate([], 'blog');
				$formwriter->begin_form();

				$formwriter->textinput("name", "Name", [
					'maxlength' => 255
				]);
				$formwriter->textbox('cmt', 'Comment', [
					'rows' => 5,
					'cols' => 80
				]);

				if(!$session->get_user_id()){
					$formwriter->antispam_question_input('blog');
					$formwriter->honeypot_hidden_input();
					$formwriter->honeypot_hidden_input('Comment', 'comment');
					$formwriter->captcha_hidden_input('blog');
				}

				$formwriter->submitbutton('btn_submit', 'Comment', ['class' => 'btn-comment']);
				$formwriter->end_form();
		?>
	</div>
<?php
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
	<div class="section-content">
		<ul class="comment-list" style="list-style: none; padding: 0;">
			<?php foreach($comments as $comment){ ?>
			<li style="margin-bottom: 3rem; padding-bottom: 3rem; border-bottom: 1px solid var(--color-border);">
				<div style="display: flex; gap: 1.5rem; align-items: flex-start;">
					<?php
					if($comment->get('cmt_usr_user_id') == 1){
						echo '<img alt="" src="/theme/jeremytunnell-html5/assets/images/jeremy-100.jpg" style="border-radius: 50%; width: 60px; height: 60px; object-fit: cover;">';
					} else {
						echo '<img alt="" src="/theme/jeremytunnell-html5/assets/images/blank-avatar.png" style="border-radius: 50%; width: 60px; height: 60px; object-fit: cover;">';
					}
					?>
					<div>
						<strong><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></strong>
						<span style="color: var(--color-meta); font-size: 1.3rem; margin-left: 1rem;">
							<?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?>
						</span>
						<p style="margin-top: 0.8rem;"><?php echo htmlspecialchars($comment->get('cmt_body')); ?></p>
					</div>
				</div>
			</li>
			<?php } ?>
		</ul>
	</div>
<?php
			}
		}
	}
?>

<?php
	$page->public_footer(array('track'=>TRUE));
?>
