<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriterPublic extends FormWriterMaster {

	protected $validate_style_info = '	errorElement: "p",						
							errorClass: "errorField",
							highlight: function(element, errorClass) {
								$("#"+element.name+"_container").addClass("error");

							  },
							  unhighlight: function(element, errorClass) {
								  $("#"+element.name+"_container").removeClass("error");

							  },
							errorPlacement: function(error, element) {
								error.prependTo(element.parents(".errorplacement").eq(0));
							}';

	function begin_form($class, $method, $action, $charset = 'UTF-8', $onsubmit = NULL){
		$output = '<form id="'. $this->formid.'" class="'.$class.'" name="'. $this->formid.'" method="'. $method.'" action="'. $action.'" accept-charset="'. $charset.'">';
		return $output;
	}

	function end_form(){
		return '</form>';
	}



	function text($label, $value, $class) {
		ob_start(); //Start output buffer
		echo "<div class=\"$class errorplacement\">";
		echo "<label>$label</label>";
		echo "<p>$value</p>";
		echo "</div>";
		$output = ob_get_contents(); //Grab output
		ob_end_clean(); //Discard output buffer
		return $output;		
	}

	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="", $autocomplete=TRUE, $formhint="") {
		ob_start(); //Start output buffer
		$value = str_replace('"', '&quot;', $value );
		?>
					<div id="<?php echo $id . '_container'; ?>" class="<?php echo $class; ?> errorplacement">
					  <label for="<?php echo $id; ?>"><?php echo $label; ?></label>
					  <input name="<?php echo $id; ?>" id="<?php echo $id; ?>" <?php if(!$autocomplete){ echo 'autocomplete="off"'; } ?> value="<?php echo $value; ?>" size="<?php echo $size; ?>" type="text" class="textInput" maxlength="<?php echo $maxlength; ?>" <?php echo $readonly; ?><?php echo $this->_get_next_tab_index(); ?>/>
						<?php if ($formhint): ?>
							<p id="<?php echo $id . '_hint'; ?>" class="formHint"><?php echo $formhint; ?></p>
						<?php endif; ?>
						<?php if ($hint): ?>
							
							<div class="form_callout">
							  <div class="form_callout_top"></div>
							  <div class="form_callout_content">
								 <p id="<?php echo $id . '_callout'; ?>"><?php echo $hint; ?></p>
							  </div>
							  <div class="form_callout_bottom"></div>
							</div>
						<?php endif; ?>


					</div>
		<?php
		$output = ob_get_contents(); //Grab output
		ob_end_clean(); //Discard output buffer
		return $output;
	}


	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
		ob_start(); //Start output buffer	
		?>
					<div id="<?php echo $id . '_container'; ?>" class="<?php echo $class; ?> errorplacement">
						<!--<p id="error1" class="errorField"><strong>Description of your error</strong></p>-->
					  <label for="<?php echo $id; ?>"><?php echo $label; ?></label>
					  <textarea name="<?php echo $id; ?>" <?php if($htmlmode == 'yes'){ echo 'class="html_editable"'; } ?> id="<?php echo $id; ?>" rows="<?php echo $rows; ?>" cols="<?php echo $cols; ?>"><?php echo $value; ?></textarea>
						<?php if ($hint): ?>
							<!--<p id="<?php echo $id . '_hint'; ?>" class="formHint"><?php echo $hint; ?></p>-->
							<div class="form_callout">
							  <div class="form_callout_top"></div>
							  <div class="form_callout_content">
								 <p id="<?php echo $id . '_callout'; ?>"><?php echo $hint; ?></p>
							  </div>
							  <div class="form_callout_bottom"></div>
							</div>
						<?php endif; ?>
					</div>
		<?php
		$output = ob_get_contents(); //Grab output
		ob_end_clean(); //Discard output buffer
		return $output;	
	}



	//align is either 'left' or 'normal'
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint){
		ob_start(); //Start output buffer
		if($value == $truevalue){
			$checked = 'checked="checked"';
		}
		else{
			$checked = '';
		}

		if($align == 'left'){
			$alignclass = 'textAlignLeft';
		}
		else{
			$alignclass = 'clear-block';
		}


		?>
				<div id="<?php echo $id . '_container'; ?>" class="<?php echo $class; ?> errorplacement">
					<!--<p id="error1" class="errorField"><strong>Description of your error</strong></p>-->
					<?php if($align != 'left'){ ?>
					<label for="<?php echo $id; ?>"></label>
					<div class="multiField">
					<?php } ?>
                    <label for="<?php echo $id; ?>" class="inlineLabel <?php echo $alignclass; ?>">
                    	<input name="<?php echo $id; ?>" id="<?php echo $id; ?>" value="<?php echo $truevalue; ?>" type="checkbox" <?php echo $checked; ?> <?php echo $this->_get_next_tab_index(); ?>/>
                      <span><?php echo $label; ?></span>

                    </label>
                    <?php if($align != 'left'){ ?>
                    </div>
                    <?php } ?>
						<?php if ($hint): ?>
							<!--<p id="<?php echo $id . '_hint'; ?>" class="formHint"><?php echo $hint; ?></p>-->
							<div class="form_callout">
							  <div class="form_callout_top"></div>
							  <div class="form_callout_content">
								 <p id="<?php echo $id . '_callout'; ?>"><?php echo $hint; ?></p>
							  </div>
							  <div class="form_callout_bottom"></div>
							</div>
						<?php endif; ?>


                </div>
		<?php
		$output = ob_get_contents(); //Grab output
		ob_end_clean(); //Discard output buffer
		return $output;	
	}



	function dropinput($label, $id, $class, &$optionvals, $input, $hint,$showdefault=TRUE, $forcestrict=TRUE) {
		ob_start(); //Start output buffer
			?>
                <div id="<?php echo $id . '_container'; ?>" class="<?php echo $class; ?> errorplacement">
                	<label for="<?php echo $id; ?>"><?php echo $label; ?></label>

                  <select name="<?php echo $id; ?>" id="<?php echo $id; ?>">
			<?php
			if($showdefault){
				if(is_null($input)){
					echo '<option value="" selected="selected">Choose One';
				}
				else{
					echo '<option value="">Choose One';
				}
			}


			foreach ($optionvals as $key => $value) {
				//$session = SessionControl::get_instance();
				//if($_SESSION['permission'] == 10){
				//	echo $input.gettype($input).' '.$value.gettype($value).'<br />';
				//}
				
				//DEBUG
				/*
				echo '<br>' . $input . ' - ' . $value;
				if($input == $value){
					echo "MATCH\n";
				}
				else{
					echo "--\n";
				}
				*/
				

				if($forcestrict && $input === $value){
					echo '<option value="'. $value .'" selected="selected">' . $key . '</option>';
				} elseif ($input == $value) { 
					echo '<option value="'. $value .'" selected="selected">' . $key . '</option>';
				} else {

					echo '<option value="'. $value .'">' . $key . '</option>';
				}
			}
			?>
                  </select>
				<?php if ($hint): ?>
					<!--<p id="<?php echo $id . '_hint'; ?>" class="formHint"><?php echo $hint; ?></p>-->
					<div class="form_callout">
					  <div class="form_callout_top"></div>
					  <div class="form_callout_content">
						 <p id="<?php echo $id . '_callout'; ?>"><?php echo $hint; ?></p>
					  </div>
					  <div class="form_callout_bottom"></div>
					</div>
				<?php endif; ?>
                </div>
            <?php
		$output = ob_get_contents(); //Grab output
		ob_end_clean(); //Discard output buffer
		return $output;	
	}






}
?>
