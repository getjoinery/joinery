<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/smtpmailer.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	
$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');	
require $composer_dir.'autoload.php';
use Mailgun\Mailgun;

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/queued_email_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/debug_email_logs_class.php');

class EmailTemplateError extends Exception {}

class EmailTemplate {

	const HOST_226 = '';
	const DEFAULT_HOST = '';
	


	public $mailer = NULL;
	protected $inner_template, $outer_template, $inner_html;
	protected $admin_notify_address = '';
	protected $utm_source =	'';
	//protected $testemails = array("test@emailreach.com","dkdelivery@yahoo.com","emailreach2@yahoo.com","emailreach@hotmail.com","emailreach2@hotmail.com","emailreach2@aol.com","emailreach@earthlink.net","emailreach@gmail.com","emailreach@netzero.net","emailreach@excite.com","delivery1@mail.com","delivery@operamail.com","emailreach@juno.com","emailreach1@peoplepc.com","emailreach2@comcast.net","emailreach@lycos.com","atest@att.net","emailreach@hushmail.com","emailview@yahoo.com","emailview@gmail.com","emailreach@gmx.com","emailview3@yahoo.com","emailview4@yahoo.com","emailview@earthlink.net","emailview1@earthlink.net","emailview1@aol.com","emailview2@aol.com","erdelivery@hotmail.com","erdelivery2@hotmail.com","emailreach@fastmail.fm","mailview@operamail.com","mailview1@operamail.com","emailreach@postinitest.com");
	protected $testemails = array("test@emailreach.com");
	public $email_from, $email_from_name, $email_recipients, $email_subject, $email_text, $email_html;


	function __construct($inner_template, $recipient_user=NULL, $outer_template=NULL, $footer=NULL) {
		$this->testing = FALSE;
		$settings = Globalvars::get_instance();
		$this->admin_notify_address = $settings->get_setting('defaultemail');

		//$template_dir = $settings->get_setting('siteDir') . '/theme/emailtemplates/';		
		//$this->inner_template = file_get_contents($template_dir . $inner_template);
		//$this->outer_template = file_get_contents($template_dir . $outer_template);
		//if ($footer) {
			//$this->footer = file_get_contents($template_dir . $footer);
		//}
		
		if(!$outer_template){
			//GET THE DEFAULT OUTER TEMPLATE
			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>'default_outer_template'));
			$templates->load();
			if($this_template = $templates->get(0)){
				$this->outer_template = $this_template->get('emt_body');
			}
			else{
				throw new EmailTemplateError('We could not find the default template.');
			}			
		}

		if(!$footer){
			//GET THE DEFAULT FOOTER
			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>'default_footer'));
			$templates->load();
			if($this_template = $templates->get(0)){
				$this->footer = $this_template->get('emt_body');
			}
			else{
				throw new EmailTemplateError('We could not find the default template.');
			}			
		}
		
		
		if(!$this->inner_template){
			//IF IT'S NOT A PHYSICAL FILE, LOOK IN THE DB
			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>$inner_template));
			$templates->load();
			if($this_template = $templates->get(0)){
				$this->inner_template = $this_template->get('emt_body');
			}
			else{
				throw new EmailTemplateError('We could not find the template '. $inner_template);
			}				
		}
		
		if(!$this->outer_template){
			//IF IT'S NOT A PHYSICAL FILE, LOOK IN THE DB
			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>$outer_template));
			$templates->load();
			if($this_template = $templates->get(0)){
				$this->outer_template = $this_template->get('emt_body');
			}
			else{
				throw new EmailTemplateError('We could not find the template '. $inner_template);
			}				
		}

		if(!$this->footer){
			//IF IT'S NOT A PHYSICAL FILE, LOOK IN THE DB
			$templates = new MultiEmailTemplateStore(
			array('email_template_name'=>$footer));
			$templates->load();
			if($this_template = $templates->get(0)){
				$this->footer = $this_template->get('emt_body');
			}
			else{
				$this->footer = '';
			}				
		}		
		

		// Recursively parse all the file insertions
		/*
		$safety_counter = 0;
		while ($this->inner_template !=
				$this->inner_template = $this->_parse_file_insertions($this->inner_template)) {
			$safety_counter++;
			if ($safety_counter > 5) {
				throw new EmailTemplateError('Over 5 file imports, stopped now for safety!');
			}
		}
		*/

		$this->orig_inner_template = $this->inner_template;

		// For a sample path like theme/emailtemplates/whatever/email_name.html
		// this pulls out the "email_name" part
		$tmp_template_name = preg_split('/[\/\.]/', $inner_template);

		if (count($tmp_template_name) <= 1) {
			$this->template_name = $inner_template;
		} else {
			$this->template_name = $tmp_template_name[count($tmp_template_name) - 2];
		}

		// Default utm_source
		$this->utm_source = 'bulk_email';

		$this->template_values = array(
			'template_name' => $this->template_name,
			'web_dir' => $settings->get_setting('webDir'),
			'email_vars' => $this->_generate_email_vars(),
			'email_type' => NULL,
		);

		$this->inner_html = NULL;
		$this->set_default_from();

		if ($recipient_user) {
			$this->template_values['recipient'] = $recipient_user->export_as_array();
			$this->add_recipient($recipient_user->get('usr_email'), $recipient_user->get('usr_first_name') . ' ' . $recipient_user->get('usr_last_name'));
		} else {
			$this->template_values['recipient'] = NULL;
		}

		// Only send emails which have content, so this will be enabled if the email
		// actually has some text
		$this->email_has_content = FALSE;
	}

	protected function _generate_email_vars() {
		return 'utm_source=' . $this->utm_source . '&amp;utm_medium=email&amp;utm_content=email' . $this->key .
			'&amp;utm_campaign=' . date('Ymd') . '&amp;ers=0';
	}

	protected function _generate_tracking_code($id) {
		$code = 'ers=' . LibraryFunctions::EncodeWithChecksum($id);
		$this->email_html = str_replace('ers=0', $code, str_replace('email_footer.png', 'email_footer.png?' . $code, $this->email_html));
	}
	
	protected function _add_tracking_to_links($email_body){
		
		//Create a new DOM document
		$dom = new DOMDocument;

		//Parse the HTML. The @ is used to suppress any parsing errors
		//that will be thrown if the $html string isn't valid XHTML.
		@$dom->loadHTML($email_body);

		//Get all links. You could also use any other tag name here,
		//like 'img' or 'table', to extract other tags.
		$links = $dom->getElementsByTagName('a');

		//Iterate over the extracted links and display their URLs
		foreach ($links as $link){
			//Extract and show the "href" attribute.
			//echo $link->getAttribute('href'), '<br>';
		}		
		
		/*
		preg_match_all('/(http[s]{0,1}\:\/\/\S{4,})\s{0,}/ims', $email_body, $matches);
		var_dump($matches);
		*/
		
		$tracking_text = $this->_generate_email_vars();
		foreach($links as $link){ 
			$start_text = $link->getAttribute('href');
			if(strpos($link->getAttribute('href'), '?')){
				$replace_text = 'href="'.$link->getAttribute('href').'&'.$tracking_text.'"';
			}
			else{
				$replace_text = 'href="'.$link->getAttribute('href').'?'.$tracking_text.'"';
			}

			$search_text = 'href="'.$start_text.'"';
			$email_body = str_replace($search_text, $replace_text, $email_body);
			//echo 'Replacing "'. $start_text . '" with "'.$replace_text.'" <br>';
			//$link->setAttribute('href', $replace_text);
			//$email_body = str_replace($link->getAttribute('href'), $replace_text, $email_body); 
		}
		
		return $email_body;
		
	}

	/*
	protected function _load_template_file($file) {
		if ($this->testing) {
			return $file;
		}

		$settings = Globalvars::get_instance();
		$template_dir = $settings->get_setting('siteDir') . '/theme/emailtemplates/';
		return file_get_contents($template_dir . $file);
	}

	protected function _parse_file_callback($matches) {
		return $this->_load_template_file($matches[1]);
	}

	protected function _parse_file_insertions($template_contents) {
		return preg_replace_callback(
			'/\<\<([a-zA-Z_\/\*\.]+?)\>\>/',
			array(&$this, '_parse_file_callback'),
			$template_contents
		);
	}
	*/

	function reset($user=NULL) {
		$this->email_has_content = FALSE;
		$this->inner_html = NULL;
		$this->inner_template = $this->orig_inner_template;

		if ($user !== NULL) {
			$this->template_values['recipient'] = $user->export_as_array();
			
			$this->add_recipient($recipient_user->get('usr_email'), $recipient_user->get('usr_first_name') . ' ' . $recipient_user->get('usr_last_name'));
		} else {
			unset($this->template_values['recipient']);
		}
	}

	function set_default_from() {
		$settings = Globalvars::get_instance();
		$this->email_from = $settings->get_setting('defaultemail');
		$this->email_from_name = $settings->get_setting('defaultemailname');		
	}
	
	function add_recipient($recipient_email, $recipient_name = NULL) {
		
		//CHECK FOR DUPLICATES
		foreach ($this->email_recipients as $recipient){
			if($recipient['email'] == $recipient_email){
				return(FALSE);
			}
		}
		
		$recipient = array();
		$recipient['name'] = $recipient_name;
		$recipient['email'] = $recipient_email;
		$this->email_recipients[] = $recipient;
		return(TRUE);
	}
	
	
	function clear_recipients() {
		$this->email_recipients = array();

		if($this->mailer){
			$this->mailer->ClearAddresses();
		}
	}

	private function _value_exists($value, $values) {
		$value_levels = explode('->', $value);

		$current_array_level = $values;

		foreach($value_levels as $array_key) {
			if (!is_array($current_array_level) || !array_key_exists($array_key, $current_array_level)) {
				return FALSE;
			}
			$current_array_level = $current_array_level[$array_key];
		}

		return TRUE;
	}

	private function _process_value($value, $values) {
		$value_levels = explode('->', $value);

		$current_array_level = $values;

		foreach($value_levels as $array_key) {
			if (!is_array($current_array_level) || !array_key_exists($array_key, $current_array_level)) {
				throw new EmailTemplateError(
					'Template value <font style="color:black;">' . implode($value_levels, '->') . '</font> is invalid or not set.  Trace:' . print_r(debug_backtrace(), TRUE));
			}
			$current_array_level = $current_array_level[$array_key];
		}

		return $current_array_level;
	}

	function _process_conditionals($values, $template_string) {
		$set_values = array();

		// First remove all comments
		$template_string = preg_replace('/\/\*\*.*?\*\*\//ms', '', $template_string);

		$split_template = preg_split(
			'/(?<!\\\){([^\}]+)}/', $template_string, NULL,
			PREG_SPLIT_DELIM_CAPTURE);

		$template_pairs = array();
		$parser_stack = array();
		$split_template_size = count($split_template);
		for($i=0;$i<$split_template_size;$i++) {
			if ($i % 2) {
				if (strpos($split_template[$i], 'end') === 0) {
					// It an end conditional, check the stack
					if (!$parser_stack) {
						throw new EmailTemplateError(
							'This template doesn\'t compile.  Please check the conditionals: Cannot find {end} at ' . $split_template[$i]);
					} else {
						// Otherwise its a pair, put it here
						$initial_spot = array_pop($parser_stack);
						$template_pairs[$initial_spot] = array($split_template[$initial_spot], $i);
					}
				} else {
					array_push($parser_stack, $i);
				}
			}
		}

		if ($parser_stack) {
			throw new EmailTemplateError(
				'This template doesn\'t compile.  There is an unmatched conditional somewhere.<br>');
				
		}

		$valid_values = array();
		$last_line = NULL;

		// First go through and handle all the conditionals
		$split_template_size = count($split_template);
		for($i=0;$i<$split_template_size;$i++) {
			if ($i % 2) {
				if (!array_key_exists($i, $template_pairs)) {
					// Here we know its an end point, skip it!
					continue;
				}

				// Pull out the conditional and test it to see if its true!
				list($conditional, $end_point) = $template_pairs[$i];

				$matches = array();

				if (preg_match('/^(~)?(\S+)$/', $conditional, $matches)) {
					list($unused, $negative, $item) = $matches;
					$item_value = $this->_process_value($item, $values);
					if (($negative && $item_value) || (!$negative && !$item_value)) {
						$i = $end_point;
						continue;
					}
				} else if (preg_match('/^(\S+?)([\+-][0-9]+)? (==|<>|!=|>|>=|<|<=|%%|&|includes) (.+)$/', $conditional, $matches)) {
					list($unused, $first_item,
						$first_item_qualifier, $operator, $second_item) = $matches;

					if ($this->_value_exists($first_item, $values)) {
						// The value could be processed from the array
						$first_value = $this->_process_value($first_item, $values);
					} else if (array_key_exists($first_item, $set_values)) {
						$first_value = $set_values[$first_item];
					} else {
						throw new EmailTemplateError(
							'The first element of the follow conditional doesn\'t exist: ' . $conditional);
					}
					if (is_numeric($first_value)) {
						$first_value = intval($first_value);
						if ($first_item_qualifier) {
							$first_value += intval($first_item_qualifier);
						}
					} else if (is_string($first_value)) {
						$first_value = '"' . $first_value . '"';
					}

					$temp_matches = array();
					if (is_numeric($second_item)) {
						$second_value = intval($second_item);
					} else if (strtolower($second_item) == 'true') {
						$second_value = TRUE;
					} else if (strtolower($second_item) == 'false') {
						$second_value = FALSE;
					} else if (preg_match('/^(".+")$/', $second_item, $temp_matches)) {
						$second_value = $temp_matches[1];
					} else if ($this->_value_exists($second_item, $values)) {
						$second_value = $this->_process_value($second_item, $values);
						if (is_numeric($second_value)) {
							$second_value = intval($second_value);
						} else if (is_string($second_value)) {
							$second_value = '"' . $second_value . '"';
						}
					} else {
						throw new EmailTemplateError(
							'Could not process the second element of this conditional: ' . $conditional);
					}

					if ($first_value === NULL) {
						throw new EmailTemplateError(
							'Could not process the first element of this conditional: ' . $conditional);
					}

					$statement_positive = FALSE;
					switch ($operator) {
						case '%%':
							$statement_positive = (($first_value % $second_value) == 0);
							break;
						case '==':
							$statement_positive = ($first_value == $second_value);
							break;
						case '<>':
						case '!=':
							$statement_positive = ($first_value != $second_value);
							break;
						case '<':
							$statement_positive = ($first_value < $second_value);
							break;
						case '<=':
							$statement_positive = ($first_value <= $second_value);
							break;
						case '>':
							$statement_positive = ($first_value > $second_value);
							break;
						case '>=':
							$statement_positive = ($first_value >= $second_value);
							break;
						case '&':
						case 'includes':
							$statement_positive = (($first_value & $second_value) != 0);
							break;
						default:
							throw new EmailTemplateError('Invalid Operator - Programming Error');
					}
					if ($statement_positive === FALSE) {
						$i = $end_point;
					}
				} else {
						throw new EmailTemplateError(
							'The follow conditional is malformed: ' . $conditional);
				}
			} else {
				// Its the in between of conditionals, so lets pull out all the [] counter
				// operations and process them
				$operations = preg_split(
					'/\[([^\]]+)\]/', $split_template[$i], NULL,
					PREG_SPLIT_DELIM_CAPTURE);

				$operations_size = count($operations);
				for($ii=0;$ii<$operations_size;$ii++) {
					if ($ii % 2) {
						$operation_matches = array();
						if (preg_match('/^([a-zA-Z_]+)(=|\+=|-=| setbit )([0-9]+)$/', $operations[$ii], $operation_matches)) {
							list($unused_full, $variable, $operation_type, $value) = $operation_matches;
							switch($operation_type) {
								case '=':
									$set_values[$variable] = intval($value);
									break;
								case '+=':
									$set_values[$variable] += intval($value);
									break;
								case '-=':
									$set_values[$variable] += intval($value);
									break;
								case ' setbit ':
									// Handle the "setbit" operation here, which just flips the bit on the variable
									// This is 1-based (not 0 based), so setbit 1 === 2^0, setbit 2 == 2^1, etc.
									if (!isset($set_values[$variable])) {
										$set_values[$variable] = 1 << (intval($value) - 1);
									} else {
										$set_values[$variable] |= 1 << (intval($value) - 1);
									}
									break;
							}
						} else if (preg_match('/^([a-zA-Z_]+)="(.+)"$/', $operations[$ii], $operation_matches)) {
							list($unused_full, $variable, $value) = $operation_matches;
							$set_values[$variable] = $value;
							// Handle special conditions here
							if ($variable == 'template_name') {
								$this->template_name = $value;
							} else if ($variable == 'utm_source') {
								$this->utm_source = $value;
							}

							// If any of these special conditions are used, update the email_vars
							if ($variable == 'template_name' || $variable == 'utm_source') {
								$values['email_vars'] = $this->_generate_email_vars();
							}
							// Done handling special conditions
						} else if (preg_match('/^([a-zA-Z_]+)$/', $operations[$ii], $operation_matches)) {
							$set_values[$operation_matches[1]] = TRUE;
							if ($operation_matches[1] == 'last') {
								// It is a last, which is a special operator.
								// Stop processing the template, its over!
								return array(implode('', $valid_values), $set_values);
							}
						} else {
							throw new EmailTemplateError('Invalid Operation, Programming Error - ' . $operations[$ii]);
						}
					} else {
						$valid_values[] = $operations[$ii];
					}
				}
			}
		}

		return array(implode('', $valid_values), $set_values);
	}
	
	
	

	function fill_template($values) {
		$settings = Globalvars::get_instance();
		
		if($values['utm_source']){
			//REDO THE TEMPLATE VALUES
			$this->utm_source = $values['utm_source'];
			$this->template_values['email_vars'] = $this->_generate_email_vars();
		}

		// Merge in the values from the constructor
		$values = array_merge($values, $this->template_values);
		$set_values = array();

		list($email_body, $set_values) = $this->_process_conditionals($values, $this->inner_template);
		$values = array_merge($values, $set_values);


		if ($this->footer) {
			list($footer_string, $footer_set_values) = $this->_process_conditionals($values, $this->footer);
			$email_body .= $footer_string;
			$set_values = array_merge($set_values, $footer_set_values);
		}

		if (!trim($email_body)) {
			// The email has no content!  Don't send it
			return;
		}
		

		$split_template = preg_split(
			'/\*([^\*\| ]+(?:\|[^\*]+)?)\*/', $email_body, NULL,
			PREG_SPLIT_DELIM_CAPTURE);

		$all_values = array_merge($values, $set_values);

		$split_template_size = count($split_template);
		for($i=0;$i<$split_template_size;$i++) {
			if ($i % 2) {
				// Due to the way the split works, the template values always
				// are on odd numbers

				// Search for pipe qualifiers
				$pipe_search = explode('|', $split_template[$i]);

				$pipe_values = NULL;
				if (count($pipe_search) >= 2) {
					// If the explode returned another value, then we know the person
					// had a pipe qualifier *placeholder|pipe_qualifier*
					$pipe_values = array_slice($pipe_search, 1);
				}

				$template_placeholder = $pipe_search[0];
				$value = $this->_process_value($template_placeholder, $all_values);

				if ($value instanceof DateTime) {
					// Handle DateTimes in a special way
					if ($pipe_values) {
						if (count($pipe_values) == 1) {
							$split_template[$i] = $value->format($pipe_values[0]);
						} else if (count($pipe_values) == 2) {
							$value->setTimeZone(new DateTimeZone($this->_process_value($pipe_values[1], $all_values)));
							$split_template[$i] = $value->format($pipe_values[0]);
						}
					} else {
						$split_template[$i] = $value->format(DATE_ATOM);
					}
				} elseif (is_string($value)) {
					if ($pipe_values) {
						foreach ($pipe_values as $pipe_value) {
							switch ($pipe_value) {
								case 'nl2br':
									$value = nl2br($value);
									break;
								default: // ignore unknown pipe_values
									break;
							}
						}
					}
					$split_template[$i] = $value;
				} else {
					$split_template[$i] = $value;
				}
			}
		}

		$html = trim(implode('', $split_template));
		$html_lines = preg_split('/[\r\n]/', $html, NULL, PREG_SPLIT_NO_EMPTY);

		if ($html_lines && stripos(trim($html_lines[0]), 'subject:') === 0) {
			$this->email_subject = substr(trim($html_lines[0]), 8);
			$html = implode("\n", array_slice($html_lines, 1));
			$this->email_has_content = TRUE;
		}	
		

		$html = str_replace(
			'*!**mail_body**!*', $html, $this->outer_template);
		
		if($values['utm_source']){
			$this->utm_source = $values['utm_source'];
			$html = $this->_add_tracking_to_links($html);
		}

		// The email has content at this point, we can send it
		$this->email_html = $html;
		$this->email_text = LibraryFunctions::htmlToText($html);

		if (array_key_exists('from', $set_values) && array_key_exists('from_name', $set_values)) {
			$this->email_from = $set_values['from'];
			$this->email_from_name = $set_values['from_name'];
		}

		return $set_values;
	}
	

	function is_sendable() {
		return $this->email_has_content;
	}

	function send($check_session=TRUE, $other_host=NULL) {
		$settings = Globalvars::get_instance();

		// If the email has no content, don't send it
		if (!$this->email_has_content) {
			return FALSE;
		}

		if ($check_session) {
			$session = SessionControl::get_instance();

			if(!$session->send_emails()) {
				//STORE THE EMAIL IN A LOG
				$debug_log = new DebugEmailLog(NULL);
				$debug_log->set('del_subject', $this->email_subject);
				$debug_log->set('del_body', $this->email_html);
				$debug_log->save();				
				
				return FALSE;
			}
		}

		
		if($this->mailer){
			$this->mailer = new smtpmailer();
			if ($other_host) {
				$this->mailer->Host = $other_host;
			}
			$this->mailer->From = $this->email_from;
			$this->mailer->FromName =  $this->email_from_name;
			
			foreach($this->email_recipients as $recipient){
				$this->mailer->AddAddress($recipient['email'], $recipient['name']);
			}
			
			$this->mailer->Body = $this->email_html;
			$this->mailer->AltBody = $this->email_text;
			

			if (!$this->mailer->Send()) {
				// Oops, email didn't send.  Save it and move on.
				$this->save_email_as_queued(NULL, QueuedEmail::NORMAL_MAILER_ERROR);
			}				
		}
		else if($settings->get_setting('mailgun_api_key') && $settings->get_setting('mailgun_domain')) {

			if($settings->get_setting('mailgun_version') == 1){
				if($settings->get_setting('mailgun_eu_api_link')){
					$mg = new Mailgun($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
				}
				else{
					$mg = new Mailgun($settings->get_setting('mailgun_api_key'));
				}
			}
			else{
				if($settings->get_setting('mailgun_eu_api_link')){
					$mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
				}
				else{
					$mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
				}
								
			}
			$domain = $settings->get_setting('mailgun_domain');	
			
			$email_to_send = array(
				'from'=>$this->email_from_name .'<'. $this->email_from . '>',
				'subject' => $this->email_subject,
				);
				
			if($this->email_html){
				$email_to_send['html'] = $this->email_html;
				$email_to_send['text'] = $this->email_text;
			}
			else{
				$email_to_send['text'] = $this->email_text;
			}

			$sending_groups = array_chunk($this->email_recipients,500,true);

			foreach ($sending_groups as $sending_group){
				//RECIPIENT VARIABLES ARE NOT FULLY IMPLEMENTED
				$mailgun_recipients = array();	
				$recipient_variables = array();
				
				foreach($sending_group as $recipient){
					$mailgun_recipients[] = $recipient['name'] . '<' . $recipient['email'] . '>';		
					$recipient_variables[$recipient['email']] = array('name'=>$recipient['name']);
				}
				$email_to_send['to'] = implode(',', $mailgun_recipients);							
				$email_to_send['recipient-variables'] = json_encode($recipient_variables);			
				

				try{
					if($settings->get_setting('mailgun_version') == 1){
						$result = $mg->sendMessage($domain, $email_to_send);
					}
					else{
						$result = $mg->messages()->send($domain, $email_to_send);
					}
				}
				catch (Exception $e) {
					// Oops, email didn't send.  Save it and move on.
					$this->save_email_as_queued(NULL, QueuedEmail::NORMAL_MAILER_ERROR);
				}
				
			}
			//TODO: ERROR CHECKING ON RETURN RESULT
			return true;
						
		}
		
	}

	function send_test() {
		$this->clear_recipients();

		foreach ($this->testemails as $testemail) {
			
			$this->add_recipient($testemail, 'John Smith');
			$this->send(FALSE);
			$this->clear_recipients();
		}

		return TRUE;
	}

	function send_admin_notify() {
		$this->clear_recipients();
		$this->add_recipient($this->admin_notify_address, 'Admin');
		$this->send(FALSE);
		
		$this->clear_recipients();
	}

	function save_email_as_queued($log_entry_id=NULL, $status=QueuedEmail::QUEUED) {
		
		$this->_generate_tracking_code($log_entry_id);
		$queued_email = new QueuedEmail(NULL);
		$queued_email->set('equ_from_name', $this->email_from_name);
		$queued_email->set('equ_from', $this->email_from);
		$to_address_list = $this->email_recipients;
		//list($to, $to_name) = $to_address_list[0];
		$queued_email->set('equ_to', $to_address_list[0]['email']);
		$queued_email->set('equ_to_name', $to_address_list[0]['name']);
		$queued_email->set('equ_body', $this->email_html);
		$queued_email->set('equ_subject', $this->email_subject);
		$queued_email->set('equ_status', $status);

		if ($log_entry_id) {
			$queued_email->set('equ_ers_recurring_email_log_id', $log_entry_id);
		}

		$queued_email->save();
		return $queued_email->key;
	}
}


?>
