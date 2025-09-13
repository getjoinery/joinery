<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('video_logic.php', 'logic'));

	$page_vars = video_logic($_GET, $_POST, $video, $params);
	$video = $page_vars['video'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $video->get('vid_title')
	));
	echo PublicPage::BeginPage($video->get('vid_title'));
?>

<!-- Canvas Video Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-10 col-xl-9">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2"><?php echo $video->get('vid_title'); ?></h1>
						<?php if($video->get('vid_description')): ?>
							<p class="text-muted"><?php echo $video->get('vid_description'); ?></p>
						<?php endif; ?>
					</div>

					<!-- Video Player -->
					<div class="card shadow-sm rounded-4 border-0">
						<div class="card-body p-0">
							<div class="ratio ratio-16x9 rounded-4 overflow-hidden">
								<?php echo $video->get_embed(); ?>
							</div>
						</div>
						
						<?php if($video->get('vid_description') || $video->get('vid_duration')): ?>
						<div class="card-body">
							<div class="row align-items-center">
								<div class="col">
									<?php if($video->get('vid_description')): ?>
										<div class="prose-content">
											<?php echo $video->get('vid_description'); ?>
										</div>
									<?php endif; ?>
								</div>
								<?php if($video->get('vid_duration')): ?>
								<div class="col-auto">
									<span class="badge bg-secondary rounded-pill">
										<i class="icon-clock me-1"></i><?php echo $video->get('vid_duration'); ?>
									</span>
								</div>
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>
					</div>

					<!-- Additional Information -->
					<?php if($video->get('vid_transcript') || $video->get('vid_tags')): ?>
					<div class="row g-4 mt-4">
						<?php if($video->get('vid_transcript')): ?>
						<div class="col-lg-8">
							<div class="card shadow-sm rounded-4 border-0">
								<div class="card-header bg-light rounded-top-4">
									<h5 class="mb-0"><i class="icon-file-text me-2"></i>Transcript</h5>
								</div>
								<div class="card-body prose-content">
									<?php echo nl2br($video->get('vid_transcript')); ?>
								</div>
							</div>
						</div>
						<?php endif; ?>
						
						<?php if($video->get('vid_tags')): ?>
						<div class="col-lg-4">
							<div class="card shadow-sm rounded-4 border-0">
								<div class="card-header bg-light rounded-top-4">
									<h6 class="mb-0"><i class="icon-tag me-2"></i>Tags</h6>
								</div>
								<div class="card-body">
									<?php 
									$tags = explode(',', $video->get('vid_tags'));
									foreach($tags as $tag): 
										$tag = trim($tag);
										if($tag):
									?>
										<span class="badge bg-primary rounded-pill me-1 mb-1"><?php echo $tag; ?></span>
									<?php 
										endif;
									endforeach; 
									?>
								</div>
							</div>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>