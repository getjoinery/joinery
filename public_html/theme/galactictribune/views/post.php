<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

	$page_vars = post_logic($_GET, $_POST, $post);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$post = $page_vars['post'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => $post->get('pst_title')
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage();
?>

<!-- Blog Post Content -->
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:max-w-4xl lg:px-8 py-8">

	<!-- Breadcrumbs -->
	<nav class="mb-6 text-sm" aria-label="Breadcrumb">
		<ol class="flex items-center gap-2">
			<li>
				<a href="/" class="text-indigo-600 hover:text-indigo-700 font-medium">Home</a>
			</li>
			<li>
				<svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
					<path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
				</svg>
			</li>
			<li>
				<a href="/blog" class="text-indigo-600 hover:text-indigo-700 font-medium">Blog</a>
			</li>
			<li>
				<svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
					<path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
				</svg>
			</li>
			<li class="text-gray-600">
				<?php echo htmlspecialchars($post->get('pst_title')); ?>
			</li>
		</ol>
	</nav>

	<!-- Post Card -->
	<article class="rounded-lg bg-white overflow-hidden shadow-lg p-8 mb-8">

		<!-- Post Title Section -->
		<div class="mb-6 border-b border-gray-200 pb-6">
			<div class="flex items-center gap-2 mb-4">
				<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-700">Blog</span>
			</div>
			<h1 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>

			<!-- Post Meta Information -->
			<div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
				<div class="flex items-center gap-2">
					<svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
						<path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
					</svg>
					<span>by <?php echo htmlspecialchars($page_vars['author']->display_name()); ?></span>
				</div>
				<div class="flex items-center gap-2">
					<svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
						<path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v2H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v2H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h12a1 1 0 100-2H6z" clip-rule="evenodd" />
					</svg>
					<time><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
				</div>
			</div>
		</div>

		<!-- Post Tags -->
		<?php if (!empty($page_vars['tags'])): ?>
		<div class="mb-6 flex flex-wrap gap-2">
			<?php foreach ($page_vars['tags'] as $tag): ?>
			<a href="/blog/tag/<?php echo urlencode($tag); ?>" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors duration-200">
				<svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
					<path d="M17.293 13.293A8 8 0 016.707 2.707a8 8 0 1010.586 10.586z" />
				</svg>
				<?php echo htmlspecialchars($tag); ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- Post Body -->
		<div class="text-lg text-gray-800 leading-relaxed max-w-3xl mx-auto">
			<?php echo $post->get('pst_body'); ?>
		</div>

		<!-- Blog Footer Text -->
		<?php if($page_vars['settings']->get_setting('blog_footer_text')): ?>
		<div class="mt-8 pt-8 border-t border-gray-200 max-w-3xl mx-auto">
			<div class="text-sm text-gray-600 leading-relaxed">
				<?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
			</div>
		</div>
		<?php endif; ?>

	</article>

	<!-- Comments Section -->
	<?php if($page_vars['settings']->get_setting('comments_active')): ?>
	<div class="rounded-lg bg-white overflow-hidden shadow-lg p-8 mb-8">

		<!-- Comments Display -->
		<?php if($page_vars['settings']->get_setting('show_comments') && $page_vars['numcomments']): ?>
		<div class="mb-8 pb-8 border-b border-gray-200">
			<h2 class="text-2xl font-bold text-gray-900 mb-6">Comments (<?php echo $page_vars['numcomments']; ?>)</h2>

			<script>
			$(document).ready(function(){
				$('.commentbutton').click(function(){
					var cid = $(this).attr('id');
					$('#' + cid + 'container').toggle(500);
				});
			});
			</script>

			<div class="space-y-6">
				<?php foreach($page_vars['comments'] as $comment): ?>
				<div class="border-l-4 border-indigo-300 pl-6 py-4">
					<div class="flex items-start gap-4 mb-3">
						<div>
							<h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></h3>
							<p class="text-sm text-gray-500"><?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></p>
						</div>
					</div>
					<div class="text-gray-700 mb-4">
						<?php echo $comment->get_sanitized_comment(); ?>
					</div>
					<button id="comment<?php echo $comment->key; ?>" class="commentbutton inline-flex items-center px-3 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-medium hover:bg-indigo-100 transition-colors duration-200 text-sm">
						<svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
							<path d="M2 5a2 2 0 012-2h9a2 2 0 012 2v5a2 2 0 01-2 2H4l-3 2v-8z" />
							<path d="M15 13l-3-2h2a2 2 0 002-2V5a2 2 0 00-2-2h-5.586A1.99 1.99 0 0010 0H5a2 2 0 00-2 2v10a2 2 0 002 2h10z" />
						</svg>
						Reply
					</button>

					<!-- Reply Form -->
					<?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
					<div id="comment<?php echo $comment->key; ?>container" style="display:none;" class="mt-4 p-4 bg-gray-50 rounded-lg">
						<?php
						$formwriter = $page->getFormWriter('form'.$comment->key);

						$validation_rules = array();
						$validation_rules['cmt']['required']['value'] = 'true';
						$validation_rules['cmt']['minlength']['value'] = 20;
						$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
						$validation_rules['name']['required']['value'] = 'true';
						$validation_rules['name']['minlength']['value'] = 2;
						$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');

						echo $formwriter->begin_form('form'.$comment->key, "post", $_SERVER['REQUEST_URI'], true);
						echo $formwriter->hiddeninput('cmt_comment_id_parent', $comment->key);
						?>

						<div class="mb-3 w-full">
							<label class="block text-sm font-medium text-gray-700 mb-2">Your name</label>
							<input type="text" name="name" id="name" class="w-full px-3 py-2 rounded-lg bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm box-border" maxlength="255" />
						</div>
						<div class="mb-4 w-full">
							<label class="block text-sm font-medium text-gray-700 mb-2">Your reply</label>
							<textarea name="cmt" id="cmt" rows="3" class="w-full px-3 py-2 rounded-lg bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm box-border"></textarea>
						</div>

						<?php if(!$page_vars['session']->get_user_id()): ?>
						<div class="mb-4">
							<?php
							echo $formwriter->antispam_question_input('blog');
							echo $formwriter->honeypot_hidden_input();
							echo $formwriter->honeypot_hidden_input('Comment', 'comment');
							echo $formwriter->captcha_hidden_input('blog');
							?>
						</div>
						<?php endif; ?>

						<div class="flex justify-end">
							<button type="submit" class="inline-flex items-center px-3 py-1 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700 transition-colors duration-200 text-sm">Reply</button>
						</div>

						<?php echo $formwriter->end_form(true); ?>
					</div>
					<?php endif; ?>

					<!-- Replies -->
					<?php
					$replies = new MultiComment(
						array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'parent_id'=>$comment->key),
						array('comment_id'=>'DESC'),
						NULL,
						NULL
					);
					$numreplies = $replies->count_all();

					if($numreplies):
						$replies->load();
						?>
						<div class="mt-4 space-y-3">
							<?php foreach($replies as $reply): ?>
								<?php if($reply->get('cmt_comment_id_parent') == $comment->key): ?>
								<div class="ml-6 pl-4 border-l-2 border-gray-200 py-3">
									<h4 class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></h4>
									<p class="text-xs text-gray-500 mb-2"><?php echo LibraryFunctions::convert_time($reply->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></p>
									<p class="text-gray-700 text-sm">
										<?php echo $reply->get_sanitized_comment(); ?>
									</p>
								</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<!-- Add Comment Form -->
		<?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
		<div>
			<h2 class="text-2xl font-bold text-gray-900 mb-6">Leave a Comment</h2>
			<?php
			$settings = Globalvars::get_instance();
			$formwriter = $page->getFormWriter('form1');
			$validation_rules = array();
			$validation_rules['cmt']['required']['value'] = 'true';
			$validation_rules['cmt']['minlength']['value'] = 20;
			$validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
			$validation_rules['name']['required']['value'] = 'true';
			$validation_rules['name']['minlength']['value'] = 2;
			$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');

			echo $formwriter->begin_form("", "post", $_SERVER['REQUEST_URI'], true);
			?>

			<div class="mb-4 w-full">
				<label for="name" class="block text-sm font-medium text-gray-700 mb-2">Name <span class="text-red-500">*</span></label>
				<input type="text" name="name" id="name" class="w-full px-4 py-2 rounded-lg bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 box-border" maxlength="255" />
			</div>
			<div class="mb-4 w-full">
				<label for="cmt" class="block text-sm font-medium text-gray-700 mb-2">Comment <span class="text-red-500">*</span></label>
				<textarea name="cmt" id="cmt" rows="6" class="w-full px-4 py-2 rounded-lg bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 box-border"></textarea>
			</div>

			<?php if(!$page_vars['session']->get_user_id()): ?>
			<div class="mb-4">
				<?php
				echo $formwriter->antispam_question_input('blog');
				echo $formwriter->honeypot_hidden_input();
				echo $formwriter->honeypot_hidden_input('Comment', 'comment');
				echo $formwriter->captcha_hidden_input('blog');
				?>
			</div>
			<?php endif; ?>

			<div class="flex justify-end">
				<button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700 transition-colors duration-200">Post Comment</button>
			</div>

			<?php echo $formwriter->end_form(true); ?>
		</div>
		<?php endif; ?>

	</div>
	<?php endif; ?>

</div>

<?php
echo PublicPage::EndPage();
$page->public_footer(array('track'=>TRUE));
?>
