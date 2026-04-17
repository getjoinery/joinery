# Complete Email Migration Specification - Line by Line

## Overview

This document provides exact, line-by-line specifications for migrating every file from the legacy EmailTemplate system to the new EmailMessage/EmailSender architecture. Each change is documented with precise before/after code to ensure perfect implementation.

## Important Implementation Notes

### Key Clarifications to Prevent Confusion

**1. Template Variable Defaults Are Handled Internally**
The refactored EmailTemplate class automatically provides system defaults:
- ✅ `template_name` - derived from template filename
- ✅ `web_dir` - site base URL via `LibraryFunctions::get_absolute_url('')`  
- ✅ `email_vars` - UTM tracking parameters
- ✅ UTM defaults - `utm_source=email`, `utm_medium=email`, `utm_content=email`, `utm_campaign=""`

**Do NOT pass these as variables** - EmailTemplate provides them automatically. Only pass:
- Custom template variables (like `subject`, `body`, `act_code`)  
- User context via `'recipient' => $user->export_as_array()` when needed
- UTM overrides when specifically required

**2. Default From Address is Automatic**
EmailSender automatically uses `defaultemail` and `defaultemailname` settings if no custom from address is specified. Only use `->from()` when you need a custom sender different from defaults.

**3. Threading Protection is Database-Based**
The threading protection in `admin_emails_send.php` relies on database state management, not the sending mechanism. The check-send-update sequence using `EmailRecipient` records works identically with the new EmailSender.

**4. Admin Bulk Messaging Requires Individual Sends**
The `admin_users_message.php` system requires granular per-recipient success/failure tracking in the database. **Do NOT use batch sending** - use individual sends to preserve the exact recipient status tracking that the admin system depends on.

## File-by-File Complete Specifications

### 1. includes/Activation.php

#### Function: email_activate_send()

**Location:** Line ~82-95

**BEFORE:**
```php
//GENERATE SIGNUP CODE
$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));
$activation_email = EmailTemplate::CreateLegacyTemplate('activation_content', $user);
$settings = Globalvars::get_instance();
$activation_email->fill_template(array(
    'resend' => $resend,
    'act_code' => $act_code,
));
return $activation_email->send();
```

**AFTER:**
```php
//GENERATE SIGNUP CODE
$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));
$success = EmailSender::sendTemplate('activation_content',
    $user->get('usr_email'),
    [
        'resend' => $resend,
        'act_code' => $act_code,
        'recipient' => $user->export_as_array()
    ]
);
return $success;
```

**Additional includes needed at top of file:**
Add after line 9: `PathHelper::requireOnce('includes/EmailSender.php');`

#### Function: email_forgotpw_send()

**Location:** Line ~106-122

**BEFORE:**
```php
$act_code = self::getTempCode($user->key, '30 day', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));

$activation_email = EmailTemplate::CreateLegacyTemplate('forgotpw_content', $user);
$settings = Globalvars::get_instance();
$activation_email->fill_template(array(
    'act_code' => $act_code,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
));
$activation_email->email_from = $settings->get_setting('defaultemail');
$activation_email->email_from_name = $settings->get_setting('defaultemailname'); 
$activation_email->add_recipient($user->get('usr_email'));
$activation_email->send();
return TRUE;
```

**AFTER:**
```php
$act_code = self::getTempCode($user->key, '30 day', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));

$success = EmailSender::sendTemplate('forgotpw_content',
    $user->get('usr_email'),
    [
        'act_code' => $act_code,
        'web_dir' => LibraryFunctions::get_absolute_url(''),
        'recipient' => $user->export_as_array()
    ]
);
return $success;
```

#### Function: email_change_send()

**Location:** Line ~124-145

**BEFORE:**
```php
$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_CHANGE, NULL, $new_email);

$activation_email = EmailTemplate::CreateLegacyTemplate('email_change_content', $user);
$settings = Globalvars::get_instance();
$activation_email->fill_template(array(
    'act_code' => $act_code,
    'new_email' => $new_email,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
));
// Clear the addresses because we don't want to automatically send this to the user's
// current email (as would happen since we pass in the recipient user to the email template)
$activation_email->mailer->clearAllRecipients();
$activation_email->mailer->addAddress($new_email);
$activation_email->send();
```

**AFTER:**
```php
$act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_CHANGE, NULL, $new_email);

$message = EmailMessage::fromTemplate('email_change_content', [
    'act_code' => $act_code,
    'new_email' => $new_email,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
    'recipient' => $user->export_as_array()
]);
$message->to($new_email); // Send to new email, not user's current email

$sender = new EmailSender();
$sender->send($message);
```

**Additional includes needed:**
Add after line 9: `PathHelper::requireOnce('includes/EmailMessage.php');`

### 2. data/users_class.php

#### Location: Line ~454-458 (in save() method)

**BEFORE:**
```php
//SEND NEW USER WELCOME EMAIL
$welcome_email = EmailTemplate::CreateLegacyTemplate('new_account_content', $user);
$welcome_email->fill_template($email_fill);
$welcome_email->send();	

//SEND ACTIVATION EMAIL
Activation::email_activate_send($user);
```

**AFTER:**
```php
//SEND NEW USER WELCOME EMAIL
EmailSender::sendTemplate('new_account_content',
    $user->get('usr_email'),
    array_merge($email_fill, ['recipient' => $user->export_as_array()])
);

//SEND ACTIVATION EMAIL
Activation::email_activate_send($user);
```

**Additional includes needed:**
Add after existing includes: `PathHelper::requireOnce('includes/EmailSender.php');`

### 3. data/mailing_lists_class.php

#### Location: Line ~85-95 (in add_user_to_mailing_list method)

**BEFORE:**
```php
//SEND WELCOME EMAIL
$user = new User($usr_user_id, TRUE);
$welcome_email = EmailTemplate::CreateLegacyTemplate('mailing_list_subscribe', $user);


$welcome_email->fill_template(array(
    'mailing_list' => $this,
));
$welcome_email->send();
```

**AFTER:**
```php
//SEND WELCOME EMAIL
$user = new User($usr_user_id, TRUE);
EmailSender::sendTemplate('mailing_list_subscribe',
    $user->get('usr_email'),
    [
        'mailing_list' => $this,
        'recipient' => $user->export_as_array()
    ]
);
```

**Additional includes needed:**
Add after existing includes: `PathHelper::requireOnce('includes/EmailSender.php');`

### 4. data/order_items_class.php

#### Location: Line ~280-290 (in cancel_subscription method)

**BEFORE:**
```php
$body = 'Subscription '.$this->get('odi_stripe_subscription_id').' (Order '. $order->key .') was cancelled for user '.$order_user->display_name().' ('.$order_user->get('usr_email').')';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $notify_user);
$email->fill_template(array(
    'subject' => 'Cancelled Subscription',
    'body' => $body,
));
$email->send();
```

**AFTER:**
```php
$body = 'Subscription '.$this->get('odi_stripe_subscription_id').' (Order '. $order->key .') was cancelled for user '.$order_user->display_name().' ('.$order_user->get('usr_email').')';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
EmailSender::sendTemplate($email_inner_template,
    $notify_user->get('usr_email'),
    [
        'subject' => 'Cancelled Subscription',
        'body' => $body,
        'recipient' => $notify_user->export_as_array()
    ]
);
```

**Additional includes needed:**
Add after existing includes: `PathHelper::requireOnce('includes/EmailSender.php');`

### 5. data/recurring_mailer_class.php

#### Location: Line ~45 (in _load_templates method)

**BEFORE:**
```php
$this->email_templates['main_template'] = EmailTemplate::CreateLegacyTemplate('recurring_emails/main_template.html', null);
```

**AFTER:**
```php
$this->email_templates['main_template'] = EmailTemplate::CreateLegacyTemplate('recurring_emails/main_template.html', null);
```

**Note:** No change needed - this class only loads templates, doesn't send emails. The CreateLegacyTemplate usage is appropriate here.

### 6. logic/post_logic.php

#### Location: Line ~168-177 (notification sending)

**BEFORE:**
```php
$body .= '<p>Link: <a href="'. LibraryFunctions::get_absolute_url($post->get_url()).'">' . LibraryFunctions::get_absolute_url($post->get_url()).'</a>';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $notify_user);
$email->fill_template(array(
    'subject' => 'New Comment',
    'body' => $body,
));
$email->send();
```

**AFTER:**
```php
$body .= '<p>Link: <a href="'. LibraryFunctions::get_absolute_url($post->get_url()).'">' . LibraryFunctions::get_absolute_url($post->get_url()).'</a>';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
EmailSender::sendTemplate($email_inner_template,
    $notify_user->get('usr_email'),
    [
        'subject' => 'New Comment',
        'body' => $body,
        'recipient' => $notify_user->export_as_array()
    ]
);
```

**Additional includes needed:**
Add after existing includes: `PathHelper::requireOnce('includes/EmailSender.php');`

### 7. logic/cart_charge_logic.php

#### Location 1: Line ~198-208 (subscription start notification)

**BEFORE:**
```php
$body = 'Subscription '.$subscription_result['id'].' (Order '. $order->key .') was started by '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $notify_user);
$email->fill_template(array(
    'subject' => 'New Subscription',
    'body' => $body,
));
$email->send();
```

**AFTER:**
```php
$body = 'Subscription '.$subscription_result['id'].' (Order '. $order->key .') was started by '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
EmailSender::sendTemplate($email_inner_template,
    $notify_user->get('usr_email'),
    [
        'subject' => 'New Subscription',
        'body' => $body,
        'recipient' => $notify_user->export_as_array()
    ]
);
```

#### Location 2: Line ~263-273 (order charged notification)

**BEFORE:**
```php
$body = 'Order '. $order->key .' was charged - user: '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $notify_user);
$email->fill_template(array(
    'subject' => 'Order Charged',
    'body' => $body,
));
$email->send();
```

**AFTER:**
```php
$body = 'Order '. $order->key .' was charged - user: '.$billing_user->display_name().' '.$billing_user->get('usr_email').'.';
$email_inner_template = $settings->get_setting('individual_email_inner_template');
EmailSender::sendTemplate($email_inner_template,
    $notify_user->get('usr_email'),
    [
        'subject' => 'Order Charged',
        'body' => $body,
        'recipient' => $notify_user->export_as_array()
    ]
);
```

**Additional includes needed:**
Add after existing includes: `PathHelper::requireOnce('includes/EmailSender.php');`

### 8. adm/admin_users_message.php

This is the most complex file. Let me break it down by section:

#### Section 1: Event registrant messaging (Line ~149-180)

**BEFORE:**
```php
foreach ($event_registrants as $event_registrant){
    $email = EmailTemplate::CreateLegacyTemplate($email_inner_template, NULL, $email_outer_template, $email_footer_template);
    
    
    $email->fill_template(array(
        'subject' => $_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        //'utm_source' => 'email', //use defaults
        'utm_medium' => 'email', //use defaults
        //'utm_campaign' => $mailing_list_string, 
        'utm_content' => urlencode($_POST['eml_subject']), 	
    ));
    $email->email_subject = $_POST['eml_subject'];
    $email->email_from = $_POST['eml_from'];
    $email->email_from_name = $_POST['eml_from_name'];
    $email->add_recipient($event_registrant->get('usr_email'), $event_registrant->get('usr_name'));
    
    $recipient_email = new EmailRecipient(NULL);
    $recipient_email->set('erc_eml_email_id', $email_record->key);
    $recipient_email->set('erc_usr_user_id', $event_registrant->key);
    $recipient_email->set('erc_email_address', $event_registrant->get('usr_email'));
    $recipient_email->set('erc_name', $event_registrant->get('usr_name'));
    $recipient_email->set('erc_status', 1);
    $recipient_email->save();							
    $numrecipients++;
    $result = $email->send();
}
```

**AFTER:**
```php
// CRITICAL: This section requires INDIVIDUAL sends per recipient for granular error tracking
foreach ($event_registrants as $event_registrant){
    $message = EmailMessage::fromTemplate($email_inner_template, [
        'subject' => $_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        'utm_medium' => 'email',
        'utm_content' => urlencode($_POST['eml_subject']),
        'recipient' => $event_registrant->export_as_array()
    ]);
    
    $message->subject($_POST['eml_subject'])
            ->to($event_registrant->get('usr_email'), $event_registrant->get('usr_name'));
    
    // Only set custom from if different from defaults
    $settings = Globalvars::get_instance();
    if ($_POST['eml_from'] != $settings->get_setting('defaultemail')) {
        $message->from($_POST['eml_from'], $_POST['eml_from_name']);
    }
    
    $recipient_email = new EmailRecipient(NULL);
    $recipient_email->set('erc_eml_email_id', $email_record->key);
    $recipient_email->set('erc_usr_user_id', $event_registrant->key);
    $recipient_email->set('erc_email_address', $event_registrant->get('usr_email'));
    $recipient_email->set('erc_name', $event_registrant->get('usr_name'));
    $recipient_email->set('erc_status', 1);
    $recipient_email->save();							
    $numrecipients++;
    
    $sender = new EmailSender();
    $result = $sender->send($message);
    // Note: Individual success/failure tracking preserved per recipient
}
```

#### Section 2: Event leader messaging (Line ~182-200)

**BEFORE:**
```php
if($event->get('evt_usr_user_id_leader')){
    $leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
    $email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $leader, $email_outer_template, $email_footer_template);
    $email->fill_template(array(
        'subject' => 'COPY: '.$_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        //'utm_source' => 'email', //use defaults
        'utm_medium' => 'email', //use defaults
        //'utm_campaign' => $mailing_list_string, 
        'utm_content' => urlencode($_POST['eml_subject']), 	
    ));
    $result = $email->send();
}
```

**AFTER:**
```php
if($event->get('evt_usr_user_id_leader')){
    $leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
    $message = EmailMessage::fromTemplate($email_inner_template, [
        'subject' => 'COPY: '.$_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        'utm_medium' => 'email',
        'utm_content' => urlencode($_POST['eml_subject']),
        'recipient' => $leader->export_as_array()
    ]);
    
    $message->to($leader->get('usr_email'), $leader->get('usr_name'));
    
    $sender = new EmailSender();
    $result = $sender->send($message);
}
```

#### Section 3: General member messaging (Line ~210-235)

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, NULL, $email_outer_template, $email_footer_template);			
$email->fill_template(array(
    'subject' => $_POST['eml_subject'],
    'body' => $_POST['eml_message_html'],
    'preview_text' => $_POST['eml_preview_text'],
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    'utm_campaign' => $mailing_list_string, 
    'utm_content' => urlencode($_POST['eml_subject']), 	
));
$email->email_subject = $_POST['eml_subject'];
$email->email_from = $_POST['eml_from'];
$email->email_from_name = $_POST['eml_from_name'];

foreach($user_members as $user_member) {
    $email->add_recipient($user_member->get('usr_email'), $user_member->get('usr_name'));
    $recipient_email = new EmailRecipient(NULL);
    $recipient_email->set('erc_eml_email_id', $email_record->key);
    $recipient_email->set('erc_usr_user_id', $user_member->key);
    $recipient_email->set('erc_email_address', $user_member->get('usr_email'));
    $recipient_email->set('erc_name', $user_member->get('usr_name'));
    $recipient_email->set('erc_status', 1);
    $recipient_email->save();							
    $numrecipients++;				
}
$result = $email->send();
```

**AFTER:**
```php
// CRITICAL: This section handles both individual recipients and group messaging
// The original system uses single email to multiple recipients - preserve this pattern

$message = EmailMessage::fromTemplate($email_inner_template, [
    'subject' => $_POST['eml_subject'],
    'body' => $_POST['eml_message_html'],
    'preview_text' => $_POST['eml_preview_text'],
    'utm_medium' => 'email',
    'utm_campaign' => $mailing_list_string,
    'utm_content' => urlencode($_POST['eml_subject'])
]);

$message->subject($_POST['eml_subject']);

// Only set custom from if different from defaults
$settings = Globalvars::get_instance();
if ($_POST['eml_from'] != $settings->get_setting('defaultemail')) {
    $message->from($_POST['eml_from'], $_POST['eml_from_name']);
}

// Add all recipients to single message (preserves original behavior)
foreach($user_members as $user_member) {
    $message->to($user_member->get('usr_email'), $user_member->get('usr_name'));
    
    $recipient_email = new EmailRecipient(NULL);
    $recipient_email->set('erc_eml_email_id', $email_record->key);
    $recipient_email->set('erc_usr_user_id', $user_member->key);
    $recipient_email->set('erc_email_address', $user_member->get('usr_email'));
    $recipient_email->set('erc_name', $user_member->get('usr_name'));
    $recipient_email->set('erc_status', 1);
    $recipient_email->save();							
    $numrecipients++;				
}

$sender = new EmailSender();
$result = $sender->send($message); // Single send to all recipients (like original)
```

#### Section 4: Individual recipient messaging (Line ~260-285)

**BEFORE:**
```php
$settings = Globalvars::get_instance();
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $recipient);
$email->fill_template(array(
    'subject' => $_POST['eml_subject'],
    'body' => $_POST['eml_message_html'],
    'preview_text' => $_POST['eml_preview_text'],
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    //'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
    'utm_content' => urlencode($_POST['eml_subject']), 	
));
$result = $email->send();
```

**AFTER:**
```php
$settings = Globalvars::get_instance();
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$result = EmailSender::sendTemplate($email_inner_template,
    $recipient->get('usr_email'),
    [
        'subject' => $_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        'utm_medium' => 'email',
        'utm_content' => urlencode($_POST['eml_subject']),
        'recipient' => $recipient->export_as_array()
    ]
);
```

#### Section 5: Sender copy (Line ~310-325)

**BEFORE:**
```php
$settings = Globalvars::get_instance();
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $sender);
$email->fill_template(array(
    'subject' => 'COPY: '.$_POST['eml_subject'],
    'body' => $_POST['eml_message_html'],
    'preview_text' => $_POST['eml_preview_text'],
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    //'utm_campaign' => ContactType::ToReadable(User::TRANSACTIONAL), 
    'utm_content' => urlencode($_POST['eml_subject']), 	
));
$result = $email->send();
```

**AFTER:**
```php
$settings = Globalvars::get_instance();
$email_inner_template = $settings->get_setting('individual_email_inner_template');
$result = EmailSender::sendTemplate($email_inner_template,
    $sender->get('usr_email'),
    [
        'subject' => 'COPY: '.$_POST['eml_subject'],
        'body' => $_POST['eml_message_html'],
        'preview_text' => $_POST['eml_preview_text'],
        'utm_medium' => 'email',
        'utm_content' => urlencode($_POST['eml_subject']),
        'recipient' => $sender->export_as_array()
    ]
);
```

**Additional includes needed for admin_users_message.php:**
Add after existing includes: 
```php
PathHelper::requireOnce('includes/EmailMessage.php');
PathHelper::requireOnce('includes/EmailSender.php');
```

### 9. adm/admin_emails_send.php

#### Section 1: Main email sending loop (Line ~88-115)

**BEFORE:**
```php
//TODO NEED TO INTEGRATE THE MAILGUN CLASS WITH THE EMAIL CLASS
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $user);	
$email_template->fill_template(array(
    'subject' => $email->get('eml_subject'),
    'preview_text' => $email->get('eml_preview_text'),
    'body' => $email->get('eml_message_html'),
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    'utm_campaign' => $mailing_list_string, 
    'utm_content' => urlencode($email->get('eml_subject')), 
    'mailing_list_id' => $mailing_list_id,
    'mailing_list_string' => $mailing_list_string,
));
$email_template->email_subject = $email->get('eml_subject');
$email_template->email_from = $email->get('eml_from');
$email_template->email_from_name = $email->get('eml_from_name');

//MAKE SURE WE DON'T SEND IF ANOTHER THREAD HAS ALREADY DONE IT
$recipient_check = new EmailRecipient($recipient->key, TRUE);
if(!$recipient_check->is_sent()){
    $result = $email_template->send(TRUE);
    if($result){
        $recipient->set('erc_sent_time', 'now()');
        $recipient->set('erc_status', EmailRecipient::EMAIL_SENT);
        $recipient->save();	
        echo 'Sent to : '. $user->display_name().'<br>';
    }
    else{
        $recipient->set('erc_status', EmailRecipient::ERROR);
        $recipient->save();	
        echo '<b>Failed to send to : '. $user->display_name().'</b><br>';
    }
}
else{
    echo 'Already sent to : '. $user->display_name().'<br>';
}
$count++;
if($count >= $max_email_sending_number) break;
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), [
    'subject' => $email->get('eml_subject'),
    'preview_text' => $email->get('eml_preview_text'),
    'body' => $email->get('eml_message_html'),
    'utm_medium' => 'email',
    'utm_campaign' => $mailing_list_string,
    'utm_content' => urlencode($email->get('eml_subject')),
    'mailing_list_id' => $mailing_list_id,
    'mailing_list_string' => $mailing_list_string,
    'recipient' => $user->export_as_array()
]);

$message->subject($email->get('eml_subject'))
        ->to($user->get('usr_email'), $user->display_name());

// Only set custom from if different from defaults
$settings = Globalvars::get_instance();
if ($email->get('eml_from') != $settings->get_setting('defaultemail')) {
    $message->from($email->get('eml_from'), $email->get('eml_from_name'));
}

//MAKE SURE WE DON'T SEND IF ANOTHER THREAD HAS ALREADY DONE IT
$recipient_check = new EmailRecipient($recipient->key, TRUE);
if(!$recipient_check->is_sent()){
    $sender = new EmailSender();
    $result = $sender->send($message);
    if($result){
        $recipient->set('erc_sent_time', 'now()');
        $recipient->set('erc_status', EmailRecipient::EMAIL_SENT);
        $recipient->save();	
        echo 'Sent to : '. $user->display_name().'<br>';
    }
    else{
        $recipient->set('erc_status', EmailRecipient::ERROR);
        $recipient->save();	
        echo '<b>Failed to send to : '. $user->display_name().'</b><br>';
    }
}
else{
    echo 'Already sent to : '. $user->display_name().'<br>';
}
$count++;
if($count >= $max_email_sending_number) break;
```

#### Section 2: Test email sending (Line ~130-150)

**BEFORE:**
```php
$email_template = EmailTemplate::CreateLegacyTemplate($test_email->get('eml_message_template_html'), $sender);	
$email_template->fill_template(array(
    'subject' => $test_email->get('eml_subject'),
    'preview_text' => $test_email->get('eml_preview_text'),
    'body' => $test_email->get('eml_message_html'),
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    //'utm_campaign' => $mailing_list_string, 
    'utm_content' => urlencode($test_email->get('eml_subject')), 
));
$email_template->email_subject = $test_email->get('eml_subject');
$email_template->email_from = $test_email->get('eml_from');
$email_template->email_from_name = $test_email->get('eml_from_name');

if(!$session->send_emails()){
    echo '<p><b>Email sending is disabled, so the email is available <a href="/ajax/email_preview_ajax?eml_email_id='.$test_email->key.'">on the preview page</a></b></p>';
}
else{
    echo '<p><b>Sending test email to '.$sender->display_name().'</b></p>';
    $result = $email_template->send(TRUE);
    if($result){
        echo '<p><b>Send succeeded.</b></p>';
    }
    else{
         echo '<p><b>Send failed.</b></p>';
    }
}
```

**AFTER:**
```php
if(!$session->send_emails()){
    echo '<p><b>Email sending is disabled, so the email is available <a href="/ajax/email_preview_ajax?eml_email_id='.$test_email->key.'">on the preview page</a></b></p>';
}
else{
    echo '<p><b>Sending test email to '.$sender->display_name().'</b></p>';
    
    $message = EmailMessage::fromTemplate($test_email->get('eml_message_template_html'), [
        'subject' => $test_email->get('eml_subject'),
        'preview_text' => $test_email->get('eml_preview_text'),
        'body' => $test_email->get('eml_message_html'),
        'utm_medium' => 'email',
        'utm_content' => urlencode($test_email->get('eml_subject')),
        'recipient' => $sender->export_as_array()
    ]);

    $message->subject($test_email->get('eml_subject'))
            ->to($sender->get('usr_email'), $sender->display_name());

    // Only set custom from if different from defaults
    $settings = Globalvars::get_instance();
    if ($test_email->get('eml_from') != $settings->get_setting('defaultemail')) {
        $message->from($test_email->get('eml_from'), $test_email->get('eml_from_name'));
    }

    $emailSender = new EmailSender();
    $result = $emailSender->send($message);
    if($result){
        echo '<p><b>Send succeeded.</b></p>';
    }
    else{
         echo '<p><b>Send failed.</b></p>';
    }
}
```

**Additional includes needed for admin_emails_send.php:**
Add after existing includes:
```php
PathHelper::requireOnce('includes/EmailMessage.php');
PathHelper::requireOnce('includes/EmailSender.php');
```

### 10. ajax/email_preview_ajax.php

#### Location: Line ~36-54

**BEFORE:**
```php
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $recipient);	
$email_template->fill_template(array(
    'subject' => 'COPY: '.$email->get('eml_subject'),
    'preview_text' => $email->get('eml_preview_text'),
    'body' => $email->get('eml_message_html'),
    //'utm_source' => 'email', //use defaults
    'utm_medium' => 'email', //use defaults
    'utm_campaign' => $mailing_list_string, 
    'utm_content' => urlencode($email->get('eml_subject')), 
    'mailing_list_id' => $mailing_list_id,
    'mailing_list_string' => $mailing_list_string,
));
$email_template->email_subject = $email->get('eml_subject');
$email_template->email_from = $email->get('eml_from_address');
$email_template->email_from_name = $email->get('eml_from_name');


echo $email_template->email_html;
```

**AFTER:**
```php
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $recipient);	
$email_template->fill_template(array(
    'subject' => 'COPY: '.$email->get('eml_subject'),
    'preview_text' => $email->get('eml_preview_text'),
    'body' => $email->get('eml_message_html'),
    'utm_medium' => 'email',
    'utm_campaign' => $mailing_list_string, 
    'utm_content' => urlencode($email->get('eml_subject')), 
    'mailing_list_id' => $mailing_list_id,
    'mailing_list_string' => $mailing_list_string,
));

echo $email_template->getHtml();
```

**Note:** No additional includes needed - this file only does template processing, no sending.

### 11. utils/scratch.php

#### Location: Line ~35-45

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate('blank_template', null);
$email->add_recipient('jeremy.tunnell+3@gmail.com', 'Jeremy 3');
$email->add_recipient('jeremy@jeremytunnell.com', 'Jeremy');
$email->fill_template(array(
    'subject' => 'scratch.php test',
    'body' => 'This is a test from scratch.php '.date('Y-m-d H:i:s'),
));
$result = $email->send();
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate('blank_template', [
    'subject' => 'scratch.php test',
    'body' => 'This is a test from scratch.php '.date('Y-m-d H:i:s'),
]);

$message->to('jeremy.tunnell+3@gmail.com', 'Jeremy 3')
        ->to('jeremy@jeremytunnell.com', 'Jeremy');

$sender = new EmailSender();
$result = $sender->send($message);
```

**Additional includes needed:**
Add after existing includes:
```php
PathHelper::requireOnce('includes/EmailMessage.php');
PathHelper::requireOnce('includes/EmailSender.php');
```

### 12. utils/email_send_test.php

#### Location: Line ~35-50

**BEFORE:**
```php
// Try to use EmailTemplate system
$emailTemplate = EmailTemplate::CreateLegacyTemplate('default_outer_template', null);
$emailTemplate->clear_recipients();
$emailTemplate->add_recipient($config['test_email'], 'Test Recipient');
$emailTemplate->fill_template([
    'subject' => 'Email Service Test - ' . date('Y-m-d H:i:s'),
    'body' => '<p>This is a test email sent at ' . date('Y-m-d H:i:s') . '</p>',
]);

if ($emailTemplate->send()) {
    echo "✅ EmailTemplate send succeeded\n";
} else {
    echo "❌ EmailTemplate send failed\n";
}
```

**AFTER:**
```php
// Try to use new EmailSender system
try {
    $success = EmailSender::quickSend(
        $config['test_email'],
        'Email Service Test - ' . date('Y-m-d H:i:s'),
        '<p>This is a test email sent at ' . date('Y-m-d H:i:s') . '</p>'
    );
    
    if ($success) {
        echo "✅ EmailSender send succeeded\n";
    } else {
        echo "❌ EmailSender send failed (queued for retry)\n";
    }
} catch (Exception $e) {
    echo "❌ EmailSender error: " . $e->getMessage() . "\n";
}
```

**Additional includes needed:**
Add after existing includes:
```php
PathHelper::requireOnce('includes/EmailSender.php');
```

### 13. tests/integration/mailgun_test.php

#### Location: Line ~10-25

**BEFORE:**
```php
$user = new User(1, true);

$email_template = EmailTemplate::CreateLegacyTemplate('blank_template', $user);		
$email_template->fill_template(array(
        'subject' => 'Test email with emailTemplate',
        'body' => 'This is the body of the test email.',
    ));
$email_template->add_recipient('jeremy.tunnell+3@gmail.com');

$result = $email_template->send();

if ($result) {
    echo "Email sent successfully\n";
} else {
    echo "Email sending failed\n";
}
```

**AFTER:**
```php
try {
    $user = new User(1, true);
    
    $message = EmailMessage::fromTemplate('blank_template', [
        'subject' => 'Test email with new system',
        'body' => 'This is the body of the test email.',
        'recipient' => $user->export_as_array()
    ]);
    
    $message->to('jeremy.tunnell+3@gmail.com', 'Test User');
    
    $sender = new EmailSender();
    $result = $sender->send($message);
    
    if ($result) {
        echo "Email sent successfully\n";
    } else {
        echo "Email sending failed (queued for retry)\n";
    }
} catch (Exception $e) {
    echo "Email error: " . $e->getMessage() . "\n";
}
```

**Additional includes needed:**
Add after existing includes:
```php
PathHelper::requireOnce('includes/EmailMessage.php');
PathHelper::requireOnce('includes/EmailSender.php');
```

## EmailTemplate Class Cleanup

### Remove Deprecated Methods

**File:** `includes/EmailTemplate.php`

**Remove these methods completely:**

```php
// DELETE THESE METHODS:
public function add_recipient($recipient_email, $recipient_name = null) { ... }
public function clear_recipients() { ... }
public function send($check_session = true, $other_host = null) { ... }
```

**Remove these properties:**

```php
// DELETE THESE PROPERTIES:
public $email_from;
public $email_from_name;
```

**Make these properties private:**

```php
// CHANGE FROM PUBLIC TO PRIVATE:
private $email_subject;  // was public
private $email_html;     // was public  
private $email_text;     // was public
```

**Remove CreateLegacyTemplate method (after all migrations complete):**

```php
// DELETE THIS METHOD AFTER ALL FILES ARE MIGRATED:
public static function CreateLegacyTemplate($inner_template, $recipient_user = null, $outer_template = null, $footer = null) { ... }
```

## Testing Verification Checklist

For each migrated file, verify:

### Functional Tests
- [ ] Email sending works with same recipients
- [ ] Template variables process correctly  
- [ ] Subject lines appear correctly
- [ ] From addresses work (default and custom)
- [ ] HTML and text versions generate properly
- [ ] Error handling works appropriately

### Integration Tests
- [ ] Database records update correctly (for admin files)
- [ ] Recipients tracking works (for campaign files)
- [ ] Thread safety maintained (for admin_emails_send.php)
- [ ] Preview system works (for email_preview_ajax.php)
- [ ] Batch sending performs well (for bulk operations)

### Error Condition Tests
- [ ] Template missing/malformed
- [ ] Service configuration errors
- [ ] Network failures (service fallback)
- [ ] Invalid email addresses
- [ ] Missing required template variables

### Performance Tests
- [ ] Batch sending performance maintained
- [ ] Memory usage reasonable for large recipient lists
- [ ] No significant slowdown in email processing

## Critical Implementation Notes

1. **Order of Operations**: Migrate all files simultaneously to avoid mixed states
2. **Error Handling**: New system provides more detailed error information
3. **Backwards Compatibility**: All existing templates and variables must continue working
4. **Performance**: Batch operations should maintain or improve performance
5. **Testing**: Each migration must be tested individually before proceeding
6. **Rollback Plan**: Keep backups of all modified files for quick rollback if needed

This specification provides exact, line-by-line changes for every affected file in the email system migration.