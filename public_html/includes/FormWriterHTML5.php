<?php
require_once('FormWriterBase.php');
require_once('DbConnector.php');
require_once('Globalvars.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterHTML5 extends FormWriterBase {
	public $validate_style_info = '
							errorElement: "span",
							errorClass: "error-message",
							highlight: function(element, errorClass) {
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"").addClass("error");
							},
							unhighlight: function(element, errorClass) {
								var name = element.name.replace(/[\[\]]/gi, "");
								$("#"+name+"").removeClass("error");
							},
							errorPlacement: function(error, element) {
								error.appendTo(element.parents(".errorplacement").eq(0));
							}';
	
	//FORM STYLING - Plain HTML5 classes

	protected $text_container_class = 'form-field';
	protected $text_label_class = 'form-label';
	protected $text_input_class = 'form-input-readonly';

	protected $textinput_container_class = 'form-field';
	protected $textinput_label_class = 'form-label';
	protected $textinput_input_class = 'form-input';

	protected $textbox_container_class = 'form-label';
	protected $textbox_textarea_class = 'form-textarea';

	protected $checkboxinput_container_class = 'form-checkbox';
	protected $checkboxinput_input_class = 'checkbox-input';
	protected $checkboxinput_label_class = 'checkbox-label';

	protected $checkboxList_container_class = 'form-checkbox';
	protected $checkboxList_input_class = 'checkbox-input';
	protected $checkboxList_label_class = 'checkbox-label';

	protected $dropinput_container_class = 'form-field';
	protected $dropinput_label_class = 'form-label';
	protected $dropinput_select_class = 'form-select';

	protected $button_primary_class = 'button-primary';
	protected $button_secondary_class = 'button-secondary';




	
	
	

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
			$output .= '<div class="button-container-full">';
		}


		$output .= '<a href="'.$link.'"><button type="button" class="button '.$class.'"';
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
			$output .= '<div class="button-container-full">';
		}

		$output = '<button type="submit" class="button '.$class.'"';
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
	
	// set_validate method now inherited from FormWriterBase

	/**
	 * Override multi_upload_button for plain HTML5 styling
	 */
	protected function multi_upload_button($context, $id, $label, $disabled = false) {
		$disabled_attr = $disabled ? ' disabled' : '';
		$style_class = '';

		switch($context) {
			case 'browse':
				$style_class = 'button button-outline-primary';
				break;
			case 'upload':
				$style_class = 'button button-primary';
				break;
			case 'clear':
				$style_class = 'button button-outline-secondary';
				break;
			default:
				$style_class = 'button button-secondary';
		}

		return '<button type="button" id="' . $id . '" class="' . $style_class . '"' . $disabled_attr . '>' . $label . '</button>';
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
			<div class="'.$this->text_container_class.' horizontal">
			  <label for="'.$id.'" class="'.$this->text_label_class.'">'.$label.'</label>
			  <div class="field-content">
				<input class="'.$this->text_input_class.'" id="'.$id.'" type="text" readonly="" value="'.$value.'" />
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
			$output .= '<div class="form-field-horizontal">';
			if($label){
				$output .= '<label for="'.$id.'" class="form-label-horizontal">'.$label.'</label>';
			}
			$output .= '<div class="field-content">';


			if($formhint){
				$output .= '<div class="input-group">
				<span class="input-prefix">'.$formhint.'</span>';
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
				<span class="input-prefix">'.$formhint.'</span>';
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
			
			<script src="/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>
			<link rel="stylesheet" href="/assets/vendor/Trumbowyg-2-26/dist/ui/trumbowyg.min.css">
			<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js"></script>
			<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/preformatted/trumbowyg.preformatted.min.js"></script>
			<script src="/assets/vendor/Trumbowyg-2-26/dist/plugins/allowtagsfrompaste/trumbowyg.allowtagsfrompaste.min.js"></script>
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
			return '<div class="form-field-horizontal">
						<div class="form-label-horizontal">'.$label.'</div>
						<div class="field-content">
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
			return '<div class="errorplacement">
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
		<link rel="stylesheet" href="/assets/vendor/jquery-timepicker-1.3.5/jquery.timepicker.min.css"/>
		<script type="text/javascript" src="/assets/vendor/jquery-timepicker-1.3.5/jquery.timepicker.min.js"></script>
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
			$output .= '<link href="/assets/vendor/select2/select2.min.css" rel="stylesheet" />
			<script src="/assets/vendor/select2/select2.full.min.js"></script>';
		
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
								<div class="form-field-horizontal">
									<label for="'.$id.'" class="form-label-horizontal">'.$label.'</label>
									<div class="field-content">';

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
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
				}
				else{
					$output .= '<input type="radio" id="default_id" name="'.$id.'" value="" checked="checked" /><label for="default_id"><span class="dropimagewidth"><img loading="lazy" src="/assets/images/image_placeholder_thumbnail.png"></span> No Image</label>';
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






}
?>
