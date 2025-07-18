<?php
require_once('FormWriterBase.php');
require_once('DbConnector.php');
require_once('Globalvars.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterMasterUIkit extends FormWriterBase {
	public $validate_style_info = 'errorElement: "p",
							errorClass: "error help-block text-red uk-form-danger",
							highlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"_container").addClass("uk-form-danger");
							  },
							  unhighlight: function(element, errorClass) {
								//REMOVE BRACKETS FOR CHECKBOX LISTS
								var name = element.name.replace(/[\[\]]/gi, "");
								  $("#"+name+"_container").removeClass("uk-form-danger");
							  },
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING
	protected $fileinput_container_class = '';
	protected $fileinput_input_class = '';
	
	protected $text_container_class = 'uk-margin';
	protected $text_label_class = '';
	
	protected $textinput_container_class_horizontal = 'row';
	protected $textinput_label_class_horizontal = 'uk-margin col-sm-2 col-form-label';
	protected $textinput_container_class = 'uk-margin';
	protected $textinput_label_class = '';
	protected $textinput_input_class = 'uk-input';
	
	protected $textbox_container_class = 'uk-margin';
	protected $textbox_textarea_class = 'uk-textarea';

	protected $checkboxinput_container_class = 'ctrlHolder uk-margin uk-grid-small uk-child-width-auto uk-grid';
	protected $checkboxinput_input_class = 'uk-checkbox';
	
	protected $checkboxList_container_class = 'uk-margin';
	protected $checkboxList_wrapper_class = 'uk-margin uk-grid-small uk-child-width-auto uk-grid';
	protected $checkboxList_input_class_checkbox = 'uk-checkbox';	
	protected $checkboxList_input_class_radio = 'uk-radio';	
	
	protected $timeinput_container_class = 'uk-margin';
	protected $timeinput_input_class = 'uk-input timepicker';
	
	protected $dropinput_container_class = 'uk-margin';
	protected $dropinput_select_class = 'uk-select';




	
	
	

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'"><fieldset class="uk-fieldset">';
		return $output;
	}

	function end_form(){
		return '</fieldset></form>';
	}
	
	//DEPRECATED
	function start_buttons($class = '') {
		return '<div class="row '.$class.'">';
	}

	function new_form_button($label='Submit', $class='uk-button uk-button-primary', $id=NULL) {
		
		$output = '<button type="submit" class="'.$class.'"';
		if($id != '' && !is_null($id)){
			$output .= ' id="'.$id.'"';
		}
		$output .= ' primaryAction">';
		$output .= '<span>'. $label.'</span></button>';
		return $output;
	}

	
	function end_buttons() {
		return '</div>';
	}
	
	


	function fileinput($label, $id, $class, $size, $hint) {
		$output = '
		<div id="'.$id.'_container" class="'.$this->fileinput_container_class.' errorplacement">
		<label for="'.$id.'">'.$label.'</label>
		<input name="'.$id.'" id="'.$id.'"  size="'.$size.'" type="file" class="'.$this->fileinput_input_class.'" />
		</div>';
		return $output;
	}


	function passwordinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="") {
		
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, TRUE, FALSE, 'password');
	}

	function text($id, $label, $value, $class) {
		$output = '
		<div id="'.$id.'_container" class="'.$this->text_container_class.' errorplacement">
		<label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
		<span>'.$value.'</span>
		</div>';
		return $output;
	}




	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='text') {

		//FORMS ARE EITHER HORIZONTAL OR REGULAR
		$layout = '';
		if($layout == 'horizontal'){
			$labelclass = $this->textintput_label_class_horizontal;
			$containerclass = $this->textintput_container_class_horizontal;
		}
		else{
			$labelclass = $this->textintput_label_class;
			$containerclass = $this->textintput_container_class;
		}
		
		if($value){
			$value = str_replace('"', '&quot;', $value );
		}
		
		
		if($hint){ 
			$hint_text = 'placeholder="'.$hint.'" onfocus="this.placeholder = \'\'" onblur="this.placeholder = \''.$hint.'\'"';
		}	
		
		
		$output = '<div id="'.$id . '_container" class="errorplacement '.$containerclass.'">';
		
		if($label){
			$output .= '<label for="'.$id.'" class="'.$labelclass.'">'.$label.'</label>';
		} 
		
		if(!$autocomplete){
			$autocomplete = 'autocomplete="off"';
		}
		else{
			$autocomplete = '';
		}

		$output .= '<input name="'.$id.'" id="'.$id.'"'.$autocomplete.' value="'.$value.'" size="'.$size.'" type="'.$type.'" class="uk-input '.$class.'" '.$hint_text.' maxlength="'.$maxlength.'" '.$readonly.$this->_get_next_tab_index().'/>';
		
		if($formhint){
			$output .= '<div id="'.$id.'_hint" class="'.$this->textinput_input_class.'"><small>'.$formhint.'</small></div>';
		}

		$output .= '</div>';
		
		return $output;

	}
	



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
			
			$output .= '<div id="'.$id.'_container" class=" errorplacement">
			<label for="'.$id.'">'.$label.'</label>
			
				<textarea name="'.$id.'" id="'.$id.'" class="html_editable" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>';
		}
		else{
			$output .= '<div id="'.$id.'_container" class="'.$this->textbox_container_class.' errorplacement">
				<label for="'.$id.'">'.$label.'</label>
				
					<textarea name="'.$id.'" id="'.$id.'" class="'.$this->textbox_textarea_class.'" rows="'.$rows.'" cols="'.$cols.'" placeholder="'.$hint.'">'.$value.'</textarea>';
		}
				
		if($formhint){
			$output .= '<div id="'.$id.'_hint"><small>'.$formhint.'</small></div>';
		}

		$output .= '
		</div>';
		
		return $output;
	}

	

	
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint){
		
		if($value == $truevalue){
			$checked = 'checked="checked"'; 
		}
		else{
			$checked = '';
		}

		return '<div class=" errorplacement">
                <div id="'.$id.'_container" class="'.$this->checkboxinput_container_class.'">
                    <input class="'.$this->checkboxinput_input_class.'" type="checkbox" id="'.$id.'" name="'.$id.'" value="'.$truevalue.'" '.$checked.' '.$this->_get_next_tab_index().' />
					<label for="'.$id.'">'.$label.'</label>                  
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
	function checkboxList($label, $id, $class, $optionvals, $checkedvals=array(), $disabledvals=array(), $readonlyvals=array(), $hint='', $type='checkbox') {
		$output = '';

		if(empty($optionvals)){
			return false;
		}

		if(!is_array($checkedvals)){
			$checkedvals = array();
		}

		if($type=='checkbox'){
			$class= $this->checkboxList_input_class_checkbox;
		}
		else if($type=='radio'){
			$type='radio';
			$class= $this->checkboxList_input_class_radio;
			
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

		$output .= '<div id="'.$id.'_container" class="'.$this->checkboxList_container_class.' errorplacement">';
		$output .= '<label for="'.$id.'">'.$label.'</label>';
		$output .=  '<fieldset style="padding:30px; margin:0px;">';
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

				$output .= '
						<div class="'.$this->checkboxList_wrapper_class.'">
							<input class="'.$class.'" type="'.$type.'" id="'.$uniqid.'" name="'.$id.'[]" value="'.$value.'" '.$checked.' '.$disabled.' />
							<label for="'.$uniqid.'">'.$key.'</label>                  
						</div>
					   ';
			}
		}
		$output .=  '</fieldset></div>';
		
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

	function dateinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='date'){
	
		return $this->textinput($label, $id, $class, $size, $value, $hint, $maxlength, $readonly, $autocomplete, $formhint, $type);
		
	}

	function datetimeinput2($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='datetime-local'){
	
		$value = trim($value);
		$value = str_replace(' ', 'T', $value);
			
		$formhint = 'MM/DD/YYYY HH:MM AM/PM';

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
			<div id="'.$id.'_container" class="'.$this->timeinput_container_class.' errorplacement">
			  <label for="'.$id.'">'.$label.'</label>
				<input class="'.$this->timeinput_input_class.'"  type="text" id="'.$id.'" name="'.$id.'" value="'.$value.'">
			</div>';
	
	}




	//DOES NOT CONVERT FOR TIMEZONES
	function datetimeinput($label, $id, $class, $inputdatetime, $hint, $timehint, $datehint) {

			if(!is_null($inputdatetime) && $inputdatetime != ''){
				$session = SessionControl::get_instance();
				$inputdate = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'Y-m-d');
				$inputtime = LibraryFunctions::convert_time($inputdatetime, 'UTC', 'UTC', 'g:i a');
			}
			else{
				$inputdate = '';
				$inputtime = '';
			}
			
			
			$output = $this->dateinput($label, $id.'_date', NULL, NULL, $inputdate, $hint, NULL, NULL, NULL, NULL, $type='date');
			
			$output .= $this->timeinput($label, $id.'_time', NULL, $inputtime, NULL); 
			return $output;

	}

	function dropinput($label, $id, $class, &$optionvals, $input, $hint,$showdefault=TRUE, $forcestrict=FALSE, $ajaxendpoint=FALSE, $imagedropdown=FALSE) {
		
		$output = '';
		
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
		
		
	
			$output .= '<div id="'.$id.'_container" class="'.$this->dropinput_container_class.' errorplacement">
						<label for="'.$id.'">'.$label.'</label>
						
							<select name="'.$id.'" id="'.$id.'" class="'.$this->dropinput_select_class.'">';
								


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
		<div id="file-drop-zone" class="uk-border-rounded uk-text-center uk-margin-bottom uk-padding" style="border: 2px dashed #e5e5e5; background-color: #f8f9fa; transition: all 0.3s ease; cursor: pointer;">
			<div uk-icon="icon: cloud-upload; ratio: 3" class="uk-text-muted uk-margin-bottom"></div>
			<h5 class="uk-text-muted">Drop files here or click to browse</h5>
			<p class="uk-text-muted uk-margin-bottom">Maximum file size: <?php echo $max_size_display; ?> | Allowed types: <?php echo strtoupper(str_replace(',', ', ', $allowed_extensions)); ?></p>
			<input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" style="display: none;">
			<button type="button" id="browse-btn" class="uk-button uk-button-default">
				<span uk-icon="icon: folder; ratio: 0.8"></span> Browse Files
			</button>
		</div>

		<!-- Upload Controls -->
		<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
			<div>
				<button type="button" id="upload-all-btn" class="uk-button uk-button-primary" disabled>
					<span uk-icon="icon: upload; ratio: 0.8"></span> Upload All
				</button>
				<button type="button" id="clear-all-btn" class="uk-button uk-button-default uk-margin-small-left" disabled>
					<span uk-icon="icon: trash; ratio: 0.8"></span> Clear All
				</button>
			</div>
			<div id="overall-progress" class="uk-width-expand uk-margin-left" style="display: none;">
				<progress id="progress-bar" class="uk-progress" value="0" max="100"></progress>
			</div>
		</div>

		<!-- Files Table -->
		<div class="uk-overflow-auto">
			<table class="uk-table uk-table-hover uk-table-divider">
				<thead>
					<tr>
						<th><span uk-icon="icon: file; ratio: 0.8"></span> File Name</th>
						<th><span uk-icon="icon: database; ratio: 0.8"></span> Size</th>
						<th><span uk-icon="icon: info; ratio: 0.8"></span> Status</th>
						<th><span uk-icon="icon: cog; ratio: 0.8"></span> Actions</th>
					</tr>
				</thead>
				<tbody id="files-list">
					<tr id="no-files-message">
						<td colspan="4" class="uk-text-center uk-text-muted uk-padding">
							<div uk-icon="icon: cloud-upload; ratio: 2" class="uk-margin-bottom"></div>
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
			const allowedTypes = new RegExp('\\\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
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
			const $progressBar = $('#progress-bar');

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
					'pdf': 'file-pdf',
					'doc': 'file-text',
					'docx': 'file-text',
					'xls': 'file-excel',
					'xlsx': 'file-excel',
					'jpg': 'image',
					'jpeg': 'image',
					'png': 'image',
					'gif': 'image',
					'mp3': 'file-audio',
					'mp4': 'file-video',
					'm4a': 'file-audio'
				};
				return iconMap[ext] || 'file';
			}

			// Show toast notification
			function showToast(message, type = 'info') {
				console.log(type + ': ' + message);
				// Simple alert for now - can be enhanced with UIKit notifications
				if (type === 'error') {
					if (typeof UIkit !== 'undefined') {
						UIkit.notification({
							message: message,
							status: 'danger',
							timeout: 5000
						});
					} else {
						alert('Error: ' + message);
					}
				} else {
					if (typeof UIkit !== 'undefined') {
						UIkit.notification({
							message: message,
							status: 'success',
							timeout: 3000
						});
					} else {
						alert(message);
					}
				}
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
					<tr data-file-id="${fileObj.id}" class="file-row">
						<td>
							<div class="uk-flex uk-flex-middle">
								<span uk-icon="icon: ${fileIcon}; ratio: 0.8" class="uk-margin-small-right"></span>
								<span class="file-name">${fileObj.file.name}</span>
							</div>
						</td>
						<td class="file-size">${formatFileSize(fileObj.file.size)}</td>
						<td class="file-status">
							<span class="uk-label uk-label-default">Ready to upload</span>
						</td>
						<td class="file-actions">
							<button type="button" class="uk-button uk-button-primary uk-button-small upload-single-btn" title="Upload this file">
								<span uk-icon="icon: upload; ratio: 0.8"></span>
							</button>
							<button type="button" class="uk-button uk-button-danger uk-button-small uk-margin-small-left remove-file-btn" title="Remove this file">
								<span uk-icon="icon: close; ratio: 0.8"></span>
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
					$uploadAllBtn.html(`<span uk-icon="icon: upload; ratio: 0.8"></span> Upload All (${pendingFiles})`);
				} else {
					$uploadAllBtn.html('<span uk-icon="icon: upload; ratio: 0.8"></span> Upload All');
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
					$status.html('<span class="uk-label uk-label-primary">Uploading...</span>');
					$actions.html(`
						<div class="uk-flex uk-flex-middle">
							<progress class="uk-progress uk-margin-small-right" value="0" max="100" style="width: 60px;"></progress>
							<span class="uk-text-muted uk-text-small">0%</span>
						</div>
					`);

					// Create XMLHttpRequest for progress tracking
					const xhr = new XMLHttpRequest();

					xhr.upload.addEventListener('progress', function(e) {
						if (e.lengthComputable) {
							const progress = Math.round((e.loaded / e.total) * 100);
							$actions.find('progress').attr('value', progress);
							$actions.find('.uk-text-muted').text(progress + '%');
							$status.html(`<span class="uk-label uk-label-primary">Uploading ${progress}%</span>`);
						}
					});

					xhr.addEventListener('load', function() {
						if (xhr.status === 200) {
							try {
								const response = JSON.parse(xhr.responseText);
								if (response.files && response.files[0]) {
									const file = response.files[0];
									if (file.url) {
										// Success
										$status.html('<span class="uk-label uk-label-success"><span uk-icon="icon: check; ratio: 0.8"></span> Upload successful</span>');
										$actions.html(`
											<a href="${file.url}" target="_blank" class="uk-button uk-button-success uk-button-small" title="Download file">
												<span uk-icon="icon: download; ratio: 0.8"></span>
											</a>
											<button type="button" class="uk-button uk-button-danger uk-button-small uk-margin-small-left remove-file-btn" title="Remove from list">
												<span uk-icon="icon: close; ratio: 0.8"></span>
											</button>
										`);
										
										// Make filename clickable if we have a file ID
										if (file.file_id) {
											const $nameElement = $row.find('.file-name');
											const fileName = $nameElement.text();
											$nameElement.html(`<a href="/admin/admin_file?fil_file_id=${file.file_id}" target="_blank">${fileName}</a>`);
										}
										
										fileObj.status = 'completed';
										fileObj.url = file.url;
										showToast(`Successfully uploaded: ${fileObj.file.name}`, 'success');
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
					
					$status.html(`<span class="uk-label uk-label-danger"><span uk-icon="icon: warning; ratio: 0.8"></span> Error</span>`);
					$actions.html(`
						<button type="button" class="uk-button uk-button-primary uk-button-small upload-single-btn" title="Retry upload">
							<span uk-icon="icon: refresh; ratio: 0.8"></span>
						</button>
						<button type="button" class="uk-button uk-button-danger uk-button-small uk-margin-small-left remove-file-btn" title="Remove this file">
							<span uk-icon="icon: close; ratio: 0.8"></span>
						</button>
					`);
					fileObj.status = 'error';
					showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
					throw error;
				});
			}

			// Event Handlers
			$browseBtn.on('click', () => $fileInput[0].click());
			
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
				$(this).css('border-color', '#1e87f0').css('background-color', '#e3f2fd');
			});

			$dropZone.on('dragleave', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css('border-color', '#e5e5e5').css('background-color', '#f8f9fa');
			});

			$dropZone.on('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css('border-color', '#e5e5e5').css('background-color', '#f8f9fa');
				
				const files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					addFiles(files);
				}
			});

			// Hover effect
			$dropZone.on('mouseenter', function() {
				$(this).css('background-color', '#e3f2fd');
			}).on('mouseleave', function() {
				$(this).css('background-color', '#f8f9fa');
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
						$progressBar.attr('value', progress);
					} catch (error) {
						console.error('Upload failed:', error);
						completed++; // Count errors as completed for progress
						const progress = Math.round((completed / total) * 100);
						$progressBar.attr('value', progress);
					}
				}

				setTimeout(() => {
					$overallProgress.hide();
					$progressBar.attr('value', 0);
					updateUI();
				}, 1000);

				const successCount = selectedFiles.filter(f => f.status === 'completed').length;
				const errorCount = selectedFiles.filter(f => f.status === 'error').length;
				
				if (errorCount === 0) {
					showToast(`All ${successCount} files uploaded successfully!`, 'success');
				} else {
					showToast(`Upload completed: ${successCount} successful, ${errorCount} failed`, 'info');
				}
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
				if (fileObj && fileObj.status === 'pending') {
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

			// Click to browse anywhere in drop zone
			$dropZone.on('click', function(e) {
				if (e.target === this || !$(e.target).closest('button').length) {
					$fileInput[0].click();
				}
			});
		});
		</script>

		<style>
		#file-drop-zone:hover {
			background-color: #e3f2fd !important;
			border-color: #1e87f0 !important;
		}
		
		.file-row {
			transition: background-color 0.2s ease;
		}
		
		.file-row:hover {
			background-color: #f8f9fa;
		}
		
		.uk-progress {
			border-radius: 0.25rem;
		}
		
		.uk-label {
			font-size: 0.75em;
		}
		
		.uk-button-small {
			font-size: 0.8rem;
		}
		
		.uk-table th {
			font-weight: 600;
			font-size: 0.9rem;
		}
		</style>
	<?php
	}


}
?>
