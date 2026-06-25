<?php
require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session    = SessionControl::get_instance();
$session_id = $_GET['session_id'] ?? null;
$settings   = Globalvars::get_instance();

$cart     = $session->get_shopping_cart();
$receipts = $cart->last_receipt;

$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => 'Checkout confirmation',
]);
?>
<div class="jy-ui">

<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-cc-wrap">

            <?php if ($receipts): ?>

            <!-- Success -->
            <div class="jy-cc-hero">
                <div class="jy-cc-icon-ok">&#10003;</div>
                <h1 class="jy-cc-h1">Purchase Confirmed!</h1>
                <p class="jy-muted">Thank you for your purchase. An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need.</p>
            </div>

            <!-- Order Summary -->
            <div class="jy-cc-card">
                <div class="jy-cc-head-primary">
                    <h5 class="jy-cc-head-title">Order Summary</h5>
                </div>
                <div class="jy-scroll-x">
                    <table class="styled-table jy-cc-table">
                        <thead>
                            <tr>
                                <th class="jy-cc-cell-l">Item</th>
                                <th class="jy-cc-cell-r">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total = 0;
                            foreach ($receipts as $receipt):
                                $total += $receipt['price'];
                            ?>
                            <tr>
                                <td class="jy-cc-cell">
                                    <strong><?php echo $receipt['pname']; ?></strong><br>
                                    <small class="jy-muted"><?php echo $receipt['name']; ?></small>
                                    <?php if (!empty($receipt['after_purchase_message'])): ?>
                                    <div class="jy-cc-after-msg"><?php echo $receipt['after_purchase_message']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="jy-cc-cell-price">
                                    $<?php echo number_format($receipt['price'], 2, '.', ','); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="jy-cc-foot-row">
                                <td class="jy-cc-cell-totlabel">Total</td>
                                <td class="jy-cc-cell-tot">
                                    $<?php echo number_format($total, 2, '.', ','); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Optional Survey -->
            <?php
            $confirmation_surveys = $session->get_saved_item('confirmation_surveys');
            if (!empty($confirmation_surveys)):
                require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
                require_once(PathHelper::getIncludePath('data/questions_class.php'));
                foreach ($confirmation_surveys as $survey_info):
                    $survey_questions = new MultiSurveyQuestion(
                        array('survey_id' => $survey_info['survey_id'], 'deleted' => false),
                        array('srq_order' => 'ASC')
                    );
                    $survey_questions->load();
                    if (count($survey_questions) > 0):
            ?>
            <div id="survey-section-<?php echo $survey_info['survey_id']; ?>" class="jy-cc-card">
                <div class="jy-cc-head-surface">
                    <h5 class="jy-tight">We'd Love Your Feedback</h5>
                    <small class="jy-muted"><?php echo htmlspecialchars($survey_info['event_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="jy-cc-body" id="survey-form-<?php echo $survey_info['survey_id']; ?>">
                    <?php
                    $formwriter = $page->getFormWriter('survey_form_' . $survey_info['survey_id']);
                    foreach ($survey_questions as $sq) {
                        $question = new Question($sq->get('srq_qst_question_id'), true);
                        $field_name = 'confirm_survey_q_' . $question->key;
                        $question->output_question($formwriter, null);
                    }
                    ?>
                    <div class="jy-cc-actions">
                        <button type="button" class="btn btn-primary jy-w-full" onclick="submitConfirmSurvey(<?php echo $survey_info['survey_id']; ?>, <?php echo $survey_info['event_id']; ?>)">Submit Feedback</button>
                    </div>
                </div>
                <div id="survey-thanks-<?php echo $survey_info['survey_id']; ?>" hidden class="jy-cc-thanks">
                    <div class="jy-cc-icon-sm-ok">&#10003;</div>
                    <p class="jy-muted">Thank you for your feedback!</p>
                </div>
            </div>
            <?php
                    endif;
                endforeach;
            endif;
            ?>

            <!-- Next Steps -->
            <div class="jy-cc-panel">
                <h5 class="jy-cc-panel-title">What's Next?</h5>
                <p class="jy-cc-panel-text">All of your purchases can be found in the My Profile section of the website.</p>
                <div class="jy-cc-actions-row">
                    <a href="/profile" class="btn btn-primary">View All Purchases</a>
                    <a href="/" class="btn btn-outline">Back to Home</a>
                </div>
            </div>

            <?php else: ?>

            <!-- Error State -->
            <div class="jy-cc-hero">
                <div class="jy-cc-icon-warn">&#9888;</div>
                <h1 class="jy-cc-h1">Purchase Not Found</h1>
            </div>

            <div class="jy-cc-panel-lg">
                <p class="jy-cc-panel-text">Your recent purchase is not available. It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>

                <?php $defaultemail = $settings->get_setting('defaultemail'); if ($defaultemail): ?>
                <div class="alert alert-info jy-cc-alert">
                    <strong>Need Help?</strong> If you think something is wrong, please contact us at
                    <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>
                </div>
                <?php endif; ?>

                <div class="jy-cc-actions-row">
                    <a href="/cart" class="btn btn-primary">Return to Cart</a>
                    <a href="/" class="btn btn-outline">Back to Home</a>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</section>

<script>
function submitConfirmSurvey(surveyId, eventId) {
    var form = document.getElementById('survey-form-' + surveyId);
    if (!form) return;

    // Collect all question answers from the form
    var inputs = form.querySelectorAll('input, select, textarea');
    var formData = new FormData();
    formData.append('action', 'submit_survey');
    formData.append('survey_id', surveyId);
    formData.append('event_id', eventId);

    inputs.forEach(function(input) {
        if (input.type === 'checkbox') {
            formData.append(input.name, input.checked ? input.value || '1' : '');
        } else if (input.type === 'radio') {
            if (input.checked) formData.append(input.name, input.value);
        } else {
            formData.append(input.name, input.value);
        }
    });

    fetch('/ajax/checkout_ajax', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('survey-form-' + surveyId).hidden = true;
                document.getElementById('survey-thanks-' + surveyId).hidden = false;
            }
        });
}
</script>

</div>
<?php
$page->public_footer(['track' => true]);
?>
