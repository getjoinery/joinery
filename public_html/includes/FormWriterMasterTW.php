<?php
require_once('DbConnector.php');
require_once('Globalvars.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterMasterTW {

	public static $tab_count = 0;

	protected $formid;
	protected $captcha_public;
	protected $captcha_private;
	protected $validate_style_info = 'errorElement: "span",
							errorClass: "text-red-500",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name).addClass("border-red-500 focus:border-red-500");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name).removeClass("border-red-500 focus:border-red-500");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING
	protected $button_primary_class = 'inline-flex justify-center mr-3 mt-3 py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500';
	protected $button_secondary_class = 'bg-white py-2 px-4 mr-3 mt-3 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500';	
	
	protected $fileinput_label_class = 'block text-sm font-medium text-gray-700';
	protected $fileinput_input_class = '';
	
	protected $text_label_class = 'block text-sm font-medium text-gray-700';
	
	protected $textinput_label_class = 'block text-sm font-medium text-gray-700';
	protected $textinput_input_class = 'shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md';
	
	protected $textbox_label_class = 'block text-sm font-medium text-gray-700';
	protected $textbox_textarea_class = 'shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border border-gray-300 rounded-md';
	
	protected $checkbox_legend_class = 'text-base font-medium text-gray-900';
	protected $checkbox_container_class = 'mt-4 space-y-4';
	protected $checkbox_outer_wrapper_class = 'relative flex items-start';
	protected $checkbox_inner_wrapper_class = 'flex items-center h-5';
	protected $checkbox_input_class_checkbox = 'focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded';	
	protected $checkbox_label_wrapper_class = 'ml-3 text-sm';	
	protected $checkbox_label_class = 'font-medium text-gray-700';	
	
	protected $timeinput_label_class = 'block text-sm font-medium text-gray-700';
	protected $timeinput_input_class = 'shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md timepicker';
	
	protected $dropinput_label_class = 'block text-sm font-medium text-gray-700';
	protected $dropinput_select_class = 'mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md';

	function __construct($formid='form1', $secure=FALSE, $use_tabindex=FALSE){
		$this->formid = $formid;

		$settings = Globalvars::get_instance();
		if ($secure) {
			$this->cdn = $settings->get_setting('CDN_SSL');
		} else {
			$this->cdn = $settings->get_setting('CDN');
		}

		$this->use_tabindex = $use_tabindex;
	}

	protected function _get_next_tab_index() {
		if ($this->use_tabindex) {
			++self::$tab_count;
			return ' tabindex="' . self::$tab_count . '"';
		}
		return '';
	}



	function antispam_question_input($type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$correct_answer = $settings->get_setting('anti_spam_answer_comments');
		}
		else{
			$correct_answer = $settings->get_setting('anti_spam_answer');
		}

		
		if($correct_answer){
			$output .= $this->textinput("Type '".strtolower($correct_answer)."' into this field (to prove you are human)", "antispam_question", NULL, 30, '', "", 255, ""); 
			$output .= $this->hiddeninput("antispam_question_answer", strtolower($correct_answer));			
			return $output;
		}
		else{
			return false;
		}
	}

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
	
	static function antispam_question_check($postvars){
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
			$output .= $this->textinput($label, $name, NULL, 30, '', "", 255, ""); 
			return $output;
		}
		else{
			return '';
		}
	}
	
	
	static function honeypot_check($postvars, $name='email'){
		$settings = Globalvars::get_instance();
		$use_honeypot = $settings->get_setting('use_honeypot');	
		if($use_honeypot){		
			if(strlen($postvars['email'] > 0)){
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
			
		if($settings->get_setting('hcaptcha_public')){
			//HCAPTCHA
			$output .= '<div id="captcha_container" class="uk-margin errorplacement ">';
			$output .= "<script src='https://www.hCaptcha.com/1/api.js' async defer></script>";
			$output .= '<div id="captcha_field" class="h-captcha" data-callback="enableBtn" data-sitekey="'.$settings->get_setting('hcaptcha_public').'"></div>';
			$output .= '</div>';
		}
		else if($settings->get_setting('captcha_public')){
			//GOOGLE
			$output .= '<div id="captcha_container" class="uk-margin errorplacement ">';
			$output .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
			$output .= '<div id="captcha_field" class="g-recaptcha" data-sitekey="'.$settings->get_setting('captcha_public').'" data-callback="enableBtn"></div>';
			$output .= '</div>';
		}
		
		return $output;
	}

	
	static function captcha_check($captcha_full_response, $type=NULL){
		$settings = Globalvars::get_instance();
		if($type == 'blog'){
			$use_captcha = $settings->get_setting('use_captcha_comments');
		}
		else{
			$use_captcha = $settings->get_setting('use_captcha');
		}
		if($use_captcha == 0){
			return true;
		}	
		
		$captcha_private = $settings->get_setting('hcaptcha_private');
		
		if($settings->get_setting('hcaptcha_public')){
			
			$captcha_response = $captcha_full_response['h-captcha-response'];

			$data = array(
						'secret' => $captcha_private,
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

	function begin_form($class, $method, $action, $use_grid = false, $charset = 'UTF-8'){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		
		if($use_grid){
			$output .= '<div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 ">';
		}
		return $output;
	}

	function end_form($use_grid = NULL){
		if($use_grid){
			return '</div></form>';
		}
		else{
			return '</form>';
		}
		
	}
	
	
	function start_buttons($class = 'flex justify-end') {
		return '<div class="'.$class.'">';
	}

	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_button($label='Submit', $link, $style='primary', $width='standard', $class='', $id=NULL) {
		
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}
		
		if($width == 'full'){
			$class = 'w-full '. $class;
		}
		
		
		$output = '<a href="'.$link.'"><button type="button" class="'.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button></a>';
		return $output;
	}

	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_form_button($label='Submit', $style='primary', $width='standard', $class='', $id=NULL) {
		
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}

		if($width == 'full'){
			$class = 'w-full '. $class;
		}		
		
		$output = '<button type="submit" class="'.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button>';
		return $output;
	}



	
	function end_buttons() {
		return '</div>';
	}
	
	function set_validate($validation_rules){
		
		$output = '
		<script type="text/javascript">
			$(document).ready(function() {

				jQuery.validator.addMethod("phoneUS", function(phone_number, element) {
				    phone_number = phone_number.replace(/\s+/g, "");
					return this.optional(element) || phone_number.length > 9 &&
						phone_number.match(/^(1-?)?(\([2-9]\d{2}\)|[2-9]\d{2})-?[2-9]\d{2}-?\d{4}$/);
				}, "Please specify a valid phone number");

					$("#'.$this->formid.'").validate({
							rules: {';
							$output .= "\r\n";
							foreach($validation_rules as $name=>$rules){
								$output .= $name .': {';
								$output .= "\r\n";
								foreach($rules as $type=>$value){
									$output .=  $type . ': '. $value['value'] . ',';
									$output .= "\r\n";
								}
								$output .=  '},';
								$output .= "\r\n";
							}
							$output .=  '},';
							$output .= "\r\n";
							
							$output .=  'messages: {';
							$output .= "\r\n";
							foreach($validation_rules as $name=>$rules){

								foreach($rules as $rule_name=>$rule_values){
									if($rule_values['message']){
										$output .=  $name .': {';
										$output .= "\r\n";
										$output .=  $rule_name . ': '. $rule_values['message'] . ',';
										$output .= "\r\n";
										$output .=  '},';
										$output .= "\r\n";
									}
								}
							}
							$output .=  '},';	
							$output .= "\r\n";							
							$output .=  $this->validate_style_info .'
							});';

			$output .= '});
		  </script>';
	
		return $output;
	
	}
	


	function fileinput($label, $id, $class="sm:col-span-6", $size, $hint) {
		$output = '
		<div id="'.$id.'_container" class="'.$class.' errorplacement">
		<label class="'.$this->fileinput_label_class.'" for="'.$id.'">'.$label.'</label>
		<input name="'.$id.'" id="'.$id.'"  size="'.$size.'" type="file" class="'.$this->fileinput_input_class.'" />
		</div>';
		return $output;
	}


	function passwordinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="") {
		
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, TRUE, FALSE, 'password');
	}

	function text($id, $label, $value, $class) {
		
		if(empty($class)){
			$class = 'sm:col-span-6';
		}
		
		$output = '
		<div id="'.$id.'_container" class="'.$class.' errorplacement">
		<label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
		<span>'.$value.'</span>
		</div>';
		return $output;
	}




	function textinput($label, $id, $class='sm:col-span-6', $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='text') {
		
		if(empty($class)){
			$class = 'sm:col-span-6';
		}


		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		$layout = '';
		if($layout == 'horizontal'){
			$labelclass = $this->textinput_label_class_horizontal;
			$containerclass = $this->textinput_container_class_horizontal;
			$inputclass = $this->textinput_input_class;
		}
		else{
			$labelclass = $this->textinput_label_class;
			$containerclass = $this->textinput_container_class;
			$inputclass = $this->textinput_input_class;
		}
		
		if($value){
			$value = str_replace('"', '&quot;', $value );
		}
		
		/*
		if($hint){ 
			$hint_text = 'placeholder="'.$hint.'" onfocus="this.placeholder = \'\'" onblur="this.placeholder = \''.$hint.'\'"';
		}	
		*/

	
		$output = '<div id="'.$id . '_container" class="errorplacement '.$class.'">';
		
		if($label){
			$output .= '<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';
		} 
		
		if(!$autocomplete){
			$autocomplete = 'autocomplete="off"';
		}
		else{
			$autocomplete = '';
		}

		
		
		if($formhint){
			$output .= '<div class="mt-1 flex rounded-md shadow-sm">';
			$output .= '<span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm">
              '.$formhint.'
            </span>';
		}
		else{
			$output .= '<div class="mt-1">';
		}
		
		$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="'.$inputclass.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/></div>';
		

		$output .= '</div>';
		
		return $output;

	}
	



	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
		
		if(empty($class)){
			$class = 'sm:col-span-6';
		}
		
		$output = '';
		if($htmlmode == 'yes'){
			$output .= '
			
			<script src="/adm/includes/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>
			<link rel="stylesheet" href="/adm/includes/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js"></script>
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js"></script>
			<script src="/adm/includes/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"></script>
			<script type="text/javascript">';
			$output .= "
				$(document).ready(function() {
						$('.html_editable').trumbowyg({
							autogrow: false,
							autogrowOnEnter: false,
							btns: [
								['viewHTML'],
								['undo', 'redo'], // Only supported in Blink browsers
								['formatting'],
								['strong', 'em', 'del'],
								['superscript', 'subscript'],
								['link'],
								['insertImage'],
								['preformatted'],
								['justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'],
								['unorderedList', 'orderedList'],
								['horizontalRule'],
								['removeformat'], 
								['fullscreen']
							],
							 semantic:{
							  'div': 'div'
							},
							plugins: {
								allowTagsFromPaste: {
									allowedTags: ['p', 'br','blockquote', 'b', 'i', 'strong', 'em', 'ul', 'li', 'ol', 'a','code','pre','h1','h2','h3','h4','h5','embed','table','tr','td','th','img','video']
								}
							}
						});
						$('.trumbowyg-editor').attr('name', 'trumbobox');

				});
			</script>
				
			<style>
			.trumbowyg-editor {
				max-height: 500px;
			}
			</style>

			";
			$output .= '<div id="'.$id.'_container" class="errorplacement">
				<label class="'.$this->textbox_label_class.'" for="'.$id.'">'.$label.'</label>
					<div class="mt-1">
					<textarea name="'.$id.'" id="'.$id.'" class="html_editable" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea></div>';
		}
		else{
			$output .= '<div id="'.$id.'_container" class="'.$class.' errorplacement">
				<label class="'.$this->textbox_label_class.'" for="'.$id.'">'.$label.'</label>
					<div class="mt-1">
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.'" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea></div>';
					
			if($formhint){
				$output .= '<div id="'.$id.'_hint"><small>'.$formhint.'</small></div>';
			}
		}

		$output .= '
		</div>';
		
		return $output;
	}

	function hiddeninput($id, $value) {
		return '<input type="hidden" class="hidden" name="'.$id.'" id="'.$id.'" value="'.$value.'" />';
	}
	

	
	function checkboxinput($label, $id, $class='sm:col-span-6', $align, $value, $truevalue, $hint){
		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}

		return '<div id="'.$id.'_container" class="'.$class.' errorplacement">
					<div class="'.$this->checkbox_outer_wrapper_class.'">
						<div class="'.$this->checkbox_inner_wrapper_class.'">
							<input class="'.$this->checkbox_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$value.'" '.$checked.' '.$disabled.' />
						</div>
						<div class="'.$this->checkbox_label_wrapper_class.'">
							<label class="'.$this->checkbox_label_class.'" for="'.$id.'">'.$label.'</label>      
						</div>
					</div>
				</div>';

	}


	/*******************************
	GENERATES A CHECKBOX GROUP GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			optionvals(associative array, 'label'=>'value')
			checkedvals(single dimensional array)
			readonlyvals(single dimensional array)
	********************************/


	
	//IF TYPE IS 'RADIO' THIS BECOMES A RADIO INPUT
	function checkboxList($label, $id, $class='sm:col-span-6', $optionvals, $checkedvals=array(), $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
		$output = '';

		if(empty($optionvals)){
			return false;
		}
		
		if(empty($class)){
			$class='sm:col-span-6';
		}

		if(!is_array($checkedvals)){
			$checkedvals = array();
		}

		if($type=='checkbox'){
			//$class= $this->checkbox_input_class_checkbox;
		}
		else if($type=='radio'){
			$type='radio';
			//$class= $this->checkbox_input_class_radio;
			
			if(is_array($checkedvals) && count($checkedvals) > 1){
				throw new SystemDisplayableError('A radio field cannot have more than one checked value.');
			}
			
			if($readonlyvals){
				throw new SystemDisplayableError('A radio field cannot have read only values.');
			}			
		}
		else{
			throw new SystemDisplayableError('Invalid checkbox type.');
		}

	
		$output .=  '<fieldset class="'.$class.'">';
		$output .= '<legend class="'.$this->checkbox_legend_class.'">'.$label.'</label>';
		$output .= '<div id="'.$id.'_container" class="'.$this->checkbox_container_class.' errorplacement">';


		foreach ($optionvals as $key => $value) {
			$uniqid = $id . $value;
			if(in_array($value, $checkedvals)){
				$checked = 'checked="checked"';
			}
			else{
				$checked = '';
			}
			
			//DISABLED MEANS THE VALUE IS NOT PASSED THROUGH POST
			if(in_array($value, $disabledvals)){
				$disabled = 'disabled="disabled"';
			}
			else{
				$disabled = '';
			}			

			//READONLY MEANS IT CANNOT BE CHANGED AND IS SUBMITTED THROUGH POST
			if(in_array($value, $readonlyvals)){
				if($checked){
					$output .= $this->hiddeninput($id.'[]', $value);	
					$output .= '<label for="'.$uniqid.'">'.$key.' (checked, read only)</label><br>';
				}
				else{
					$output .= $this->hiddeninput($id.'[]', '');	
					$output .= '<label for="'.$uniqid.'">'.$key.' (unchecked, read only)</label><br>';
				}
			}
			else{

				$output .= '<div class="'.$this->checkbox_outer_wrapper_class.'">
								<div class="'.$this->checkbox_inner_wrapper_class.'">
									<input class="'.$this->checkbox_input_class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' '.$disabled.' />
								</div>
								<div class="'.$this->checkbox_label_wrapper_class.'">
									<label class="'.$this->checkbox_label_class.'" for="'.$uniqid.'">'.$key.'</label>      
								</div>
						</div>
					   ';
			}
		}
		$output .=  '</div></fieldset>';
		
		return $output;

	}



	/*******************************
	GENERATES A RADIO GROUP GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			optionvals(associative array, 'label'=>'value')
			checkedval
			readonlyvals(single dimensional array)
	********************************/
	function radioinput($label, $id, $class, &$optionvals, $checkedval, $disabledvals, $readonlyvals, $hint) {
		
		if(empty($class)){
			$class='sm:col-span-6';
		}		
		
		$checkedvals = array($checkedval);
		return $this->checkboxList($label, $id, $class, $optionvals, $checkedvals, $disabledvals, $readonlyvals, $hint, 'radio');
		
	}

	function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='date'){
	
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, $autocomplete, $formhint, $type);
		
	}
	
	//FORMAT 'HH:MM PM'
	function timeinput($label, $id, $class, $value, $hint) {

		return '
		<link rel="stylesheet" href="/adm/includes/jquery-timepicker-1.3.5/jquery.timepicker.min.css"/>
		<script type="text/javascript" src="/adm/includes/jquery-timepicker-1.3.5/jquery.timepicker.min.js"></script>
		<script type="text/javascript">
		$(document).ready(function(){
			$("input.timepicker").timepicker({
				timeFormat: "h:mm p",
				interval: 15,
				//minTime: "10",
				//maxTime: "6:00pm",
				//defaultTime: "11",
				//startTime: "00:00",
				//dynamic: false,
				//dropdown: true,
				//scrollbar: true
			});
		});
		</script>
		<!-- time Picker -->
			<div id="'.$id.'_container" class="'.$class.' errorplacement">
			  <label class="'.$this->timeinput_label_class.'" for="'.$id.'">'.$label.'</label>
				<input class="'.$this->timeinput_input_class.'"  type="text" id="'.$id.'" name="'.$id.'" value="'.$value.'">
			</div>';
	
	}




	//DOES NOT CONVERT FOR TIMEZONES
	function datetimeinput($label, $id, $class, $inputdatetime, $hint, $timehint, $datehint) {

		if(empty($class)){
			$class='sm:col-span-6';
		}

		if(!is_null($inputdatetime) && $inputdatetime != ''){
			$session = SessionControl::get_instance();
			$inputdate = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'Y-m-d');
			$inputtime = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'g:i a');
		}
		else{
			$inputdate = '';
			$inputtime = '';
		}
		
		
		$output = $this->dateinput($label, $id.'_date', $class, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, $type='date');
		
		$output .= $this->timeinput($label, $id.'_time', $class, $inputtime, NULL); 
		return $output;

	}

	function dropinput($label, $id, $class, &$optionvals, $input, $hint,$showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE, $imagedropdown=FALSE) {
		
		if(empty($class)){
			$class='sm:col-span-6';
		}
		
		$output = '';
		
		if($ajaxendpoint){
			$output .= '<link href="/includes/select2.min.css" rel="stylesheet" />
			<script src="/includes/select2.full.min.js"></script>';
		
			$output .= '<script type="text/javascript">
			$(document).ready(function() {
			  $("#'.$id.'").select2({
				placeholder: "Select an item",
				ajax: {
				  url: "'.$ajaxendpoint.'",
				  dataType: "json",
				  delay: 250,
				  processResults: function (data) {
					return {
					  results: data
					};
				  },
				  minimumInputLength: 3,
				  cache: true
				}
			  });
			});
				</script>';
		
		}
		
		
	
			$output .= '<div id="'.$id.'_container" class="'.$class.' errorplacement">
						<label class="'.$this->dropinput_label_class.'" for="'.$id.'">'.$label.'</label>
						
							<select name="'.$id.'" id="'.$id.'" class="'.$this->dropinput_select_class.'">';
								


			if($showdefault){
				if(is_null($input)){
					$output .=  '<option value="" selected="selected">Choose One';
				}
				else{
					$output .= '<option value="">Choose One';
				}
			}


			foreach ($optionvals as $key => $value) {
			
				if($forcestrict){
					if ($input === $value) { 
						$output .= '<option value="'. $value .'" selected="selected">' . $key . '</option>';
					} 
					else {
						$output .= '<option value="'. $value .'">' . $key . '</option>';
					}					
				}
				else{
					if ($input == $value) { 
						$output .= '<option value="'. $value .'" selected="selected">' . $key . '</option>';
					} 
					else {

						$output .= '<option value="'. $value .'">' . $key . '</option>';
					}
				}
			}
			$output .= '</select>				
						
					</div>';	
		

			return $output;
				 
	}

	function imageinput($label, $id, $class, &$optionvals, $input, $hint,$showdefault=TRUE, $forcestrict=TRUE, $ajaxendpoint=FALSE) {
		
		$output = '';
		
			$output .= '
			<style>
			.image-dropdown {
				/*style the "box" in its minimzed state*/
				border:1px solid black; width:600px; height:80px; overflow:hidden;
				/*animate the dropdown collapsing*/
				transition: height 0.1s;
			}
			.image-dropdown:hover {
				/*when expanded, the dropdown will get native means of scrolling*/
				height:400px; overflow-y:scroll;
				/*animate the dropdown expanding*/
				transition: height 0.5s;
			}
			.image-dropdown input {
				/*hide the nasty default radio buttons!*/
				position:absolute;top:0;left:0;opacity:0;
			}
			.image-dropdown label {
				/*style the labels to look like dropdown options*/
				display:none; margin:2px; height:80px; opacity:0.8;  overflow:hidden;
				/*background:url("http://www.google.com/images/srpr/logo3w.png") 50% 50%;*/
				}
			.image-dropdown:hover label{
				/*this is how labels render in the "expanded" state.
				 we want to see only the selected radio button in the collapsed menu,
				 and all of them when expanded*/
				display:block;
			}
			.image-dropdown input:checked + label {
				/*tricky! labels immediately following a checked radio button
				  (with our markup they are semantically related) should be fully opaque
				  and visible even in the collapsed menu*/
				opacity:1 !important; font-weight: bold; display:block;
			}
			.dropimagewidth {
				display: inline-block;
				width: 80px;
				padding-right: 5px;
			}
			</style>
			';
			
			$output .= '<h5>'.$label.'</h5><div id="'.$id.'_container" class="uk-margin errorplacement image-dropdown">';
								

			if($showdefault){
				if(is_null($input)){
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/adm/includes/images/image_placeholder_thumbnail.png"></span> No Image</label>';
				}
				else{
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/adm/includes/images/image_placeholder_thumbnail.png"></span> No Image</label>';
				}
			}


			foreach ($optionvals as $key => $value) {			

				if($forcestrict && $input === $value){
					
					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" checked="checked" /><label for="' . $value . '_id"> ' . $key . '</label>';
				} elseif ($input == $value) { 
					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" checked="checked" /><label for="' . $value . '_id"> ' . $key . '</label>';
				} else {

					$output .= '<input type="radio" id="' . $value . '_id" name="'.$id.'" value="'. $value .'" /><label for="' . $value . '_id"> ' . $key . '</label>';
				}
			}
			$output .= '
					</div>';

		

			return $output;
				 
	}




	static function file_upload_full($getvars=NULL, $delete=FALSE, $checkall=FALSE){
		$getargs = '';
		if($getvars){ 
			foreach($getvars as $getvar=>$getval){
				$getargs.= '<input type="hidden" name="'.$getvar.'" value="'.$getval.'"/>';      
			}
		}
	?>
      <form
        id="fileupload"
        action="/admin/admin_file_upload_process"
        method="POST"
        enctype="multipart/form-data"
      >
		
		<?php if($getargs){ echo $getargs; } ?>
		
        <!-- The fileupload-buttonbar contains buttons to add/delete files and start/cancel the upload -->
        <div class="row fileupload-buttonbar">
          <div class="col-lg-7">
            <!-- The fileinput-button span is used to style the file input field as button -->
            <span class="btn btn-success fileinput-button">
              <i class="glyphicon glyphicon-plus"></i>
              <span>Add files...</span>
              <input type="file" name="files[]" multiple />
            </span>
            <button type="submit" class="btn btn-primary start">
              <i class="glyphicon glyphicon-upload"></i>
              <span>Start upload</span>
            </button>
            <button type="reset" class="btn btn-warning cancel">
              <i class="glyphicon glyphicon-ban-circle"></i>
              <span>Cancel upload</span>
            </button>
			<?php if($delete){ ?>
            <button type="button" class="btn btn-danger delete">
              <i class="glyphicon glyphicon-trash"></i>
              <span>Delete selected</span>
            </button>
			<?php } ?>
			<?php if($checkall){ ?>
            <input type="checkbox" class="toggle" />
			<?php } ?>
            <!-- The global file processing state -->
            <span class="fileupload-process"></span>
          </div>
          <!-- The global progress state -->
          <div class="col-lg-5 fileupload-progress fade">
            <!-- The global progress bar -->
            <div
              class="progress progress-striped active"
              role="progressbar"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <div
                class="progress-bar progress-bar-success"
                style="width:0%;"
              ></div>
            </div>
            <!-- The extended global progress state -->
            <div class="progress-extended">&nbsp;</div>
          </div>
        </div>
        <!-- The table listing the files available for upload/download -->
        <table role="presentation" class="table table-striped">
          <tbody class="files"></tbody>
        </table>
      </form>
	   <div
      id="blueimp-gallery"
      class="blueimp-gallery blueimp-gallery-controls"
      data-filter=":even"
    >
      <div class="slides"></div>
      <h3 class="title"></h3>
      <a class="prev">‹</a>
      <a class="next">›</a>
      <a class="close">×</a>
      <a class="play-pause"></a>
      <ol class="indicator"></ol>
    </div>
    <!-- The template to display files available for upload -->
    <script id="template-upload" type="text/x-tmpl">
      {% for (var i=0, file; file=o.files[i]; i++) { %}
          <tr class="template-upload fade">
              <td>
                  <span class="preview"></span>
              </td>
              <td>
                  {% if (window.innerWidth > 480 || !o.options.loadImageFileTypes.test(file.type)) { %}
                      <p class="name">{%=file.name%}</p>
                  {% } %}
                  <strong class="error text-danger"></strong>
              </td>
              <td>
                  <p class="size">Processing...</p>
                  <div class="progress progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-success" style="width:0%;"></div></div>
              </td>
              <td>
                  {% if (!o.options.autoUpload && o.options.edit && o.options.loadImageFileTypes.test(file.type)) { %}
                    <button class="btn btn-success edit" data-index="{%=i%}" disabled>
                        <i class="glyphicon glyphicon-edit"></i>
                        <span>Edit</span>
                    </button>
                  {% } %}
                  {% if (!i && !o.options.autoUpload) { %}
                      <button class="btn btn-primary start" disabled>
                          <i class="glyphicon glyphicon-upload"></i>
                          <span>Start</span>
                      </button>
                  {% } %}
                  {% if (!i) { %}
                      <button class="btn btn-warning cancel">
                          <i class="glyphicon glyphicon-ban-circle"></i>
                          <span>Cancel</span>
                      </button>
                  {% } %}
              </td>
          </tr>
      {% } %}
    </script>
    <!-- The template to display files available for download -->
    <script id="template-download" type="text/x-tmpl">
      {% for (var i=0, file; file=o.files[i]; i++) { %}
          <tr class="template-download fade">
              <td>
                  <span class="preview">
                      {% if (file.thumbnailUrl) { %}
                          <a href="{%=file.url%}" title="{%=file.name%}" download="{%=file.name%}" data-gallery><img src="{%=file.thumbnailUrl%}"></a>
                      {% } %}
                  </span>
              </td>
              <td>
                  {% if (window.innerWidth > 480 || !file.thumbnailUrl) { %}
                      <p class="name"><a href="/admin/admin_files">{%=file.name%}</a>
					  <?php /*
                          {% if (file.url) { %}
                              <a href="{%=file.url%}" title="{%=file.name%}" download="{%=file.name%}" {%=file.thumbnailUrl?'data-gallery':''%}>{%=file.name%}</a>
                          {% } else { %}
                              <span>{%=file.name%}</span>
                          {% } %}
						  */ 
						  ?>
                      </p>
                  {% } %}
                  {% if (file.error) { %}
                      <div><span class="label label-danger">Error</span> {%=file.error%}</div>
                  {% } %}
              </td>
              <td>
                  <span class="size">{%=o.formatFileSize(file.size)%}</span>
              </td>
              <td> 
				<?php if($delete){ ?>
                  {% if (file.deleteUrl) { %}
                      <button class="btn btn-danger delete" data-type="{%=file.deleteType%}" data-url="{%=file.deleteUrl%}"{% if (file.deleteWithCredentials) { %} data-xhr-fields='{"withCredentials":true}'{% } %}>
                          <i class="glyphicon glyphicon-trash"></i>
                          <span>Delete</span>
                      </button>
                      <input type="checkbox" name="delete" value="1" class="toggle">
                  {% } else { %}
                      <button class="btn btn-warning cancel">
                          <i class="glyphicon glyphicon-ban-circle"></i>
                          <span>Cancel</span>
                      </button>
                  {% } %}
				<?php } ?>
              </td>
          </tr>
      {% } %}
    </script>
	
    <!-- The jQuery UI widget factory, can be omitted if jQuery UI is already included -->
    <!--<script src="/includes/jquery-file-upload/js/vendor/jquery.ui.widget.js"></script>-->
    <!-- The Templates plugin is included to render the upload/download listings -->
    <script src="/includes/jquery-file-upload/js/tmpl.min.js"></script>
    <!-- The Load Image plugin is included for the preview images and image resizing functionality -->
    <!--<script src="/includes/jquery-file-upload/js/load-image.all.min.js"></script>-->
    <!-- The Canvas to Blob plugin is included for image resizing functionality -->
    <!--<script src="https://blueimp.github.io/JavaScript-Canvas-to-Blob/js/canvas-to-blob.min.js"></script>-->

    <!-- blueimp Gallery script -->
    <!--<script src="https://blueimp.github.io/Gallery/js/jquery.blueimp-gallery.min.js"></script>-->
    <!-- The Iframe Transport is required for browsers without support for XHR file uploads -->
    <script src="/includes/jquery-file-upload/js/jquery.iframe-transport.js"></script>
    <!-- The basic File Upload plugin -->
    <script src="/includes/jquery-file-upload/js/jquery.fileupload.js"></script>
    <!-- The File Upload processing plugin -->
    <script src="/includes/jquery-file-upload/js/jquery.fileupload-process.js"></script>
    <!-- The File Upload image preview & resize plugin -->
    <!--<script src="/includes/jquery-file-upload/js/jquery.fileupload-image.js"></script>-->
    <!-- The File Upload audio preview plugin -->
    <!--<script src="/includes/jquery-file-upload/js/jquery.fileupload-audio.js"></script>-->
    <!-- The File Upload video preview plugin -->
    <!--<script src="/includes/jquery-file-upload/js/jquery.fileupload-video.js"></script>-->
    <!-- The File Upload validation plugin -->
    <!--<script src="/includes/jquery-file-upload/js/jquery.fileupload-validate.js"></script>-->
    <!-- The File Upload user interface plugin -->
    <script src="/includes/jquery-file-upload/js/jquery.fileupload-ui.js"></script>
    
	<script>
	$(function() {
  'use strict';

  // Initialize the jQuery File Upload widget:
  $('#fileupload').fileupload({
    // Uncomment the following to send cross-domain cookies:
    //xhrFields: {withCredentials: true},
    url: '/admin/admin_file_upload_process'
  });

  // Enable iframe cross-domain access via redirect option:
  /*
  $('#fileupload').fileupload(
    'option',
    'redirect',
    window.location.href.replace(/\/[^/]*$/, '/cors/result.html?%s')
  );
  */

  
    // Load existing files:
	/*
    $('#fileupload').addClass('fileupload-processing');
    $.ajax({
      // Uncomment the following to send cross-domain cookies:
      //xhrFields: {withCredentials: true},
      url: $('#fileupload').fileupload('option', 'url'),
      dataType: 'json',
      context: $('#fileupload')[0]
    })
      .always(function() {
        $(this).removeClass('fileupload-processing');
      })
      .done(function(result) {
        $(this)
          .fileupload('option', 'done')
          // eslint-disable-next-line new-cap
          .call(this, $.Event('done'), { result: result });
      });
	  */
  }); 

</script>
	
	
    
    <!-- The XDomainRequest Transport is included for cross-domain file deletion for IE 8 and IE 9 -->
    <!--[if (gte IE 8)&(lt IE 10)]>
      <script src="/includes/jquery-file-upload/js/cors/jquery.xdr-transport.js"></script>
    <![endif]-->	
	  <?php
		
	}


}
?>
