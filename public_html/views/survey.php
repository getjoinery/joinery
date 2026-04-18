<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('survey_logic.php', 'logic'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

    $page_vars = process_logic(survey_logic($_GET, $_POST));
    $survey = $page_vars['survey'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Surveys',
    ]);
    echo PublicPage::BeginPage($survey->get('svy_name'));
?>
<div class="jy-ui">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <?php if ($survey->get('svy_description')): ?>
            <p class="text-muted text-center mb-4"><?php echo htmlspecialchars($survey->get('svy_description')); ?></p>
            <?php endif; ?>

            <div class="card shadow-sm rounded-4">
                <div class="card-body p-4">
                    <?php
                    $formwriter = $page->getFormWriter('form1', ['action' => '/survey']);
                    $formwriter->begin_form();

                    if ($invalid_messages): ?>
                        <div class="alert alert-danger mb-4" role="alert">
                            <h6 class="alert-heading mb-2">Please correct the following:</h6>
                            <?php foreach ($invalid_messages as $invalid_message): ?>
                                <div><?php echo $invalid_message; ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif;

                    foreach ($page_vars['survey_questions'] as $survey_question):
                        $question = new Question($survey_question->get('srq_qst_question_id'), true);

                        foreach ($page_vars['survey_answers'] as $survey_answer):
                            $answer_fill = null;
                            if ($survey_answer->get('sva_qst_question_id') == $question->key) {
                                if ($question->get('qst_type') == Question::TYPE_CHECKBOX_LIST) {
                                    $answer_fill = explode(',', $survey_answer->get('sva_answer'));
                                } else {
                                    $answer_fill = $survey_answer->get('sva_answer');
                                }
                                break;
                            }
                        endforeach;

                        echo '<input type="hidden" name="survey_id" value="' . LibraryFunctions::encode($survey->key) . '">';
                        ?>
                        <div class="mb-4">
                            <?php echo $question->output_question($formwriter, $answer_fill); ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Submit Survey</button>
                    </div>

                    <?php echo $formwriter->end_form(); ?>
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
