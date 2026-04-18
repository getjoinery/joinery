<?php
require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$session    = SessionControl::get_instance();
$session_id = $_GET['session_id'];
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
        <div style="max-width: 680px; margin: 0 auto;">

            <?php if ($receipts): ?>

            <!-- Success -->
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <div style="font-size: 3.5rem; color: #198754; margin-bottom: 1rem;">&#10003;</div>
                <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Purchase Confirmed!</h1>
                <p style="color: var(--jy-color-text-muted);">Thank you for your purchase. An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need.</p>
            </div>

            <!-- Order Summary -->
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--jy-color-primary); color: #fff; padding: 1rem 1.5rem;">
                    <h5 style="margin: 0; color: #fff;">Order Summary</h5>
                </div>
                <div style="overflow-x: auto;">
                    <table class="styled-table" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th style="padding: 0.875rem 1.5rem; text-align: left;">Item</th>
                                <th style="padding: 0.875rem 1.5rem; text-align: right;">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total = 0;
                            foreach ($receipts as $receipt):
                                $total += $receipt['price'];
                            ?>
                            <tr>
                                <td style="padding: 0.875rem 1.5rem;">
                                    <strong><?php echo $receipt['pname']; ?></strong><br>
                                    <small style="color: var(--jy-color-text-muted);"><?php echo $receipt['name']; ?></small>
                                </td>
                                <td style="padding: 0.875rem 1.5rem; text-align: right; font-weight: 600;">
                                    $<?php echo number_format($receipt['price'], 2, '.', ','); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: var(--jy-color-surface);">
                                <td style="padding: 0.875rem 1.5rem; font-weight: 700;">Total</td>
                                <td style="padding: 0.875rem 1.5rem; text-align: right; font-weight: 700; font-size: 1.125rem; color: var(--jy-color-primary);">
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
            <div id="survey-section-<?php echo $survey_info['survey_id']; ?>" style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--jy-color-surface); padding: 1rem 1.5rem; border-bottom: 1px solid var(--jy-color-border);">
                    <h5 style="margin: 0;">We'd Love Your Feedback</h5>
                    <small style="color: var(--jy-color-text-muted);"><?php echo htmlspecialchars($survey_info['event_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div style="padding: 1.5rem;" id="survey-form-<?php echo $survey_info['survey_id']; ?>">
                    <?php
                    $formwriter = $page->getFormWriter('survey_form_' . $survey_info['survey_id']);
                    foreach ($survey_questions as $sq) {
                        $question = new Question($sq->get('srq_qst_question_id'), true);
                        $field_name = 'confirm_survey_q_' . $question->key;
                        $question->output_question($formwriter, null);
                    }
                    ?>
                    <div style="margin-top: 1.25rem;">
                        <button type="button" class="btn btn-primary" onclick="submitConfirmSurvey(<?php echo $survey_info['survey_id']; ?>, <?php echo $survey_info['event_id']; ?>)" style="width: 100%;">Submit Feedback</button>
                    </div>
                </div>
                <div id="survey-thanks-<?php echo $survey_info['survey_id']; ?>" style="display: none; padding: 2rem; text-align: center;">
                    <div style="font-size: 2rem; color: #198754; margin-bottom: 0.5rem;">&#10003;</div>
                    <p style="color: var(--jy-color-text-muted);">Thank you for your feedback!</p>
                </div>
            </div>
            <?php
                    endif;
                endforeach;
            endif;
            ?>

            <!-- Next Steps -->
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; text-align: center;">
                <h5 style="margin-bottom: 0.75rem;">What's Next?</h5>
                <p style="color: var(--jy-color-text-muted); margin-bottom: 1.5rem;">All of your purchases can be found in the My Profile section of the website.</p>
                <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                    <a href="/profile" class="btn btn-primary">View All Purchases</a>
                    <a href="/" class="btn btn-outline">Back to Home</a>
                </div>
            </div>

            <?php else: ?>

            <!-- Error State -->
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <div style="font-size: 3.5rem; color: #ffc107; margin-bottom: 1rem;">&#9888;</div>
                <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Purchase Not Found</h1>
            </div>

            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2.5rem; text-align: center;">
                <p style="color: var(--jy-color-text-muted); margin-bottom: 1.5rem;">Your recent purchase is not available. It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>

                <?php $defaultemail = $settings->get_setting('defaultemail'); if ($defaultemail): ?>
                <div class="alert alert-info" style="margin-bottom: 1.5rem; text-align: left;">
                    <strong>Need Help?</strong> If you think something is wrong, please contact us at
                    <a href="mailto:<?php echo $defaultemail; ?>"><?php echo $defaultemail; ?></a>
                </div>
                <?php endif; ?>

                <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
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
                document.getElementById('survey-form-' + surveyId).style.display = 'none';
                document.getElementById('survey-thanks-' + surveyId).style.display = 'block';
            }
        });
}
</script>

</div>
<?php
$page->public_footer(['track' => true]);
?>
