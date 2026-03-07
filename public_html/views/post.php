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
	if ($post->get_picture_link('hero')) {
		$hoptions['preview_image_url'] = $post->get_picture_link('hero');
	}
	if ($post->get('pst_short_description')) {
		$hoptions['meta_description'] = $post->get('pst_short_description');
	}
	$page->public_header($hoptions);

	echo PublicPage::BeginPage();
?>

<!-- Canvas Blog Single Content -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row gx-5">

				<main class="postcontent col-lg-9">
					<div class="single-post mb-0">

						<!-- Single Post -->
						<article class="entry">

							<!-- Entry Title -->
							<div class="entry-title text-center mb-4">
								<span class="badge bg-primary rounded-pill mb-3 px-3 py-2">Blog</span>
								<h1 class="mb-3 fw-bold"><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>
							</div>

							<!-- Entry Meta -->
							<div class="entry-meta text-center mb-4">
								<ul class="list-inline mb-3">
									<li class="list-inline-item">
										<i class="bi-person me-1"></i>
										<span>by <?php echo htmlspecialchars($page_vars['author']->display_name()); ?></span>
									</li>
									<li class="list-inline-item">
										<i class="bi-calendar me-1"></i>
										<time><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
									</li>
								</ul>

								<!-- Tags -->
								<div class="d-flex justify-content-center flex-wrap gap-2 mb-4">
									<?php foreach ($page_vars['tags'] as $tag): ?>
									<a href="/blog/tag/<?php echo urlencode($tag); ?>" class="badge bg-light text-dark text-decoration-none">
										<i class="bi-tag me-1"></i><?php echo htmlspecialchars($tag); ?>
									</a>
									<?php endforeach; ?>
								</div>
							</div>

							<!-- Post Images -->
							<?php
							require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
							echo ComponentRenderer::render(null, 'image_gallery', [
								'photos' => $post->get_photos(),
								'primary_file_id' => $post->get('pst_fil_file_id'),
								'alt_text' => $post->get('pst_title'),
							]);
							?>

							<!-- Entry Content -->
							<div class="entry-content">
								<div class="mb-5">
									<?php echo $post->get('pst_body'); ?>
								</div>

								<?php if($page_vars['settings']->get_setting('blog_footer_text')): ?>
								<div class="border-top pt-4 mt-5">
									<?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
								</div>
								<?php endif; ?>
							</div>

						</article>
					</div>
				</main>

				<!-- Sidebar -->
				<aside class="sidebar col-lg-3">
					<div class="sidebar-widgets-wrap">

						<!-- Back to Blog -->
						<div class="widget mb-4">
							<div class="widget-title">
								<h4 class="border-0 mb-0">Navigation</h4>
							</div>
							<div class="widget-content p-3">
								<a href="/blog" class="btn btn-outline-primary w-100">
									<i class="bi-arrow-left me-2"></i>Back to Blog
								</a>
							</div>
						</div>

						<!-- Tags Widget -->
						<?php if (!empty($page_vars['tags'])): ?>
						<div class="widget mb-4">
							<div class="widget-title">
								<h4 class="border-0 mb-0">Tags</h4>
							</div>
							<div class="widget-content p-3">
								<?php foreach ($page_vars['tags'] as $tag): ?>
									<a href="/blog/tag/<?php echo urlencode($tag); ?>" class="btn btn-outline-secondary btn-sm rounded-pill me-1 mb-1">
										<?php echo htmlspecialchars($tag); ?>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endif; ?>

					</div>
				</aside>
			</div>

			<!-- Comments Section -->
			<?php if($page_vars['settings']->get_setting('comments_active')): ?>
			<div class="row mt-5">
				<div class="col-lg-9">

					<!-- Add Comment Form -->
					<?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
					<div class="card shadow-sm rounded-4 mb-5">
						<div class="card-header bg-primary text-white rounded-top-4">
							<h4 class="mb-0">Add Comment</h4>
						</div>
						<div class="card-body p-4">
							<?php
							$settings = Globalvars::get_instance();
						$formwriter = $page->getFormWriter('form1', ['action' => $_SERVER['REQUEST_URI'], 'method' => 'POST']);

							?>

							<div class="row g-3">
								<div class="col-md-6">
									<div class="form-group">
										<label for="name" class="form-label">Name <span class="text-danger">*</span></label>
										<?php
										$formwriter->textinput('name', '', [
											'class' => 'form-control',
											'maxlength' => 255,
											'required' => true,
											'minlength' => 2
										]);
										?>
									</div>
								</div>
								<div class="col-12">
									<div class="form-group">
										<label for="cmt" class="form-label">Comment <span class="text-danger">*</span></label>
										<?php
										$formwriter->textbox('cmt', '', [
											'class' => 'form-control',
											'rows' => 4,
											'cols' => 80,
											'required' => true,
											'minlength' => 20,
											'data-msg-minlength' => 'Comment must be at least 20 characters'
										]);
										?>
									</div>
								</div>

								<?php if(!$page_vars['session']->get_user_id()): ?>
								<div class="col-12">
									<?php
									$formwriter->antispam_question_input('blog');
									$formwriter->honeypot_hidden_input();
									$formwriter->honeypot_hidden_input('Comment', 'comment');
									$formwriter->captcha_hidden_input('blog');
									?>
								</div>
								<?php endif; ?>

								<div class="col-12">
									<div class="d-flex justify-content-end">
										<?php
										$formwriter->submitbutton('btn_submit', 'Post Comment', [
											'class' => 'btn btn-primary'
										]);
										?>
									</div>
								</div>
							</div>

							<?php $formwriter->end_form(); ?>
						</div>
					</div>
					<?php endif; ?>

					<!-- Comments Display -->
					<?php if($page_vars['settings']->get_setting('show_comments') && $page_vars['numcomments']): ?>
					<div class="card shadow-sm rounded-4">
						<div class="card-header bg-light">
							<h4 class="mb-0">Comments (<?php echo $page_vars['numcomments']; ?>)</h4>
						</div>
						<div class="card-body p-0">
							<script>
							document.addEventListener('DOMContentLoaded', function(){
								document.querySelectorAll('.commentbutton').forEach(function(btn){
									btn.addEventListener('click', function(){
										var container = document.getElementById(this.id + 'container');
										if(container) container.style.display = container.style.display === 'none' ? '' : 'none';
									});
								});
							});
							</script>

							<?php foreach($page_vars['comments'] as $comment): ?>
							<div class="border-bottom p-4">
								<div class="d-flex align-items-start">
									<img class="rounded-circle me-3" src="/includes/images/blank-avatar.png" width="50" height="50" alt="Avatar">
									<div class="flex-grow-1">
										<div class="d-flex justify-content-between align-items-start mb-2">
											<div>
												<h6 class="mb-0"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></h6>
												<small class="text-muted"><?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></small>
											</div>
										</div>
										<div class="mb-3">
											<?php echo $comment->get_sanitized_comment(); ?>
										</div>
										<button id="comment<?php echo $comment->key; ?>" class="commentbutton btn btn-outline-primary btn-sm">Reply</button>

										<!-- Reply Form -->
										<?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
										<div id="comment<?php echo $comment->key; ?>container" style="display:none;" class="mt-3 p-3 bg-light rounded">
											<?php
							$formwriter = $page->getFormWriter('form'.$comment->key, ['action' => $_SERVER['REQUEST_URI'], 'method' => 'POST']);


							$formwriter->hiddeninput('cmt_comment_id_parent', $comment->key);
											?>

											<div class="row g-3">
												<div class="col-md-6">
													<div class="form-group">
														<label class="form-label">Your name</label>
														<?php
														$formwriter->textinput('name', '', [
															'class' => 'form-control',
															'maxlength' => 255,
															'required' => true,
															'minlength' => 2
														]);
														?>
													</div>
												</div>
												<div class="col-12">
													<div class="form-group">
														<label class="form-label">Your reply</label>
														<?php
														$formwriter->textbox('cmt', '', [
															'class' => 'form-control',
															'rows' => 3,
															'cols' => 80,
															'required' => true,
															'minlength' => 20,
															'data-msg-minlength' => 'Comment must be at least 20 characters'
														]);
														?>
													</div>
												</div>

												<?php if(!$page_vars['session']->get_user_id()): ?>
												<div class="col-12">
													<?php
													$formwriter->antispam_question_input('blog');
													$formwriter->honeypot_hidden_input();
													$formwriter->honeypot_hidden_input('Comment', 'comment');
													$formwriter->captcha_hidden_input('blog');
													?>
												</div>
												<?php endif; ?>

												<div class="col-12">
													<div class="d-flex justify-content-end">
														<?php
														$formwriter->submitbutton('btn_submit', 'Reply', [
															'class' => 'btn btn-primary btn-sm'
														]);
														?>
													</div>
												</div>
											</div>

											<?php $formwriter->end_form(); ?>
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
											<div class="mt-3">
												<?php foreach($replies as $reply): ?>
													<?php if($reply->get('cmt_comment_id_parent') == $comment->key): ?>
													<div class="d-flex align-items-start mt-3 ms-4">
														<img class="rounded-circle me-3" src="/includes/images/blank-avatar.png" width="40" height="40" alt="Avatar">
														<div class="flex-grow-1">
															<div class="bg-light p-3 rounded">
																<div class="d-flex justify-content-between align-items-start mb-1">
																	<h6 class="mb-0 small"><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></h6>
																	<small class="text-muted"><?php echo LibraryFunctions::convert_time($reply->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></small>
																</div>
																<div class="small">
																	<?php echo $reply->get_sanitized_comment(); ?>
																</div>
															</div>
														</div>
													</div>
													<?php endif; ?>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

		</div>
	</div>
</section>

<?php
echo PublicPage::EndPage();
$page->public_footer(array('track'=>TRUE));
?>
