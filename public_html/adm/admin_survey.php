<?php
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_survey_logic.php'));

// Process logic
$page_vars = process_logic(admin_survey_logic($_GET, $_POST));
extract($page_vars);

// Build dropdown actions
$options['altlinks'] = array('Edit Survey' => '/admin/admin_survey_edit?svy_survey_id=' . $survey->key);
if (!$survey->get('svy_delete_time') && $_SESSION['permission'] >= 8) {
    $options['altlinks']['Soft Delete'] = '/admin/admin_survey?action=delete&svy_survey_id=' . $survey->key;
} else if ($_SESSION['permission'] >= 8) {
    $options['altlinks']['Undelete'] = '/admin/admin_survey?action=undelete&svy_survey_id=' . $survey->key;
}

// Build dropdown button from altlinks
$dropdown_button = '';
if (!empty($options['altlinks'])) {
    $dropdown_button = '<div class="dropdown">';
    $dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
    $dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
    foreach ($options['altlinks'] as $label => $url) {
        $is_danger = strpos($label, 'Delete') !== false;
        $dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
    }
    $dropdown_button .= '</div>';
    $dropdown_button .= '</div>';
}

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'surveys',
    'page_title' => 'Survey',
    'readable_title' => $survey->get('svy_name'),
    'breadcrumbs' => [
        'Surveys' => '/admin/admin_surveys',
        $survey->get('svy_name') => '',
    ],
    'session' => $session,
    'no_page_card' => true,
    'header_action' => $dropdown_button,
]);

// Count total responses (would need to query survey_answers table to get actual count)
$total_responses = 0;
?>

<!-- Two Column Layout -->
<div class="row g-3 mb-3">
    <!-- Left Column -->
    <div class="col-xxl-6">
        <!-- Survey Information Card -->
        <div class="card mb-3">
            <div class="card-header bg-body-tertiary">
                <h5 class="mb-0">Survey Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0" style="font-size: 0.875rem;">
                    <tbody>
                        <tr>
                            <td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Name</td>
                            <td class="p-1 text-600"><?php echo htmlspecialchars($survey->get('svy_name')); ?></td>
                        </tr>
                        <?php if (!$survey->get('svy_delete_time')): ?>
                            <tr>
                                <td class="p-1 text-800 fw-semi-bold">Survey Link</td>
                                <td class="p-1 text-600"><a href="/survey?survey_id=<?php echo LibraryFunctions::encode($survey->key); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url('/survey?survey_id=' . LibraryFunctions::encode($survey->key))); ?></a></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="p-1 text-800 fw-semi-bold">Total Questions</td>
                            <td class="p-1 text-600"><?php echo $survey_questions->count(); ?></td>
                        </tr>
                        <tr>
                            <td class="p-1 text-800 fw-semi-bold">Total Responses</td>
                            <td class="p-1 text-600"><?php echo $total_responses; ?></td>
                        </tr>
                        <?php if ($survey->get('svy_create_time')): ?>
                            <tr>
                                <td class="p-1 text-800 fw-semi-bold">Created</td>
                                <td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($survey->get('svy_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($survey->get('svy_last_edit_time')): ?>
                            <tr>
                                <td class="p-1 text-800 fw-semi-bold">Last Edited</td>
                                <td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($survey->get('svy_last_edit_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="p-1 text-800 fw-semi-bold">Status</td>
                            <td class="p-1">
                                <?php if ($survey->get('svy_delete_time')): ?>
                                    <span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($survey->get('svy_delete_time'), 'UTC', $session->get_timezone()); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-subtle-success">Active</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-xxl-6">
        <!-- Associated Events Card (if applicable) -->
        <?php
        // Check if survey is associated with events
        $associated_events = array(); // Would need to implement this relationship
        if (!empty($associated_events)):
        ?>
            <div class="card mb-3">
                <div class="card-header bg-body-tertiary">
                    <h5 class="mb-0">Associated Events</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($associated_events as $event): ?>
                            <a href="/admin/admin_event?evt_event_id=<?php echo $event->key; ?>" class="list-group-item list-group-item-action px-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($event->get('evt_name')); ?></h6>
                                        <small class="text-600">Event info here</small>
                                    </div>
                                    <i class="bi bi-chevron-right text-600"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Survey Questions Table
$headers = array('Question', 'Action');
$altlinks = array();
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
    'altlinks' => $altlinks,
    'title' => 'Survey Questions (' . $survey_questions->count() . ')',
    'card' => true
);
$page->tableheader($headers, $table_options, $pager);

// Prepare remove forms with deferred output
$remove_forms = [];

foreach ($survey_questions as $survey_question) {
    $question = new Question($survey_question->get('srq_qst_question_id'), TRUE);

    $rowvalues = array();
    $rowvalues[] = '<a href="/admin/admin_question?qst_question_id=' . $survey_question->get('srq_qst_question_id') . '">' . htmlspecialchars($question->get('qst_question')) . '</a>';

    // Create form with deferred output
    $form_id = 'remove_form_' . $survey_question->key;
    $formwriter = $page->getFormWriter($form_id, 'v2', [
        'deferred_output' => true,
        'action' => '/admin/admin_survey'
    ]);

    $formwriter->begin_form();
    $formwriter->hiddeninput('action', ['value' => 'removequestion']);
    $formwriter->hiddeninput('svy_survey_id', ['value' => $survey->key]);
    $formwriter->hiddeninput('srq_survey_question_id', ['value' => $survey_question->key]);
    $formwriter->submitbutton('remove_button', 'Remove', ['class' => 'btn btn-sm btn-danger']);
    $formwriter->end_form();

    $remove_forms[] = $formwriter->get_deferred_output();
    $rowvalues[] = '<div id="' . $form_id . '"></div>';

    $page->disprow($rowvalues);
}

// Add question form
if ($numquestions) {
    echo '<tr><td colspan="3">';

    $formwriter = $page->getFormWriter('form_add_question', 'v2', [
        'action' => '/admin/admin_survey?svy_survey_id=' . $survey->key
    ]);

    $formwriter->begin_form();
    $formwriter->hiddeninput('action', ['value' => 'addquestion']);
    $formwriter->hiddeninput('svy_survey_id', ['value' => $survey->key]);

    $optionvals = $questions->get_dropdown_array();
    $formwriter->dropinput('qst_question_id', 'Add question to survey', [
        'options' => $optionvals
    ]);

    $formwriter->submitbutton('add_button', 'Add');
    $formwriter->end_form();

    echo '</td></tr>';
} else {
    echo '<tr><td colspan="3">There are no questions. <a href="/admin/admin_questions">Add one</a>.</td></tr>';
}

$page->endtable($pager);

// Output deferred forms
foreach ($remove_forms as $form_html) {
    echo $form_html;
}

$page->admin_footer();
?>