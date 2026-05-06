<?php
/**
 * Add _logic_descriptor() functions to action-shaped logic files (Step 3 of
 * logic_code_refactor.md).
 *
 * _logic_api() stubs are preserved untouched — apiv1.php uses them as its opt-in
 * gate and will continue to until Step 7. _logic_descriptor() adds the typed input
 * schema the stubs currently lack.
 *
 * Five files that have _logic_api() are intentionally skipped here because they are
 * mixed page-handlers (load display data on GET AND execute an action on POST):
 *   cart_logic, booking_logic, survey_logic, event_sessions_logic,
 *   event_sessions_course_logic
 * These will be split and descriptored in Step 5.
 *
 * Run modes:
 *   --dry-run   (default) Print what would be appended; write nothing.
 *   --apply     Write descriptor functions to each file.
 *   --verify    Confirm backward-compat: every target file still exposes _logic_api().
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

// ---------------------------------------------------------------------------
// Descriptor data — review this before running --apply.
//
// 'input' keys are the canonical POST/GET parameter names the logic function
// reads. Required=true means the function will fail or return an error without
// the field. Types: int, string, email, password, bool, select, text, date.
// ---------------------------------------------------------------------------
$descriptors = [

    'register' => [
        'description'      => 'Create a new user account.',
        'requires_session' => false,
        'mutates'          => true,
        'input'            => [
            'usr_email'      => ['type' => 'email',    'required' => true,  'label' => 'Email address'],
            'usr_first_name' => ['type' => 'string',   'required' => true,  'label' => 'First name'],
            'usr_last_name'  => ['type' => 'string',   'required' => true,  'label' => 'Last name'],
            'password'       => ['type' => 'password', 'required' => true,  'label' => 'Password'],
            'setcookie'      => ['type' => 'bool',     'required' => false, 'label' => 'Remember me'],
        ],
    ],

    'password_reset_1' => [
        'description'      => 'Send a password reset email to the given address.',
        'requires_session' => false,
        'mutates'          => true,
        'input'            => [
            'email' => ['type' => 'email', 'required' => true, 'label' => 'Email address'],
        ],
    ],

    'password_reset_2' => [
        'description'      => 'Set a new password using a one-time reset code.',
        'requires_session' => false,
        'mutates'          => true,
        'input'            => [
            'act_code'          => ['type' => 'string',   'required' => true, 'label' => 'Reset code'],
            'usr_password'      => ['type' => 'password', 'required' => true, 'label' => 'New password'],
            'usr_password_again'=> ['type' => 'password', 'required' => true, 'label' => 'Confirm new password'],
        ],
    ],

    'password_set' => [
        'description'      => 'Set an initial password for an account that has none.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'usr_password'       => ['type' => 'password', 'required' => true, 'label' => 'Password'],
            'usr_password_again' => ['type' => 'password', 'required' => true, 'label' => 'Confirm password'],
        ],
    ],

    'password_edit' => [
        'description'      => 'Change the current user\'s password.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'usr_old_password'   => ['type' => 'password', 'required' => false, 'label' => 'Current password'],
            'usr_password'       => ['type' => 'password', 'required' => true,  'label' => 'New password'],
            'usr_password_again' => ['type' => 'password', 'required' => true,  'label' => 'Confirm new password'],
        ],
    ],

    'change_password_required' => [
        'description'      => 'Satisfy a forced password-change requirement for admin users.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'new_password'     => ['type' => 'password', 'required' => true, 'label' => 'New password'],
            'confirm_password' => ['type' => 'password', 'required' => true, 'label' => 'Confirm password'],
        ],
    ],

    'verify_totp' => [
        'description'      => 'Verify a TOTP or backup code to complete two-factor login.',
        'requires_session' => false,
        'mutates'          => true,
        'input'            => [
            'totp_code' => ['type' => 'string', 'required' => true, 'label' => 'Authenticator code or backup code'],
        ],
    ],

    'security' => [
        'description'      => 'Manage two-factor authentication settings. '
                            . 'action=start_enable begins TOTP setup; confirm_enable confirms it; '
                            . 'cancel_enable aborts pending setup; regenerate_backup_codes issues new codes; '
                            . 'disable turns off TOTP.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'action'       => ['type' => 'select',  'required' => true,  'label' => 'Action',
                               'options' => ['start_enable', 'confirm_enable', 'cancel_enable', 'regenerate_backup_codes', 'disable']],
            'totp_code'    => ['type' => 'string',  'required' => false, 'label' => 'TOTP code (confirm_enable and disable)'],
            'confirm_code' => ['type' => 'string',  'required' => false, 'label' => 'Backup code (disable)'],
        ],
    ],

    'account_edit' => [
        'description'      => 'Update the current user\'s profile fields.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'usr_first_name' => ['type' => 'string', 'required' => true,  'label' => 'First name'],
            'usr_last_name'  => ['type' => 'string', 'required' => true,  'label' => 'Last name'],
            'usr_timezone'   => ['type' => 'string', 'required' => true,  'label' => 'Timezone'],
            'usr_email_new'  => ['type' => 'email',  'required' => false, 'label' => 'New email address'],
        ],
    ],

    'address_edit' => [
        'description'      => 'Create or update the current user\'s address.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'edit_primary_key_value' => ['type' => 'int',    'required' => false, 'label' => 'Address ID (omit to create)'],
            'usa_cco_country_code_id'=> ['type' => 'string', 'required' => false, 'label' => 'Country'],
            'usa_address1'           => ['type' => 'string', 'required' => false, 'label' => 'Address line 1'],
            'usa_address2'           => ['type' => 'string', 'required' => false, 'label' => 'Address line 2'],
            'usa_city'               => ['type' => 'string', 'required' => false, 'label' => 'City'],
            'usa_state'              => ['type' => 'string', 'required' => false, 'label' => 'State / province'],
            'usa_zip_code_id'        => ['type' => 'string', 'required' => false, 'label' => 'Postal code'],
        ],
    ],

    'phone_numbers_edit' => [
        'description'      => 'Create or update the current user\'s phone number.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'edit_primary_key_value'  => ['type' => 'int',    'required' => false, 'label' => 'Phone number ID (omit to create)'],
            'phn_cco_country_code_id' => ['type' => 'string', 'required' => false, 'label' => 'Country code'],
            'phn_phone_number'        => ['type' => 'string', 'required' => true,  'label' => 'Phone number'],
        ],
    ],

    'contact_preferences' => [
        'description'      => 'Update the user\'s mailing list subscriptions.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'new_list_subscribes' => ['type' => 'string', 'required' => false, 'label' => 'Mailing list IDs to subscribe to (array)'],
        ],
    ],

    'event_register' => [
        'description'      => 'Register the current user for an event.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'evt_event_id'  => ['type' => 'int',  'required' => true,  'label' => 'Event ID'],
            'instance_date' => ['type' => 'date', 'required' => false, 'label' => 'Instance date (recurring events)'],
        ],
    ],

    'event_withdraw' => [
        'description'      => 'Withdraw the current user from an event registration.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'evr_event_registrant_id' => ['type' => 'int',  'required' => true, 'label' => 'Event registrant ID'],
            'confirm'                 => ['type' => 'bool', 'required' => true, 'label' => 'Confirmation flag'],
        ],
    ],

    'event_waiting_list' => [
        'description'      => 'Add the current user (or a guest) to an event\'s waiting list.',
        'requires_session' => false,
        'mutates'          => true,
        'input'            => [
            'event_id'      => ['type' => 'int',    'required' => true,  'label' => 'Event ID'],
            'usr_first_name'=> ['type' => 'string', 'required' => false, 'label' => 'First name (guests)'],
            'usr_last_name' => ['type' => 'string', 'required' => false, 'label' => 'Last name (guests)'],
            'usr_email'     => ['type' => 'email',  'required' => false, 'label' => 'Email (guests)'],
            'newsletter'    => ['type' => 'bool',   'required' => false, 'label' => 'Subscribe to newsletter'],
        ],
    ],

    'cart_clear' => [
        'description'      => 'Clear all items from the current user\'s cart.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [],
    ],

    'change_tier' => [
        'description'      => 'Change the current user\'s subscription tier. '
                            . 'action=upgrade or downgrade requires product_id; '
                            . 'cancel and reactivate do not.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'action'     => ['type' => 'select', 'required' => true,  'label' => 'Action',
                             'options' => ['upgrade', 'downgrade', 'cancel', 'reactivate']],
            'product_id' => ['type' => 'int',    'required' => false, 'label' => 'Product ID (upgrade / downgrade)'],
        ],
    ],

    'orders_recurring_action' => [
        'description'      => 'Execute a recurring-order action (cancel, reactivate, etc.) for an order item.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'order_item_id' => ['type' => 'int', 'required' => true, 'label' => 'Order item ID'],
        ],
    ],

];

// ---------------------------------------------------------------------------
// Code generation
// ---------------------------------------------------------------------------

function render_descriptor_function(string $action_name, array $data): string {
    $fn   = $action_name . '_logic_descriptor';
    $desc = addslashes($data['description']);
    $rs   = $data['requires_session'] ? 'true' : 'false';
    $mut  = $data['mutates'] ? 'true' : 'false';

    $lines   = [];
    $lines[] = 'function ' . $fn . '(): array {';
    $lines[] = "\treturn [";
    $lines[] = "\t\t'description'      => '" . $desc . "',";
    $lines[] = "\t\t'requires_session' => " . $rs . ",";
    $lines[] = "\t\t'mutates'          => " . $mut . ",";
    $lines[] = "\t\t'input'            => [";

    foreach ($data['input'] as $field => $spec) {
        $type  = $spec['type'];
        $req   = $spec['required'] ? 'true' : 'false';
        $label = addslashes($spec['label']);
        if (isset($spec['options'])) {
            $opts = "'" . implode("', '", $spec['options']) . "'";
            $lines[] = "\t\t\t'" . $field . "' => ['type' => '" . $type . "', 'required' => " . $req . ", 'label' => '" . $label . "', 'options' => [" . $opts . "]],";
        } else {
            $lines[] = "\t\t\t'" . $field . "' => ['type' => '" . $type . "', 'required' => " . $req . ", 'label' => '" . $label . "'],";
        }
    }

    $lines[] = "\t\t],";
    $lines[] = "\t];";
    $lines[] = '}';

    return implode("\n", $lines);
}

function inject_descriptor(string $filepath, string $action_name, array $data, bool $dry_run): string {
    $contents = file_get_contents($filepath);

    $descriptor_fn = $action_name . '_logic_descriptor';
    if (strpos($contents, 'function ' . $descriptor_fn) !== false) {
        return 'SKIP   ' . $filepath . ' (descriptor already present)';
    }

    $code = "\n" . render_descriptor_function($action_name, $data) . "\n";

    $close_tag = '?' . '>';
    if (preg_match('/\?>\s*$/', $contents)) {
        $new_contents = preg_replace('/\?>\s*$/', $code . $close_tag . "\n", $contents);
    } else {
        $new_contents = rtrim($contents) . "\n" . $code;
    }

    if ($dry_run) {
        echo '--- would append to ' . $filepath . " ---\n";
        echo $code . "\n";
        return 'DRY    ' . $filepath;
    }

    file_put_contents($filepath, $new_contents);
    return 'WROTE  ' . $filepath;
}

// ---------------------------------------------------------------------------
// Verification: confirm _logic_api() still present (backward compat with apiv1.php)
// ---------------------------------------------------------------------------

function verify_backward_compat(array $descriptors): void {
    $logic_dir = PathHelper::getIncludePath('logic');
    $ok = 0; $fail = 0;
    foreach (array_keys($descriptors) as $action_name) {
        $filepath = $logic_dir . '/' . $action_name . '_logic.php';
        if (!file_exists($filepath)) {
            echo "MISSING  {$filepath}\n";
            $fail++;
            continue;
        }
        $contents = file_get_contents($filepath);
        $api_fn   = $action_name . '_logic_api';
        $desc_fn  = $action_name . '_logic_descriptor';
        $has_api  = strpos($contents, "function {$api_fn}") !== false;
        $has_desc = strpos($contents, "function {$desc_fn}") !== false;
        $status   = ($has_api ? '_logic_api ✓' : '_logic_api MISSING') . '  '
                  . ($has_desc ? '_logic_descriptor ✓' : '_logic_descriptor missing');
        echo ($has_api ? 'OK' : 'FAIL') . "  {$action_name}: {$status}\n";
        $has_api ? $ok++ : $fail++;
    }
    echo "\n{$ok} OK, {$fail} failures.\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args     = array_slice($argv, 1);
$mode     = in_array('--apply', $args) ? 'apply' : (in_array('--verify', $args) ? 'verify' : 'dry-run');
$logic_dir = PathHelper::getIncludePath('logic');

if ($mode === 'verify') {
    echo "=== Backward-compat verification ===\n\n";
    verify_backward_compat($descriptors);
    exit(0);
}

echo "=== add_logic_descriptors.php — mode: {$mode} ===\n\n";

foreach ($descriptors as $action_name => $data) {
    $filepath = $logic_dir . '/' . $action_name . '_logic.php';
    if (!file_exists($filepath)) {
        echo "MISSING  {$filepath}\n";
        continue;
    }
    $result = inject_descriptor($filepath, $action_name, $data, $mode === 'dry-run');
    echo $result . "\n";
}

echo "\nDone. Run with --verify to confirm backward compat.\n";
if ($mode === 'dry-run') {
    echo "Run with --apply to write the changes.\n";
}
