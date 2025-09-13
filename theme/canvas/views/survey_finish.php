<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/surveys_class.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');

	$survey_id = LibraryFunctions::decode(LibraryFunctions::fetch_variable('survey_id', NULL, 0, 'Survey id is required'));

	$survey = new Survey($survey_id, TRUE);

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Survey Finished'
	));
	echo PublicPage::BeginPage($survey->get('svy_name'));
?>

<!-- Canvas Survey Finish Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8 col-xl-7">
					
					<!-- Success Message -->
					<div class="text-center mb-5">
						<div class="text-success mb-3">
							<i class="icon-check-circle display-3"></i>
						</div>
						<h1 class="h2 mb-2"><?php echo $survey->get('svy_name'); ?></h1>
					</div>

					<!-- Completion Card -->
					<div class="card shadow-sm rounded-4 border-0">
						<div class="card-body p-4 p-lg-5 text-center">
							<h3 class="mb-3 text-success">Survey Complete</h3>
							<p class="text-muted mb-4">
								You have successfully finished the survey "<?php echo $survey->get('svy_name'); ?>".
							</p>
							
							<div class="row g-3 justify-content-center">
								<div class="col-auto">
									<a href="/" class="btn btn-primary rounded-pill">
										<i class="icon-home me-2"></i>Back to Home
									</a>
								</div>
								<div class="col-auto">
									<a href="/surveys" class="btn btn-outline-secondary rounded-pill">
										<i class="icon-clipboard-list me-2"></i>More Surveys
									</a>
								</div>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>