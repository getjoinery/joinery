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
	 * Default validation styling information
	 * Can be overridden by child classes for framework-specific styling
	 */
	public $validate_style_info = 'errorElement: "span",
		errorClass: "text-danger",
		highlight: function(element, errorClass) {
			var name = element.name.replace(/[\[\]]/gi, "");
			$("#"+name).addClass("error");
		},
		unhighlight: function(element, errorClass) {
			var name = element.name.replace(/[\[\]]/gi, "");
			$("#"+name).removeClass("error");
		},
		errorPlacement: function(error, element) {
			error.appendTo(element.parents(".errorplacement").eq(0));
		}';

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

	/**
	 * Generate basic form opening tag
	 * Child classes can override this method completely for framework-specific implementations
	 * @param string $class CSS classes for the form
	 * @param string $method HTTP method (GET, POST)
	 * @param string $action Form action URL
	 * @param string $charset Character encoding (default: UTF-8)
	 * @param string $onsubmit JavaScript onsubmit handler (optional)
	 * @return string HTML for form opening tag
	 */
	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = null) {
		$output = '<form id="' . $this->formid . '" class="' . $class . '" name="' . $this->formid . '" method="' . $method . '" action="' . $action . '" accept-charset="' . $charset . '">';
		return $output;
	}

	/**
	 * Generate basic form closing tag
	 * Child classes can override this method completely for framework-specific implementations
	 * @return string HTML for form closing tag
	 */
	function end_form() {
		return '</form>';
	}

	/**
	 * Generate form validation JavaScript
	 * @param array $validation_rules Array of validation rules
	 * @param string $custom_js Optional custom JavaScript to include
	 * @param boolean $debug Whether to include debug functionality
	 * @return string HTML with JavaScript for form validation
	 */
	function set_validate($validation_rules, $custom_js = NULL, $debug = false) {
		$debugtext = '';
		if ($debug) {
			$debugtext = ',
			invalidHandler: function(event, validator) {
				if (validator.numberOfInvalids()) {
					let errorList = "Please fix the following errors:\\n\\n";
					$.each(validator.errorList, function(index, error) {
						errorList += "- " + error.element.name + ": " + error.message + "\\n";
					});
					alert(errorList);
				}
			}';
		}
		
		$output = '
		<script type="text/javascript">
			$(document).ready(function() {
				// Custom phone number validator
				jQuery.validator.addMethod("phoneUS", function(phone_number, element) {
					phone_number = phone_number.replace(/\\s+/g, "");
					return this.optional(element) || phone_number.length > 9 &&
						phone_number.match(/^(1-?)?(\([2-9]\\d{2}\)|[2-9]\\d{2})-?[2-9]\\d{2}-?\\d{4}$/);
				}, "Please specify a valid phone number");
				
				// Time parsing helper function
				function parseTime(timeStr) {
					var parts = timeStr.split(":");
					return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
				}

				// Custom validator to check that end_time is greater than start_time
				$.validator.addMethod("timeGreaterThan", function(value, element, param) {
					var startVal = $(param).val();
					if (!startVal || !value) {
						return true; // Let the required rule handle empty fields
					}
					return parseTime(value) > parseTime(startVal);
				}, "End time must be after start time.");

				$("#'.$this->formid.'").validate({
					'.$custom_js.'
					rules: {';
		
		$output .= "\r\n";
		foreach ($validation_rules as $name => $rules) {
			$output .= "\t\t\t\t\t\t" . $name . ': {';
			$output .= "\r\n";
			foreach ($rules as $type => $value) {
				$output .= "\t\t\t\t\t\t\t" . $type . ': ' . $value['value'] . ',';
				$output .= "\r\n";
			}
			$output .= "\t\t\t\t\t\t" . '},';
			$output .= "\r\n";
		}
		$output .= "\t\t\t\t\t" . '},';
		$output .= "\r\n";
		
		$output .= "\t\t\t\t\t" . 'messages: {';
		$output .= "\r\n";
		foreach ($validation_rules as $name => $rules) {
			foreach ($rules as $rule_name => $rule_values) {
				if (!empty($rule_values['message'])) {
					$output .= "\t\t\t\t\t\t" . $name . ': {';
					$output .= "\r\n";
					$output .= "\t\t\t\t\t\t\t" . $rule_name . ': ' . $rule_values['message'] . ',';
					$output .= "\r\n";
					$output .= "\t\t\t\t\t\t" . '},';
					$output .= "\r\n";
				}
			}
		}
		$output .= "\t\t\t\t\t" . '},';	
		$output .= "\r\n";							
		$output .= "\t\t\t\t\t" . $this->validate_style_info . $debugtext . '
				});
			});
		</script>';

		return $output;
	}

	/**
	 * Generate a full-featured file upload interface with drag-and-drop
	 * @param array $getvars Optional GET variables to include in upload
	 * @param boolean $delete Whether to allow deletion
	 * @param boolean $checkall Whether to check all files
	 * @return void Outputs HTML directly
	 */
	static function file_upload_full($getvars=NULL, $delete=FALSE, $checkall=FALSE){
		$getargs = '';
		if($getvars){
			foreach($getvars as $getvar=>$getval){
				$getargs.= '<input type="hidden" name="'.$getvar.'" value="'.$getval.'"/>';
			}
		}

		$settings = Globalvars::get_instance();
		$allowed_extensions = $settings->get_setting('allowed_upload_extensions');
		$accept_attr = '.' . str_replace(',', ',.', $allowed_extensions);

		// Get actual PHP upload limits
		$upload_max = ini_get('upload_max_filesize');
		$post_max = ini_get('post_max_size');
		// Convert to bytes to compare
		function parseSize($size) {
			$unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
			$size = preg_replace('/[^0-9\.]/', '', $size);
			if ($unit) {
				return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
			} else {
				return round($size);
			}
		}
		$upload_max_bytes = parseSize($upload_max);
		$post_max_bytes = parseSize($post_max);
		$max_size = min($upload_max_bytes, $post_max_bytes);
		$max_size_display = round($max_size / (1024 * 1024)) . 'MB';
	?>
		<!-- File Drop Zone -->
		<div id="file-drop-zone" class="file-drop-zone" style="border: 2px dashed #ccc; border-radius: 5px; padding: 20px; text-align: center; margin-bottom: 20px; background-color: #f9f9f9; transition: all 0.3s ease; cursor: pointer;">
			<div style="font-size: 48px; color: #999; margin-bottom: 10px;">☁️</div>
			<h3 style="color: #666; margin: 10px 0;">Drop files here or click to browse</h3>
			<p style="color: #999; margin: 10px 0;">Maximum file size: <?php echo $max_size_display; ?> | Allowed types: <?php echo strtoupper(str_replace(',', ', ', $allowed_extensions)); ?></p>
			<input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" style="display: none;">
			<button type="button" id="browse-btn" class="button primary" style="margin-top: 10px;">
				📁 Browse Files
			</button>
		</div>

		<!-- Upload Controls -->
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
			<div>
				<button type="button" id="upload-all-btn" class="button primary" disabled>
					⬆️ Upload All
				</button>
				<button type="button" id="clear-all-btn" class="button secondary" style="margin-left: 10px;" disabled>
					🗑️ Clear All
				</button>
			</div>
			<div id="overall-progress" style="display: none; flex-grow: 1; margin-left: 20px;">
				<progress id="overall-progress-bar" value="0" max="100" style="width: 100%; height: 20px;">0%</progress>
			</div>
		</div>

		<!-- Files Table -->
		<div style="overflow-x: auto;">
			<table style="width: 100%; border-collapse: collapse;">
				<thead>
					<tr style="background-color: #f5f5f5;">
						<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">📄 File Name</th>
						<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">📊 Size</th>
						<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">ℹ️ Status</th>
						<th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">⚙️ Actions</th>
					</tr>
				</thead>
				<tbody id="files-list">
					<tr id="no-files-message">
						<td colspan="4" style="text-align: center; padding: 40px; color: #999;">
							<div style="font-size: 32px; margin-bottom: 10px;">📤</div>
							<div>No files selected</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if($getargs): ?>
		<form id="hidden-form-data" style="display: none;">
			<?php echo $getargs; ?>
		</form>
		<?php endif; ?>
		<script>
		$(function() {
			'use strict';

			let selectedFiles = [];

			// Get allowed file extensions from server setting
			const allowedExtensions = '<?php echo $allowed_extensions; ?>';
			const allowedTypes = new RegExp('\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
			const maxFileSize = <?php echo $max_size; ?>; // Maximum file size in bytes

			// DOM elements
			const $dropZone = $('#file-drop-zone');
			const $fileInput = $('#file-input');
			const $browseBtn = $('#browse-btn');
			const $uploadAllBtn = $('#upload-all-btn');
			const $clearAllBtn = $('#clear-all-btn');
			const $filesList = $('#files-list');
			const $noFilesMessage = $('#no-files-message');
			const $overallProgress = $('#overall-progress');
			const $progressBar = $('#overall-progress-bar');

			// File size formatter
			function formatFileSize(bytes) {
				if (bytes === 0) return '0 Bytes';
				const k = 1024;
				const sizes = ['Bytes', 'KB', 'MB', 'GB'];
				const i = Math.floor(Math.log(bytes) / Math.log(k));
				return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
			}

			// Generate unique ID for each file
			function generateFileId() {
				return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
			}

			// Get file icon based on extension
			function getFileIcon(filename) {
				const ext = filename.split('.').pop().toLowerCase();
				const iconMap = {
					'pdf': '📕',
					'doc': '📘',
					'docx': '📘',
					'xls': '📗',
					'xlsx': '📗',
					'jpg': '🖼️',
					'jpeg': '🖼️',
					'png': '🖼️',
					'gif': '🖼️',
					'mp3': '🎵',
					'mp4': '🎬',
					'm4a': '🎵'
				};
				return iconMap[ext] || '📄';
			}

			// Add files to the list
			function addFiles(files) {
				Array.from(files).forEach(file => {
					// Validate file type using server setting
					if (!allowedTypes.test(file.name)) {
						showToast('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions, 'error');
						return;
					}

					// Validate file size using server limit
					if (file.size > maxFileSize) {
						showToast('File too large: ' + file.name + '. Maximum size: <?php echo $max_size_display; ?>', 'error');
						return;
					}

					const fileId = generateFileId();
					const fileObj = {
						id: fileId,
						file: file,
						status: 'pending'
					};

					selectedFiles.push(fileObj);
					renderFileRow(fileObj);
				});

				updateUI();
			}

			// Render a file row in the table
			function renderFileRow(fileObj) {
				$noFilesMessage.hide();

				const fileIcon = getFileIcon(fileObj.file.name);
				const $row = $(`
					<tr data-file-id="${fileObj.id}" class="file-row" style="border-bottom: 1px solid #eee;">
						<td style="padding: 10px;">
							<div style="display: flex; align-items: center;">
								<span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
								<span class="file-name">${fileObj.file.name}</span>
							</div>
						</td>
						<td class="file-size" style="padding: 10px;">${formatFileSize(fileObj.file.size)}</td>
						<td class="file-status" style="padding: 10px;">
							<span style="padding: 2px 8px; background: #6c757d; color: white; border-radius: 3px; font-size: 12px;">Ready to upload</span>
						</td>
						<td class="file-actions" style="padding: 10px;">
							<button type="button" class="button small upload-single-btn" title="Upload this file" style="padding: 4px 8px; font-size: 12px;">
								⬆️
							</button>
							<button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
								❌
							</button>
						</td>
					</tr>
				`);

				$filesList.append($row);
			}

			// Update UI state
			function updateUI() {
				const hasFiles = selectedFiles.length > 0;
				const pendingFiles = selectedFiles.filter(f => f.status === 'pending').length;

				$uploadAllBtn.prop('disabled', pendingFiles === 0);
				$clearAllBtn.prop('disabled', !hasFiles);

				if (!hasFiles) {
					$noFilesMessage.show();
				}

				// Update button text with count
				if (pendingFiles > 0) {
					$uploadAllBtn.html(`⬆️ Upload All (${pendingFiles})`);
				} else {
					$uploadAllBtn.html('⬆️ Upload All');
				}
			}

			// Show toast notification
			function showToast(message, type = 'info') {
				console.log(type + ': ' + message);
				// Simple alert for now - can be enhanced with proper toast notifications
				if (type === 'error') {
					alert('Error: ' + message);
				}
			}

			// Upload a single file
			function uploadFile(fileObj) {
				return new Promise((resolve, reject) => {
					const formData = new FormData();
					formData.append('files[]', fileObj.file);

					// Add any additional form data
					$('#hidden-form-data input').each(function() {
						formData.append($(this).attr('name'), $(this).val());
					});

					const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
					const $status = $row.find('.file-status');
					const $actions = $row.find('.file-actions');

					// Update UI to uploading state
					$status.html('<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading...</span>');
					$actions.html(`
						<div style="display: flex; align-items: center;">
							<progress value="0" max="100" style="width: 60px; height: 20px; margin-right: 8px;">0%</progress>
							<span style="color: #666; font-size: 12px;">0%</span>
						</div>
					`);

					// Create XMLHttpRequest for progress tracking
					const xhr = new XMLHttpRequest();

					xhr.upload.addEventListener('progress', function(e) {
						if (e.lengthComputable) {
							const progress = Math.round((e.loaded / e.total) * 100);
							$actions.find('progress').val(progress).text(progress + '%');
							$actions.find('span').text(progress + '%');
							$status.html(`<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading ${progress}%</span>`);
						}
					});

					xhr.addEventListener('load', function() {
						if (xhr.status === 200) {
							try {
								const response = JSON.parse(xhr.responseText);
								if (response.files && response.files[0]) {
									const file = response.files[0];
									console.log('Upload response file object:', file); // Debug log
									if (file.url) {
										// Success
										$status.html('<span style="padding: 2px 8px; background: #28a745; color: white; border-radius: 3px; font-size: 12px;">✓ Upload successful</span>');
										$actions.html(`
											<a href="${file.url}" target="_blank" class="button small success" title="Download file" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">
												⬇️
											</a>
											<button type="button" class="button small danger remove-file-btn" title="Remove from list" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
												❌
											</button>
										`);

										// Make filename clickable if we have a file ID
										console.log('Checking for file_id:', file.file_id); // Debug log
										if (file.file_id) {
											const $nameElement = $row.find('.file-name');
											const fileName = $nameElement.text();
											const fileIcon = getFileIcon(fileName);

											console.log('Making filename clickable:', fileName, 'with ID:', file.file_id); // Debug log

											$nameElement.parent().html(`
												<div style="display: flex; align-items: center;">
													<span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
													<a href="/admin/admin_file?fil_file_id=${file.file_id}" style="color: #0066cc; text-decoration: none;">${fileName}</a>
												</div>
											`);
										} else {
											console.log('No file_id found in response'); // Debug log
										}

										fileObj.status = 'completed';
										fileObj.url = file.url;
										fileObj.file_id = file.file_id;
										resolve(fileObj);
									} else if (file.error) {
										throw new Error(file.error);
									}
								} else {
									throw new Error('Invalid response format');
								}
							} catch (e) {
								reject(e);
							}
						} else {
							reject(new Error('Upload failed with status: ' + xhr.status));
						}
					});

					xhr.addEventListener('error', function() {
						reject(new Error('Network error during upload'));
					});

					xhr.open('POST', '/admin/admin_file_upload_process');
					xhr.send(formData);
				}).catch(error => {
					// Handle error
					const $row = $(`.file-row[data-file-id="${fileObj.id}"]`);
					const $status = $row.find('.file-status');
					const $actions = $row.find('.file-actions');

					$status.html(`<span style="padding: 2px 8px; background: #dc3545; color: white; border-radius: 3px; font-size: 12px;">⚠️ Error</span>`);
					$actions.html(`
						<button type="button" class="button small upload-single-btn" title="Retry upload" style="padding: 4px 8px; font-size: 12px;">
							🔄
						</button>
						<button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
							❌
						</button>
					`);
					fileObj.status = 'error';
					showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
					throw error;
				});
			}

			// Event Handlers
			$browseBtn.on('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$fileInput[0].click(); // Use native click instead of jQuery
			});

			$fileInput.on('change', function() {
				if (this.files.length > 0) {
					addFiles(this.files);
					this.value = ''; // Reset input
				}
			});

			// Drag and drop styling
			$dropZone.on('dragover dragenter', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css({'border-color': '#007bff', 'background-color': '#e7f3ff'});
			});

			$dropZone.on('dragleave', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css({'border-color': '#ccc', 'background-color': '#f9f9f9'});
			});

			$dropZone.on('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css({'border-color': '#ccc', 'background-color': '#f9f9f9'});

				const files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					addFiles(files);
				}
			});

			// Hover effect
			$dropZone.on('mouseenter', function() {
				$(this).css('background-color', '#e7f3ff');
			}).on('mouseleave', function() {
				$(this).css('background-color', '#f9f9f9');
			});

			// Upload all files
			$uploadAllBtn.on('click', async function() {
				const pendingFiles = selectedFiles.filter(f => f.status === 'pending');
				if (pendingFiles.length === 0) return;

				$overallProgress.show();
				$uploadAllBtn.prop('disabled', true);

				let completed = 0;
				const total = pendingFiles.length;

				for (const fileObj of pendingFiles) {
					try {
						await uploadFile(fileObj);
						completed++;
						const progress = Math.round((completed / total) * 100);
						$progressBar.val(progress);
					} catch (error) {
						console.error('Upload failed:', error);
						completed++; // Count errors as completed for progress
						const progress = Math.round((completed / total) * 100);
						$progressBar.val(progress);
					}
				}

				setTimeout(() => {
					$overallProgress.hide();
					$progressBar.val(0);
					updateUI();
				}, 1000);
			});

			// Clear all files
			$clearAllBtn.on('click', function() {
				if (confirm('Are you sure you want to clear all files?')) {
					selectedFiles = [];
					$filesList.find('.file-row').remove();
					updateUI();
				}
			});

			// Event delegation for dynamic buttons
			$filesList.on('click', '.upload-single-btn', function() {
				const fileId = $(this).closest('.file-row').data('file-id');
				const fileObj = selectedFiles.find(f => f.id === fileId);
				if (fileObj && (fileObj.status === 'pending' || fileObj.status === 'error')) {
					uploadFile(fileObj).then(() => {
						updateUI();
					}).catch(() => {
						updateUI();
					});
				}
			});

			$filesList.on('click', '.remove-file-btn', function() {
				const $row = $(this).closest('.file-row');
				const fileId = $row.data('file-id');

				// Remove from array
				selectedFiles = selectedFiles.filter(f => f.id !== fileId);

				// Remove from DOM with animation
				$row.fadeOut(300, function() {
					$(this).remove();
					updateUI();
				});
			});

			// Click to browse anywhere in drop zone (except on buttons)
			$dropZone.on('click', function(e) {
				// Only trigger if clicking directly on the drop zone, not on child elements
				if (e.target === this) {
					e.preventDefault();
					e.stopPropagation();
					$fileInput[0].click(); // Use native click
				}
			});
		});
		</script>

		<style>
		#file-drop-zone:hover {
			background-color: #e7f3ff !important;
			border-color: #007bff !important;
		}

		.file-row {
			transition: background-color 0.2s ease;
		}

		.file-row:hover {
			background-color: #f8f9fa;
		}

		.button {
			cursor: pointer;
			border: 1px solid #ccc;
			background: #f5f5f5;
			padding: 8px 16px;
			border-radius: 4px;
			font-size: 14px;
		}

		.button.primary {
			background: #007bff;
			color: white;
			border-color: #0056b3;
		}

		.button.secondary {
			background: #6c757d;
			color: white;
			border-color: #545b62;
		}

		.button.success {
			background: #28a745;
			color: white;
			border-color: #1e7e34;
		}

		.button.danger {
			background: #dc3545;
			color: white;
			border-color: #bd2130;
		}

		.button.small {
			padding: 4px 8px;
			font-size: 12px;
		}

		.button:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		progress {
			border-radius: 4px;
		}
		</style>

	<!-- Modern browsers handle CORS natively, no IE8/9 support needed -->
	  <?php

	}

}