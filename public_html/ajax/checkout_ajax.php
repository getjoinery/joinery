<?php
/**
 * AJAX endpoints for the accordion checkout.
 * Actions: validate_section, apply_coupon, remove_coupon, check_email
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
$settings = Globalvars::get_instance();
$cart = $session->get_shopping_cart();

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch ($action) {

    case 'validate_section':
        $section = isset($_POST['section']) ? $_POST['section'] : '';
        $data = isset($_POST['data']) ? $_POST['data'] : array();

        require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));
        $errors = validate_checkout_section($section, $data);

        if (empty($errors)) {
            // Determine next section
            $section_order = array('contact', 'billing', 'payment');
            if ($settings->get_setting('coupons_active')) {
                array_splice($section_order, 1, 0, 'coupon');
            }
            $idx = array_search($section, $section_order);
            $next = ($idx !== false && $idx < count($section_order) - 1) ? $section_order[$idx + 1] : null;

            $summary = '';
            if ($section == 'contact') {
                $summary = htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8');
            } elseif ($section == 'billing') {
                $summary = htmlspecialchars(($data['billing_first_name'] ?? '') . ' ' . ($data['billing_last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            }

            echo json_encode(array('valid' => true, 'summary' => $summary, 'next_section' => $next));
        } else {
            echo json_encode(array('valid' => false, 'errors' => $errors));
        }
        break;

    case 'apply_coupon':
        $code = isset($_POST['coupon_code']) ? trim($_POST['coupon_code']) : '';
        if (empty($code)) {
            echo json_encode(array('success' => false, 'error' => 'Please enter a coupon code.'));
            break;
        }

        $result = $cart->add_coupon($code);
        if ($result === 1) {
            $currency_symbol = \Product::$currency_symbols[$settings->get_setting('site_currency')];
            echo json_encode(array(
                'success' => true,
                'coupon_codes' => $cart->coupon_codes,
                'total' => $currency_symbol . number_format($cart->get_total(), 2, '.', ','),
            ));
        } else {
            echo json_encode(array('success' => false, 'error' => $result));
        }
        break;

    case 'remove_coupon':
        $code = isset($_POST['coupon_code']) ? trim($_POST['coupon_code']) : '';
        $cart->remove_coupon($code);
        $currency_symbol = \Product::$currency_symbols[$settings->get_setting('site_currency')];
        echo json_encode(array(
            'success' => true,
            'coupon_codes' => $cart->coupon_codes,
            'total' => $currency_symbol . number_format($cart->get_total(), 2, '.', ','),
        ));
        break;

    case 'check_email':
        $email = isset($_GET['email']) ? trim($_GET['email']) : '';
        $exists = false;
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $exists = (User::GetByEmail($email) !== null && User::GetByEmail($email) !== false);
        }
        echo json_encode(array('exists' => $exists));
        break;

    case 'submit_survey':
        require_once(PathHelper::getIncludePath('data/survey_questions_class.php'));
        require_once(PathHelper::getIncludePath('data/questions_class.php'));
        require_once(PathHelper::getIncludePath('data/survey_answers_class.php'));
        require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));

        $survey_id = isset($_POST['survey_id']) ? intval($_POST['survey_id']) : 0;
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $user_id = $session->get_user_id();

        if ($survey_id && $user_id) {
            $sq = new MultiSurveyQuestion(
                array('survey_id' => $survey_id, 'deleted' => false),
                array('srq_order' => 'ASC')
            );
            $sq->load();

            foreach ($sq as $survey_question) {
                $question_id = $survey_question->get('srq_qst_question_id');
                $question = new Question($question_id, true);
                // Try multiple field name patterns
                $field_name = 'question_' . $question_id;
                $answer = isset($_POST[$field_name]) ? $_POST[$field_name] : '';
                if ($answer === '') {
                    $field_name = 'confirm_survey_q_' . $question_id;
                    $answer = isset($_POST[$field_name]) ? $_POST[$field_name] : '';
                }

                if ($answer !== '') {
                    $readable = $question->get_answer_readable($answer, false);
                    $survey_answer = new SurveyAnswer(NULL);
                    $survey_answer->set('sva_svy_survey_id', $survey_id);
                    $survey_answer->set('sva_qst_question_id', $question_id);
                    $survey_answer->set('sva_usr_user_id', $user_id);
                    $survey_answer->set('sva_answer', $readable);
                    $survey_answer->save();
                }
            }

            // Mark survey completed on event registrant
            if ($event_id) {
                $registrant = EventRegistrant::check_if_registrant_exists($user_id, $event_id);
                if ($registrant) {
                    $registrant->set('evr_survey_completed', true);
                    $registrant->save();
                }
            }

            echo json_encode(array('success' => true));
        } else {
            echo json_encode(array('success' => false, 'error' => 'Not logged in or invalid survey'));
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Unknown action'));
        break;
}
