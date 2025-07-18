<?php
require_once('DbConnector.php');
require_once('Globalvars.php');

/**
 * Base class for all FormWriter implementations
 * Contains all methods that are identical across UIKit, Falcon, and Tailwind implementations
 */
abstract class FormWriterBase {

	public static $tab_count = 0;

	protected $formid;
	protected $captcha_public;
	protected $captcha_private;
	protected $use_tabindex;

	/**
	 * FormWriter constructor
	 * @param string $formid Form ID
	 * @param boolean $secure Secure mode (unused)
	 * @param boolean $use_tabindex Enable tab indexing for form elements
	 */
	function __construct($formid='form1', $secure=FALSE, $use_tabindex=FALSE){
		$this->formid = $formid;

		$settings = Globalvars::get_instance();

		$this->use_tabindex = $use_tabindex;
	}

	/**
	 * Get the next tab index for form elements
	 * @return string Tab index attribute or empty string
	 */
	protected function _get_next_tab_index() {
		if ($this->use_tabindex) {
			++self::$tab_count;
			return ' tabindex="' . self::$tab_count . '"';
		}
		return '';
	}

	/**
	 * Generate anti-spam question input field
	 * @param string $type Optional type (e.g., 'blog')
	 * @return string HTML for anti-spam field or false if not configured
	 */
	function antispam_question_input($type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$correct_answer = $settings->get_setting('anti_spam_answer_comments');
		}
		else{
			$correct_answer = $settings->get_setting('anti_spam_answer');
		}

		
		if($correct_answer){
			$output .= $this->textinput("Type '".strtolower($correct_answer)."' into this field (to prove you are human)", "antispam_question", "ctrlHolder", 30, '', "", 255, ""); 
			$output .= $this->hiddeninput("antispam_question_answer", strtolower($correct_answer));			
			return $output;
		}
		else{
			return false;
		}
	}

	/**
	 * Add anti-spam validation rules
	 * @param array $validation_rules Existing validation rules
	 * @param string $type Optional type (e.g., 'blog')
	 * @return array Updated validation rules
	 */
	static function antispam_question_validate($validation_rules, $type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$correct_answer = $settings->get_setting('anti_spam_answer_comments');
		}
		else{
			$correct_answer = $settings->get_setting('anti_spam_answer');
		}
		
		if($correct_answer){
			$validation_rules['antispam_question']['required']['value'] = 'true';
			$validation_rules['antispam_question']['equalTo']['value'] = "'#antispam_question_answer'";
			$validation_rules['antispam_question']['equalTo']['message'] = "'You must type the correct word here'";					
		}
		return $validation_rules;
	}	
	
	/**
	 * Check if anti-spam answer is correct
	 * @param array $postvars POST variables
	 * @param string $type Optional type (e.g., 'blog')
	 * @return boolean True if answer is correct or not configured
	 */
	static function antispam_question_check($postvars, $type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$correct_answer = $settings->get_setting('anti_spam_answer_comments');
		}
		else{
			$correct_answer = $settings->get_setting('anti_spam_answer');
		}		
		if($correct_answer){
			if(strtolower($postvars['antispam_question']) == strtolower($correct_answer)){
				return true;		
			}
			else{
				return false;
			}
		}
		else{
			return true;
		}
	}	
	
	/**
	 * Generate honeypot hidden input field
	 * @param string $label Field label
	 * @param string $name Field name
	 * @return string HTML for honeypot field or empty string if not configured
	 */
	function honeypot_hidden_input($label='Extra email', $name='email'){
		$settings = Globalvars::get_instance();
		$use_honeypot = $settings->get_setting('use_honeypot');	
		if($use_honeypot){
			$output = '
			<script type="text/javascript">
			$(document).ready(function() {
				$("#'.$name.'_container").hide();
			});
			</script>';
			$output .= $this->textinput($label, $name, "ctrlHolder", 30, '', "", 255, ""); 
			return $output;
		}
		else{
			return '';
		}
	}
	
	/**
	 * Check if honeypot field is empty (should be for real users)
	 * @param array $postvars POST variables
	 * @param string $name Field name to check
	 * @return boolean True if honeypot check passes
	 */
	static function honeypot_check($postvars, $name='email'){
		$settings = Globalvars::get_instance();
		$use_honeypot = $settings->get_setting('use_honeypot');	
		if($use_honeypot){		
			if(strlen($postvars[$name]) > 0){
				return false;		
			}
			else{
				return true;
			}
		}
		else{
			return true;
		}
	}
	
	/**
	 * Generate CAPTCHA input field
	 * @param string $submit_button_id ID of submit button
	 * @param string $type Optional type (e.g., 'blog')
	 * @return string HTML/JavaScript for CAPTCHA or false if not configured
	 */
	function captcha_hidden_input($submit_button_id="submit1", $type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$use_captcha = $settings->get_setting('use_captcha_comments');
		}
		else{
			$use_captcha = $settings->get_setting('use_captcha');
		}
			
		if($use_captcha == 0){
			return false;
		}	
		
		$output = '';
	
	
		$output = '
		<script type="text/javascript">
		function isCaptchaChecked() {
		  return grecaptcha && grecaptcha.getResponse().length !== 0;
		}		
		
		$("form").submit(function(event) {
		   if(!isCaptchaChecked()){
			  event.preventDefault();
			  alert("Please check the \"I am human\" in the hCaptcha field.");			   
		   }	   
		});
		</script>';	
	
		
		$output .= '<div class="uk-margin">';	
		$output .= '<label class="uk-form-label"></label>';	
		
		
		
		if($settings->get_setting('hcaptcha_public')){
			//HCAPTCHA - SHOW IN FORM
			
			$output .= '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
			$output .= '<div class="h-captcha" data-sitekey="'.$settings->get_setting('hcaptcha_public').'"></div>';			
		}
		else if($settings->get_setting('captcha_public')){
			//GOOGLE CAPTCHA - SHOW IN FORM
			
			$output .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
			$output .= '<div class="g-recaptcha" data-sitekey="'.$settings->get_setting('captcha_public').'"></div>';			
		}	
		
		$output .= '</div>';		
		return $output;
		
	}	
	
	/**
	 * Verify CAPTCHA response
	 * @param array $captcha_full_response CAPTCHA response data
	 * @param string $type Optional type (e.g., 'blog')
	 * @return boolean True if CAPTCHA verification passes
	 */
	static function captcha_check($captcha_full_response, $type=NULL){
		$settings = Globalvars::get_instance();
		
		if($type == 'blog'){
			$use_captcha = $settings->get_setting('use_captcha_comments');
			$captcha_private = $settings->get_setting('captcha_private_comments');
		}
		else{
			$use_captcha = $settings->get_setting('use_captcha');
			$captcha_private = $settings->get_setting('captcha_private');
		}

		if($use_captcha == 0){
			return true;
		}			
		
		if($settings->get_setting('hcaptcha_public')){
			
			$captcha_response = $captcha_full_response['h-captcha-response'];
			
			//HCAPTCHA
			$data = array(
						'secret' => $settings->get_setting('hcaptcha_private'),
						'response' => $captcha_response  //$_POST['h-captcha-response']
					);
			$verify = curl_init();
			curl_setopt($verify, CURLOPT_URL, "https://hcaptcha.com/siteverify");
			curl_setopt($verify, CURLOPT_POST, true);
			curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query($data));
			curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
			$response = curl_exec($verify);
			// var_dump($response);
			$responseData = json_decode($response);
			if($responseData->success) {
				return $responseData->success;
			} 
			else {
			   // return error to user; they did not pass
			   return false;
			}	
		}
		else if($settings->get_setting('captcha_public')){
			
			$captcha_response = $captcha_full_response['g-recaptcha-response'];
			
			//GOOGLE CAPTCHA
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, [
				'secret' => $captcha_private,
				'response' => $captcha_response,
				'remoteip' => $_SERVER['REMOTE_ADDR']
			]);

			$resp = json_decode(curl_exec($ch));
			curl_close($ch);
			
			return $resp->success;	
			
		}
	}	

	/**
	 * Generate hidden input field
	 * @param string $id Field ID/name
	 * @param string $value Field value
	 * @return string HTML for hidden input
	 */
	function hiddeninput($id, $value) {
		return '<input type="hidden" class="hidden" name="'.$id.'" id="'.$id.'" value="'.$value.'" />';
	}

}