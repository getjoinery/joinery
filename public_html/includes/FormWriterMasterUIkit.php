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



}
?>
