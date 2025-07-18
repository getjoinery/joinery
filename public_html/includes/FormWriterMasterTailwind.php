<?php
require_once('FormWriterBase.php');
require_once('DbConnector.php');
require_once('Globalvars.php');

//THIS FORMWRITER MASTER IS FOR TAILWIND FORM STYLING

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterMasterTailwind extends FormWriterBase {

	protected $use_grid;
	public $validate_style_info = 'errorElement: "span",
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

		$this->use_tabindex = $use_tabindex;
	}




	

	function begin_form($class, $method, $action, $use_grid = false, $charset = 'UTF-8'){
		$this->use_grid = $use_grid;
		$output = '<form class="'.$class.'" id="'. $this->formid.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		
		if($this->use_grid){
			$output .= '<div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">';
		}
		return $output;
	}

	function end_form(){
		if($this->use_grid){
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
			$class = 'w-full sm:col-span-6 '. $class;
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
	function radioinput($label, $id, $class, &$optionvals, $checkedval, $disabledvals=array(), $readonlyvals=array(), $hint) {
		
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
		
		
	
			$output .= '<div id="'.$id.'_container" class="'.$class.' errorplacement">
						<label class="'.$this->dropinput_label_class.'" for="'.$id.'">'.$label.'</label>
						
							<select name="'.$id.'" id="'.$id.'" class="'.$this->dropinput_select_class.'">';
								


			if($showdefault){
				if($showdefault === true){
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">Choose One';
					}
					else{
						$output .= '<option value="">Choose One';
					}
				}
				else{
					if(is_null($input)){
						$output .=  '<option value="" selected="selected">'.$showdefault;
					}
					else{
						$output .= '<option value="">'.$showdefault;
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
			
			$output .= '<h5>'.$label.'</h5><div id="'.$id.'_container" class=" errorplacement image-dropdown">';
								

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
		<div id="file-drop-zone" class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center mb-6 bg-gray-50 transition-all duration-300 ease-in-out cursor-pointer hover:bg-blue-50 hover:border-blue-300">
			<svg class="mx-auto h-12 w-12 text-gray-400 mb-4" stroke="currentColor" fill="none" viewBox="0 0 48 48">
				<path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<h3 class="text-lg font-medium text-gray-700 mb-2">Drop files here or click to browse</h3>
			<p class="text-sm text-gray-500 mb-4">Maximum file size: <?php echo $max_size_display; ?> | Allowed types: <?php echo strtoupper(str_replace(',', ', ', $allowed_extensions)); ?></p>
			<input type="file" id="file-input" multiple accept="<?php echo $accept_attr; ?>" class="hidden">
			<button type="button" id="browse-btn" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center">
				<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-5L12 5H5a2 2 0 00-2 2z"/>
				</svg>
				Browse Files
			</button>
		</div>

		<!-- Upload Controls -->
		<div class="flex justify-between items-center mb-6">
			<div class="flex space-x-2">
				<button type="button" id="upload-all-btn" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
					<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
					</svg>
					Upload All
				</button>
				<button type="button" id="clear-all-btn" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
					<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
					</svg>
					Clear All
				</button>
			</div>
			<div id="overall-progress" class="flex-1 ml-4 hidden">
				<div class="bg-gray-200 rounded-full h-2">
					<div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
				</div>
			</div>
		</div>

		<!-- Files Table -->
		<div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
			<table class="min-w-full divide-y divide-gray-200">
				<thead class="bg-gray-50">
					<tr>
						<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
							<div class="flex items-center">
								<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
								</svg>
								File Name
							</div>
						</th>
						<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
							<div class="flex items-center">
								<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
								</svg>
								Size
							</div>
						</th>
						<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
							<div class="flex items-center">
								<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
								</svg>
								Status
							</div>
						</th>
						<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
							<div class="flex items-center">
								<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
								</svg>
								Actions
							</div>
						</th>
					</tr>
				</thead>
				<tbody id="files-list" class="bg-white divide-y divide-gray-200">
					<tr id="no-files-message">
						<td colspan="4" class="px-6 py-8 text-center text-gray-500">
							<svg class="mx-auto h-16 w-16 text-gray-400 mb-4" stroke="currentColor" fill="none" viewBox="0 0 48 48">
								<path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<div class="text-lg font-medium">No files selected</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if($getargs): ?>
		<form id="hidden-form-data" class="hidden">
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

			// Show toast notification
			function showToast(message, type = 'info') {
				console.log(type + ': ' + message);
				if (type === 'error') {
					alert('Error: ' + message);
				} else {
					alert(message);
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
				$noFilesMessage.addClass('hidden');
				
				const $row = $(`
					<tr data-file-id="${fileObj.id}" class="file-row hover:bg-gray-50 transition-colors duration-200">
						<td class="px-6 py-4 whitespace-nowrap">
							<div class="flex items-center">
								<svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
								</svg>
								<span class="file-name text-sm font-medium text-gray-900">${fileObj.file.name}</span>
							</div>
						</td>
						<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 file-size">${formatFileSize(fileObj.file.size)}</td>
						<td class="px-6 py-4 whitespace-nowrap file-status">
							<span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">Ready to upload</span>
						</td>
						<td class="px-6 py-4 whitespace-nowrap text-sm font-medium file-actions">
							<button type="button" class="text-blue-600 hover:text-blue-900 mr-3 upload-single-btn" title="Upload this file">
								<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
								</svg>
							</button>
							<button type="button" class="text-red-600 hover:text-red-900 remove-file-btn" title="Remove this file">
								<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
								</svg>
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
					$noFilesMessage.removeClass('hidden');
				}
				
				// Update button text with count
				if (pendingFiles > 0) {
					$uploadAllBtn.find('svg').next().text(`Upload All (${pendingFiles})`);
				} else {
					$uploadAllBtn.find('svg').next().text('Upload All');
				}
			}

			// Event Handlers
			$browseBtn.on('click', () => $fileInput[0].click());
			
			$fileInput.on('change', function() {
				if (this.files.length > 0) {
					addFiles(this.files);
					this.value = ''; // Reset input
				}
			});

			// Drag and drop functionality
			$dropZone.on('dragover dragenter', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).addClass('bg-blue-50 border-blue-300');
			});

			$dropZone.on('dragleave', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).removeClass('bg-blue-50 border-blue-300');
			});

			$dropZone.on('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).removeClass('bg-blue-50 border-blue-300');
				
				const files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					addFiles(files);
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
			$filesList.on('click', '.remove-file-btn', function() {
				const $row = $(this).closest('.file-row');
				const fileId = $row.data('file-id');
				
				// Remove from array
				selectedFiles = selectedFiles.filter(f => f.id !== fileId);
				
				// Remove from DOM
				$row.remove();
				updateUI();
			});

			// Click to browse anywhere in drop zone
			$dropZone.on('click', function(e) {
				if (e.target === this || !$(e.target).closest('button').length) {
					$fileInput[0].click();
				}
			});

			console.log('Modern Tailwind file upload loaded');
		});
		</script>
	<?php
	}


}
?>
