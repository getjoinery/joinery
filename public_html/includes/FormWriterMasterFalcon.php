<?php
require_once('DbConnector.php');
require_once('Globalvars.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterMaster {

	public static $tab_count = 0;

	protected $formid;
	protected $captcha_public;
	protected $captcha_private;
	public $validate_style_info = '
							/* ignore: ":hidden:not(input[type=\'checkbox\'], input[type=\'radio\'])", */
							errorElement: "p",
							errorClass: "text-danger",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"").addClass("is-invalid");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"").removeClass("is-invalid");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING
	
	protected $text_container_class = 'mb-3';
	protected $text_label_class = 'col-sm-2 col-form-label';
	protected $text_input_class = 'form-control-plaintext outline-none';
	
	protected $textinput_container_class = 'mb-3';
	protected $textinput_label_class = 'form-label';
	protected $textinput_input_class = 'form-control';
	
	protected $textbox_container_class = 'form-label';
	protected $textbox_textarea_class = 'form-control';

	protected $checkboxinput_container_class = 'form-check';
	protected $checkboxinput_input_class = 'form-check-input';
	protected $checkboxinput_label_class = 'form-check-label';
	
	protected $checkboxList_container_class = 'form-check';
	protected $checkboxList_input_class = 'form-check-input';
	protected $checkboxList_label_class = 'form-check-label';		
	
	protected $dropinput_container_class = 'mb-3';
	protected $dropinput_label_class = 'form-label';
	protected $dropinput_select_class = 'form-select';
	
	protected $button_primary_class = 'btn-primary';
	protected $button_secondary_class = 'btn-secondary';

	function __construct($formid='form1', $secure=FALSE, $use_tabindex=FALSE){
		$this->formid = $formid;

		$settings = Globalvars::get_instance();

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
			$output .= $this->textinput("Type '".strtolower($correct_answer)."' into this field (to prove you are human)", "antispam_question", "ctrlHolder", 30, '', "", 255, ""); 
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
			$output .= $this->textinput($label, $name, "ctrlHolder", 30, '', "", 255, ""); 
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
			$output .= '<div id="captcha_container" class=" errorplacement ">';
			$output .= "<script src='https://www.hCaptcha.com/1/api.js' async defer></script>";
			$output .= '<div id="captcha_field" class="h-captcha" data-callback="enableBtn" data-sitekey="'.$settings->get_setting('hcaptcha_public').'"></div>';
			$output .= '</div>';
		}
		else if($settings->get_setting('captcha_public')){
			//GOOGLE
			$output .= '<div id="captcha_container" class=" errorplacement ">';
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

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'"><fieldset>';
		return $output;
	}

	function end_form(){
		return '</fieldset></form>';
	}
	
	//DEPRECATED
	function start_buttons($class = '') {
		return '';
	}


	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_button($label='Submit', $link, $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '';
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}
		
		if($width == 'full'){
			$output .= '<div class="d-grid gap-2">';
		}
		
		
		$output .= '<a href="'.$link.'"><button type="button" class="btn me-1 mb-1 '.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button></a>';
		if($width == 'full'){
			$output .= '</div>';
		}
		return $output;
	}

	//STYLE IS 'primary' or 'secondary'
	//WIDTH IS 'standard' or 'full'
	function new_form_button($label='Submit', $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '';
		if($style == 'primary'){
			$class = $this->button_primary_class . ' ' . $class;
		}
		else{
			$class = $this->button_secondary_class . ' ' . $class;
		}

		if($width == 'full'){
			$output .= '<div class="d-grid gap-2">';
		}	
		
		$output = '<button type="submit" class="btn me-1 mb-1 '.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= '>';
		$output .= $label.'</button>';
		if($width == 'full'){
			$output .= '</div>';
		}
		return $output;
	}

	
	function end_buttons() {
		return '';
	}
	
	function set_validate($validation_rules, $custom_js = NULL, $debug=false){
		$debugtext = '';
		if($debug){
			$debugtext = ',
			
        invalidHandler: function(event, validator) {
            if (validator.numberOfInvalids()) {
                let errorList = "Please fix the following errors:\n\n";
                $.each(validator.errorList, function(index, error) {
                    errorList += "- " + error.element.name + ": " + error.message + "\n";
                });
                alert(errorList);
            }
        }';
		}
		
		$output = '
		<script type="text/javascript">
			$(document).ready(function() {
				jQuery.validator.addMethod("phoneUS", function(phone_number, element) {
				    phone_number = phone_number.replace(/\s+/g, "");
					return this.optional(element) || phone_number.length > 9 &&
						phone_number.match(/^(1-?)?(\([2-9]\d{2}\)|[2-9]\d{2})-?[2-9]\d{2}-?\d{4}$/);
				}, "Please specify a valid phone number");
				
				function parseTime(timeStr) {
					var parts = timeStr.split(":");
					return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
				}

				// Custom validator to check that end_time is greater than start_time.
				$.validator.addMethod("timeGreaterThan", function(value, element, param) {
					// Get the start time field value
					var startVal = $(param).val();
					// Only validate if both values are present.
					if (!startVal || !value) {
						return true; // Let the required rule handle empty fields.
					}
					return parseTime(value) > parseTime(startVal);
				}, "End time must be after start time.");

					$("#'.$this->formid.'").validate({
							'.$custom_js.'
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
							$output .=  $this->validate_style_info . $debugtext .'
							});';

			$output .= '});
		  </script>';
	
		return $output;
	
	}
	



	/*******************************
	GENERATES A LABEL WITH TEXT, NOT EDITABLE

	INPUT: 	id (str)
			label (str)
			value (value)
			class (str)		
			layout ('default', 'horizontal', '')
	********************************/
	function text($id, $label, $value, $class, $layout='default') {
		if($layout == 'default'){
			$output = '
			<div id="'.$id.'_container" class="'.$this->text_container_class.' errorplacement">
			<label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
			<input class="'.$this->text_input_class.'" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
			</div>';
		}
		else{
			$output = '
			<div class="'.$this->text_container_class.' row">
			  <label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
			  <div class="col-sm-10">
				<input class="'.$this->text_input_class.'" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
				<div class="mb-3 row"></div>
			  </div>
			</div>';						
		}
		return $output;
	}



	/*******************************
	GENERATES A TEXT INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			size(integer)
			value (value)
			hint (string appears inside)
			maxlength (1-255)
			readonly (true or false)
			autocomplete (true or false)
			formhint (string that appears at the beginning of the field)
			type (text, date, password, file)
			layout ('default', 'horizontal', '')
	********************************/
	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='text', $layout='default') {

		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		if($layout == 'default'){
			$containerclass = $this->textinput_container_class;
			$labelclass = $this->textinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->textinput_container_class;
			$labelclass = $this->textinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}
		
		if($value){
			$value = str_replace('"', '&quot;', $value );
		}
		
		
		if($hint){ 
			$hint_text = 'placeholder="'.$hint.'" onfocus="this.placeholder = \'\'" onblur="this.placeholder = \''.$hint.'\'"';
		}	
		
		
		$output = '<div id="'.$id . '_container" class="errorplacement '.$containerclass.'">';
		

		
		if(!$autocomplete){
			$autocomplete = 'autocomplete="off"';
		}
		else{
			$autocomplete = '';
		}




		
		if($layout == 'horizontal'){
			$output .= '<div class="row mb-3">';
			if($label){
				$output .= '<label for="'.$id.'" class="col-sm-2 col-form-label">'.$label.'</label>';
			} 
			$output .= '<div class="col-sm-10">';
	
	
			if($formhint){
				$output .= '<div class="input-group">
				<div class="input-group-text">'.$formhint.'</div>';
			}

			$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="'.$this->textinput_input_class.' '.$class.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/>';
			
			if($formhint){
				$output .= '</div>';
			}

			$output .= '</div> </div>';
		}
		else{
			if($label){
				$output .= '<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';
			} 

			if($formhint){
				$output .= '<div class="input-group">
				<div class="input-group-text">'.$formhint.'</div>';
			}
			
			$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="'.$this->textinput_input_class.' '.$class.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/>';
			
			if($formhint){
				$output .= '</div>';
			}
			
		}

		$output .= '</div>';
		
		return $output;

	}
	

	/*******************************
	GENERATES A FILE INPUT

	SEE TEXTINPUT
	********************************/		
	function fileinput($label, $id, $class, $size, $hint, $layout='default') {
		return $this->textinput($label, $id, $class, $size, NULL, $hint, 255, '', FALSE, FALSE, 'file', $layout);
	}

	/*******************************
	GENERATES A PASSWORD INPUT

	SEE TEXTINPUT
	********************************/	
	function passwordinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="", $layout='default') {
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, TRUE, FALSE, 'password', $layout);
	}
	

	/*******************************
	GENERATES A TEXT BOX

	INPUT: 	label (str)
			id (str)
			class (str)
			rows (integer)
			cols (integer)
			value (value)
			hint (string)
			htmlmode ('yes', 'no')
	********************************/
	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
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
			.trumbowyg-box,
			.trumbowyg-editor,
			.trumbowyg-textarea {
				height: 500px;
			}

			.trumbowyg-box.trumbowyg-fullscreen,
			.trumbowyg-box.trumbowyg-fullscreen .trumbowyg-editor,
			.trumbowyg-box.trumbowyg-fullscreen .trumbowyg-textarea {
				height: 100%;
			}
			/*
			.trumbowyg-box {
				max-height: 500px;
			}
			*/
			</style>
			";
	
	
			$output .= '
				<div id="'.$id.'_container" class="mb-3 errorplacement">
					<label class="'.$this->textbox_container_class.'" for="'.$id.'">'.$label.'</label>
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.' html_editable" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>
				</div>';
		}
		else{
			$output .= '
				<div id="'.$id.'_container" class="mb-3 errorplacement">
					<label class="'.$this->textbox_container_class.'" for="'.$id.'">'.$label.'</label>
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.'" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>
				</div>';
		}
				
		if($formhint){
			$output .= '<div id="'.$id.'_hint"><small>'.$formhint.'</small></div>';
		}

		
		return $output;
	}

	function hiddeninput($id, $value) {
		return '<input type="hidden" class="hidden" name="'.$id.'" id="'.$id.'" value="'.$value.'" />';
	}
	

	/*******************************
	GENERATES A CHECKBOX

	INPUT: 	label (str)
			id (str)
			class (str)
			align(unused)
			value (value)
			truevalue (value if checked)
			hint (string)
			layout ('default', 'horizontal', '')
			TODO:  Remove align or use it for other things
	********************************/
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint, $layout='default'){

		//FORMS ARE EITHER HORIZONTAL OR DEFAULT
		if($layout == 'default'){
			$containerclass = $this->checkboxinput_container_class;
			$labelclass = $this->checkboxinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->checkboxinput_container_class ;
			$labelclass = $this->checkboxinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}

		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}




		if($layout == 'horizontal'){
			return '<div class="row mb-3">
						<div class="col-form-label col-sm-2 pt-0">'.$label.'</div>
						<div class="col-sm-10">
						  <div class="'.$containerclass.' errorplacement">
							<div id="'.$id.'_container" class="'.$containerclass.'">
								<input class="'.$this->checkboxinput_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
								<label for="'.$id.'" class="'.$labelclass.'"></label>                  
							</div>
						   </div>
						</div>
					</div>';
		}
		else{
			return '<div class=" errorplacement">
					<div id="'.$id.'_container" class="'.$containerclass.'">
						<input class="'.$this->checkboxinput_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
						<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>                  
					</div>
				   </div>';			
		}



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
	function checkboxList($label, $id, $class, $optionvals, $checkedvals=array(), $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
		$output = '';

		if(empty($optionvals)){
			return false;
		}

		if(!is_array($checkedvals)){
			$checkedvals = array();
		}

		if($type=='checkbox'){
			$class= $this->checkboxList_input_class;
		}
		else if($type=='radio'){
			$type='radio';
			$class= $this->checkboxList_input_class;
			
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

		$output .= '<div id="'.$id.'_container" class="errorplacement">';
		$output .= '<label for="'.$id.'">'.$label.'</label>';
		//$output .=  '<fieldset style="padding:30px; margin:0px;">';
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
					//$output .= '<label for="'.$uniqid.'">'.$key.' (checked, read only)</label><br>';
				}
				else{
					$output .= $this->hiddeninput($id.'[]', '');	
					//$output .= '<label for="'.$uniqid.'">'.$key.' (unchecked, read only)</label><br>';
				}
				
				$output .= '
						<div class="'.$this->checkboxList_container_class.'">
							<input class="'.$class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' disabled="disabled" />
							<label class="'.$this->checkboxList_label_class.'" for="'.$uniqid.'">'.$key.'</label>                  
						</div>
					   ';				
				
				
			}
			else{

				$output .= '
						<div class="'.$this->checkboxList_container_class.'">
							<input class="'.$class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' '.$disabled.' />
							<label class="'.$this->checkboxList_label_class.'" for="'.$uniqid.'">'.$key.'</label>                  
						</div>
					   ';
			}
		}
		//$output .=  '</fieldset>';
		$output .=  '</div>';
		
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
		$checkedvals = array($checkedval);
		return $this->checkboxList($label, $id, $class, $optionvals, $checkedvals, $disabledvals, $readonlyvals, $hint, 'radio');
		
	}


	/*******************************
	GENERATES A COMBO DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			value (HH:MM PM)
			hint (string)
			layout ('default', 'horizontal')
	********************************/	
	function datetimeinput2($label, $id, $class, $value, $hint, $readonly=false, $formhint=FALSE, $layout='default'){
	
		$value = trim($value);
		$value = str_replace(' ', 'T', $value);
			
		//$formhint = 'MM/DD/YYYY HH:MM AM/PM';

		return $this->textinput($label, $id, $class, NULL, $value, $hint, 255, $readonly, false, $formhint, 'datetime-local', $layout);
		
	}	

	/*******************************
	GENERATES A DATEPICKER

	SEE TEXTINPUT
	********************************/	
	function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $layout='default' ){
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, $autocomplete, $formhint, 'date', $layout);
	}
	
	/*******************************
	GENERATES A DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			value (HH:MM PM)
			hint (string)
			layout ('default', 'horizontal')
	********************************/	
	function timeinput($label, $id, $class, $value, $hint, $layout='default') {
		$class = $class . ' timepicker';
		$output = '';
		$output .= '
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
		</script>';
		
		$output .= $this->textinput($label, $id, $class, $size, $value, $hint, 10, $readonly, TRUE, FALSE, 'text', $layout);
		return $output;

	
	}



	/*******************************
	GENERATES A DATE AND A TIME INPUT FIELD

	INPUT: 	label (str)
			id (str)
			class (str)
			inputdatetime (datetime)
			hint (string)
			timehint (unused)
			datehint (unused)
			layout ('default', 'row', 'horizontal')
			
			*DOES NOT CONVERT FOR TIMEZONES
	********************************/
	function datetimeinput($label, $id, $class, $inputdatetime, $hint, $timehint, $datehint, $layout='default') {
		
			//REMOVE TIME OR DATE FROM LABEL
			$label = preg_replace('/\s*(time|date)$/i', '', trim($label));
			if(!is_null($inputdatetime) && $inputdatetime != ''){
				$session = SessionControl::get_instance();
				$inputdate = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'Y-m-d');
				$inputtime = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'g:i a');
			}
			else{
				$inputdate = '';
				$inputtime = '';
			}
			
			$output = '';
			if($layout == 'default'){
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'default');
				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'default'); 
			}
			else if($layout == 'horizontal'){
				$output .= '<div class="row gy-2 gx-3">
					<div class="col-auto">';
				
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'default');
			
		
				$output .= '  </div>
					<div class="col-auto">';
			
				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'default'); 
			
				$output .= '</div></div>';
			}
			else if($layout = 'row'){
				$output .= $this->dateinput($label.' date', $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, 'horizontal');

				$output .= $this->timeinput($label.' time', $id.'_time', NULL, $inputtime, NULL, 'horizontal'); 				
			}
			return $output;

	}



	/*******************************
	GENERATES A DROPDOWN GIVEN AN ARRAY OF VALUES

	INPUT: 	label (str)
			id (str)
			class (str)
			optionvals(associative array, 'label'=>'value')
			input (value selected)
			hint (string)
			showdefault (if true, it shows "choose one")
			forcestrict (selects value only if exactly equal ===)
			imagedropdown (special case for images)
			layout ('default', 'horizontal', '')
	********************************/
	function dropinput($label, $id, $class, &$optionvals, $input, $hint, $showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE, $imagedropdown=FALSE, $layout='default') {
		
		$output = '';
		
		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		if($layout == 'default'){
			$containerclass = $this->dropinput_container_class;
			$labelclass = $this->dropinput_label_class;
		}
		else if($layout == 'horizontal'){
			$containerclass = $this->dropinput_container_class;
			$labelclass = $this->dropinput_label_class;
		}
		else{
			$labelclass = '';
			$containerclass = '';
		}
		
		if($ajaxendpoint){
			$output .= '<link href="/includes/select2.min.css" rel="stylesheet" />
			<script src="/includes/select2.full.min.js"></script>';
		
			$output .= '<script type="text/javascript">
			$(document).ready(function() {
			  $("#'.$id.'").select2({
				placeholder: "None",
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
		


				
			
						
			if($layout == 'horizontal'){
				$output .= '<div id="'.$id.'_container" class="'.$containerclass.' errorplacement">
								<div class="row mb-3">
									<label for="'.$id.'" class="col-sm-2 col-form-label">'.$label.'</label>
									<div class="col-sm-10">';

			}
			else{
				$output .= '<div id="'.$id.'_container" class="'.$containerclass.' errorplacement">
								<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';				
			}
						
			$output .= '<select name="'.$id.'" id="'.$id.'" class="'.$this->dropinput_select_class.'">';
								


			if($showdefault){
				if($showdefault === true){
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">Choose One</option>';
					}
					else{
						$output .= '<option value="">Choose One</option>';
					}
				}
				else{
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">'.$showdefault.'</option>';
					}
					else{
						$output .= '<option value="">'.$showdefault.'</option>';
					}						
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
			$output .= '</select>';

			if($layout == 'horizontal'){
				$output .= '</div></div>';
			}			
						
			$output .= '</div>';	
		

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
			
			$output .= '<h5>'.$label.'</h5><div id="'.$id.'_container" class="errorplacement image-dropdown">';
								

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
