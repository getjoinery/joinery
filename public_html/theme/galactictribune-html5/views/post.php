<?php
	// A post: breadcrumbs, the post in a panel, then its comments and the
	// comment form. Styled by assets/css/galactictribune.css (gt-*); the
	// Reply buttons are core's assets/js/blog-page.js.
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

	$page_vars = process_logic(post_logic(array_merge($_GET, $_POST, $params ?? [])));
	$post = $page_vars['post'];
	$settings = $page_vars['settings'];
	$signed_in = (bool)$page_vars['session']->get_user_id();
	$may_comment = $settings->get_setting('comments_unregistered_users') || $signed_in;

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page ?? false,
		'title' => $post->get('pst_title'),
	));

	echo PublicPage::BeginPage();

	$chevron = '<svg viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>';

	/** The name, comment and spam-guard fields, and the submit button, of a comment form. */
	$comment_fields = function ($fw, $name_label, $text_label, $rows, $button) use ($signed_in) {
		$fw->textinput('name', $name_label, ['maxlength' => 255, 'required' => true]);
		$fw->textbox('cmt', $text_label, ['rows' => $rows, 'required' => true]);
		if (!$signed_in) {
			echo $fw->antispam_question_input('blog');
			echo $fw->honeypot_hidden_input();
			echo $fw->honeypot_hidden_input('Comment', 'comment');
			echo $fw->captcha_hidden_input('blog');
		}
		// The FormWriter methods print as they are called.
		echo '<div class="gt-form-actions">';
		$fw->submitbutton('btn_submit', $button, ['class' => 'btn btn-primary']);
		echo '</div>';
	};
?>

<div class="gt-post">
	<nav class="gt-crumbs" aria-label="Breadcrumb">
		<ol>
			<li><a href="/">Home</a></li>
			<li><?php echo $chevron; ?></li>
			<li><a href="/blog">Blog</a></li>
			<li><?php echo $chevron; ?></li>
			<li aria-current="page"><?php echo htmlspecialchars($post->get('pst_title')); ?></li>
		</ol>
	</nav>

	<article class="gt-panel">
		<header class="gt-post-head">
			<span class="gt-tag">Blog</span>
			<h1><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>
			<div class="gt-post-meta">
				<span>
					<svg viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" /></svg>
					by <?php echo htmlspecialchars($page_vars['author']->display_name()); ?>
				</span>
				<span>
					<svg viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v2H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v2H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h12a1 1 0 100-2H6z" clip-rule="evenodd" /></svg>
					<time><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</span>
			</div>
		</header>

		<?php if (!empty($page_vars['tags'])): ?>
		<div class="gt-post-tags">
			<?php foreach ($page_vars['tags'] as $tag): ?>
			<a href="/blog/tag/<?php echo urlencode($tag); ?>" class="gt-tag">
				<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M17.293 13.293A8 8 0 016.707 2.707a8 8 0 1010.586 10.586z" /></svg>
				<?php echo htmlspecialchars($tag); ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="gt-post-body">
			<?php echo $post->get('pst_body'); ?>
		</div>

		<?php if ($settings->get_setting('blog_footer_text')): ?>
		<div class="gt-post-footer">
			<?php echo $settings->get_setting('blog_footer_text'); ?>
		</div>
		<?php endif; ?>
	</article>

	<?php if ($settings->get_setting('comments_active')): ?>
	<div class="gt-panel">
		<?php if ($settings->get_setting('show_comments') && $page_vars['numcomments']): ?>
		<div class="gt-comments">
			<h2>Comments (<?php echo $page_vars['numcomments']; ?>)</h2>
			<?php echo PageScript::src('assets/js/blog-page.js'); ?>

			<?php foreach ($page_vars['comments'] as $comment): ?>
			<div class="gt-comment">
				<h3><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></h3>
				<p class="gt-comment-time"><?php echo LibraryFunctions::convert_time($comment->get('cmt_create_time'), 'UTC', 'America/New_York'); ?></p>
				<div class="gt-comment-text"><?php echo $comment->get_sanitized_comment(); ?></div>
				<button type="button" id="comment<?php echo $comment->key; ?>" class="commentbutton gt-reply-button">
					<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2 5a2 2 0 012-2h9a2 2 0 012 2v5a2 2 0 01-2 2H4l-3 2v-8z" /><path d="M15 13l-3-2h2a2 2 0 002-2V5a2 2 0 00-2-2h-5.586A1.99 1.99 0 0010 0H5a2 2 0 00-2 2v10a2 2 0 002 2h10z" /></svg>
					Reply
				</button>

				<?php if ($may_comment): ?>
				<div id="comment<?php echo $comment->key; ?>container" class="gt-reply-form" hidden>
					<?php
					$reply_fw = $page->getFormWriter('form' . $comment->key, ['action' => $_SERVER['REQUEST_URI']]);
					$reply_fw->antispam_question_validate([], 'blog');
					$reply_fw->begin_form();
					echo $reply_fw->hiddeninput('cmt_comment_id_parent', $comment->key);
					$comment_fields($reply_fw, 'Your name', 'Your reply', 3, 'Reply');
					$reply_fw->end_form();
					?>
				</div>
				<?php endif; ?>

				<?php if (!empty($page_vars['replies_by_parent'][$comment->key])): ?>
				<div class="gt-replies">
					<?php foreach ($page_vars['replies_by_parent'][$comment->key] as $reply): ?>
					<div class="gt-reply">
						<h4><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></h4>
						<p class="gt-comment-time"><?php echo LibraryFunctions::convert_time($reply->get('cmt_create_time'), 'UTC', 'America/New_York'); ?></p>
						<div class="gt-comment-text"><?php echo $reply->get_sanitized_comment(); ?></div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php if ($may_comment): ?>
		<div>
			<h2>Leave a Comment</h2>
			<?php
			$formwriter = $page->getFormWriter('form1', ['action' => $_SERVER['REQUEST_URI']]);
			$formwriter->antispam_question_validate([], 'blog');
			$formwriter->begin_form();
			$comment_fields($formwriter, 'Name', 'Comment', 6, 'Post Comment');
			$formwriter->end_form();
			?>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div>

<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track' => TRUE));
?>
