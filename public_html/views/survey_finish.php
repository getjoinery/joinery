<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('data/surveys_class.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $survey_id = LibraryFunctions::decode(LibraryFunctions::fetch_variable('survey_id', null, 0, 'Survey id is required'));
    $survey    = new Survey($survey_id, true);

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Survey Finished',
    ]);
    echo PublicPage::BeginPage($survey->get('svy_name'));
?>
<div class="jy-ui">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <div class="text-center mb-4">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>

            <div class="card shadow-sm rounded-4">
                <div class="card-body p-4 text-center">
                    <h3 class="text-success mb-3">Survey Complete</h3>
                    <p class="text-muted mb-4">
                        You have successfully finished the survey &ldquo;<?php echo htmlspecialchars($survey->get('svy_name')); ?>&rdquo;.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="/" class="btn btn-primary">Back to Home</a>
                        <a href="/surveys" class="btn btn-outline">More Surveys</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

</div>
<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
