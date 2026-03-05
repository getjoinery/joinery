<?php
/**
 * Data Migration: Product Requirements Refactor
 *
 * Converts existing product configurations from the bitmask system
 * and old prq/pri system to the new pri_class_name based system.
 *
 * Steps:
 * 1. Create Question records for Tier 1 requirements (Comment, GDPR, RecordConsent)
 * 2. For each product with pro_requirements > 0, create pri rows from bitmask
 * 3. Convert existing prq/pri rows to new format
 *
 * @version 1.0
 */
function migrate_product_requirements() {
    require_once(PathHelper::getIncludePath('data/products_class.php'));
    require_once(PathHelper::getIncludePath('data/questions_class.php'));
    require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
    require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));

    $migrated_count = 0;

    // ────────────────────────────────────────────────────────────
    // Step 1: Create Questions for Tier 1 requirements
    // ────────────────────────────────────────────────────────────

    // Comment → long_text Question
    $comment_q = new Question(NULL);
    $comment_q->set('qst_question', 'Comment');
    $comment_q->set('qst_type', Question::TYPE_LONG_TEXT);
    $comment_q->set('qst_is_required', false);
    $comment_q->set('qst_is_published', true);
    $comment_q->save();
    error_log("Migration: Created Comment question ID=" . $comment_q->key);

    // GDPR Notice → confirmation Question
    $gdpr_q = new Question(NULL);
    $gdpr_q->set('qst_question', 'GDPR Notice');
    $gdpr_q->set('qst_type', Question::TYPE_CONFIRMATION);
    $gdpr_q->set('qst_config', json_encode([
        'body_text' => 'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our privacy policy.',
        'checkbox_label' => 'I have read and agree to the privacy policy.',
        'scrollable' => true,
    ]));
    $gdpr_q->set('qst_is_required', true);
    $gdpr_q->set('qst_is_published', true);
    $gdpr_q->save();
    error_log("Migration: Created GDPR question ID=" . $gdpr_q->key);

    // Recording Consent → confirmation Question
    $consent_q = new Question(NULL);
    $consent_q->set('qst_question', 'Consent to Record');
    $consent_q->set('qst_type', Question::TYPE_CONFIRMATION);
    $consent_q->set('qst_config', json_encode([
        'checkbox_label' => 'I am aware that the course/event may be recorded and consent to being recorded.',
    ]));
    $consent_q->set('qst_is_required', true);
    $consent_q->set('qst_is_published', true);
    $consent_q->save();
    error_log("Migration: Created Recording Consent question ID=" . $consent_q->key);

    // ────────────────────────────────────────────────────────────
    // Step 2: Bitmask → pri rows
    // ────────────────────────────────────────────────────────────

    // Tier 2: custom classes (direct mapping)
    $custom_map = [
        1 => 'FullNameRequirement',
        2 => 'PhoneNumberRequirement',
        4 => 'DOBRequirement',
        8 => 'AddressRequirement',
        64 => 'EmailRequirement',
        128 => 'UserPriceRequirement',
        256 => 'NewsletterSignupRequirement',
    ];

    // Tier 1: Questions (created above)
    $question_map = [
        16 => $gdpr_q->key,
        32 => $consent_q->key,
        512 => $comment_q->key,
    ];

    // Load all products with requirements
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    $sql = "SELECT pro_product_id, pro_requirements FROM pro_products WHERE pro_requirements > 0 AND pro_requirements IS NOT NULL";
    $q = $dblink->prepare($sql);
    $q->execute();
    $products_with_requirements = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products_with_requirements as $product_row) {
        $product_id = $product_row['pro_product_id'];
        $bitmask = intval($product_row['pro_requirements']);
        $order = 0;

        // Tier 2 custom classes
        foreach ($custom_map as $bit => $class_name) {
            if ($bitmask & $bit) {
                $instance = new ProductRequirementInstance(NULL);
                $instance->set('pri_pro_product_id', $product_id);
                $instance->set('pri_class_name', $class_name);
                $instance->set('pri_order', $order++);
                $instance->save();
                $migrated_count++;
            }
        }

        // Tier 1 questions
        foreach ($question_map as $bit => $question_id) {
            if ($bitmask & $bit) {
                $instance = new ProductRequirementInstance(NULL);
                $instance->set('pri_pro_product_id', $product_id);
                $instance->set('pri_class_name', 'QuestionRequirement');
                $instance->set('pri_config', json_encode(['question_id' => $question_id]));
                $instance->set('pri_order', $order++);
                $instance->save();
                $migrated_count++;
            }
        }
    }

    error_log("Migration: Migrated $migrated_count bitmask requirements across " . count($products_with_requirements) . " products");

    // ────────────────────────────────────────────────────────────
    // Step 3: Existing prq/pri → new pri rows
    // ────────────────────────────────────────────────────────────

    // Convert existing old-style pri rows (with pri_prq_product_requirement_id) to new format
    $sql = "SELECT pri.pri_product_requirement_instance_id, pri.pri_pro_product_id, pri.pri_prq_product_requirement_id, pri.pri_delete_time,
                   prq.prq_qst_question_id, prq.prq_title
            FROM pri_product_requirement_instances pri
            JOIN prq_product_requirements prq ON prq.prq_product_requirement_id = pri.pri_prq_product_requirement_id
            WHERE pri.pri_class_name IS NULL
            AND pri.pri_prq_product_requirement_id IS NOT NULL";
    $q = $dblink->prepare($sql);
    $q->execute();
    $old_instances = $q->fetchAll(PDO::FETCH_ASSOC);

    $converted_count = 0;
    foreach ($old_instances as $old_instance) {
        if ($old_instance['prq_qst_question_id']) {
            // Update the existing row in-place
            $pri = new ProductRequirementInstance($old_instance['pri_product_requirement_instance_id'], true);
            $pri->set('pri_class_name', 'QuestionRequirement');
            $pri->set('pri_config', json_encode(['question_id' => intval($old_instance['prq_qst_question_id'])]));

            // Set order after any bitmask-migrated rows
            $existing_count_sql = "SELECT COUNT(*) as cnt FROM pri_product_requirement_instances
                                   WHERE pri_pro_product_id = ? AND pri_class_name IS NOT NULL AND pri_delete_time IS NULL";
            $cnt_q = $dblink->prepare($existing_count_sql);
            $cnt_q->execute([$old_instance['pri_pro_product_id']]);
            $cnt_result = $cnt_q->fetch(PDO::FETCH_ASSOC);
            $pri->set('pri_order', intval($cnt_result['cnt']));

            $pri->save();
            $converted_count++;
        }
    }

    error_log("Migration: Converted $converted_count old prq/pri instances to new format");
    error_log("Migration: Product requirements migration complete");

    return true;
}
