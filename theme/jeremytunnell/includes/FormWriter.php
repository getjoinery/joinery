<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
// Use PathHelper for other includes
PathHelper::requireOnce('includes/FormWriterBase.php');

// THESE FUNCTIONS GENERATE FORM INPUTS

class FormWriter extends FormWriterBase {

	function passwordinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly="") {
	?>
					<div id="<?php echo $id . '_container'; ?>" class="<?php echo $class; ?> errorplacement">
						<!--<p id="error1" class="errorField"><strong>Description of your error</strong></p>-->
					  <label for="<?php echo $id; ?>"><?php echo $label; ?></label>
						<input name="<?php echo $id; ?>" id="<?php echo $id; ?>" value="<?php echo $value; ?>" size="<?php echo $size; ?>" type="password" class="textInput" maxlength="<?php echo $maxlength; ?>" <?php echo $this->_get_next_tab_index(); ?>/>
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

	}

	function text($id, $label, $value, $class, $layout = 'default') {
		echo "<div class=\"$class errorplacement\">";
		echo "<label>$label</label>";
		echo "<p>$value</p>";
		echo "</div>";
	}

	function textinput($label, $id, $class, $size, $value, $hint, $maxlength=255, $readonly='', $autocomplete=TRUE, $formhint=FALSE, $type='text', $layout='default') {

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
	}

	function textbox($label, $id, $class, $rows, $cols, $value, $hint, $htmlmode="no") {
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
	}

	//align is either 'left' or 'normal'
	function checkboxinput($label, $id, $class, $align, $value, $truevalue, $hint){
		
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
	}

	function start_buttons($class = '') {
		return '';
	}

	function new_form_button($label='Submit', $style='primary', $width='standard', $class='', $id=NULL) {
		$output = '<input type="submit" value="' . htmlspecialchars($label) . '" class="btn ' . $class . '"';
		if($id) {
			$output .= ' id="' . htmlspecialchars($id) . '"';
		}
		$output .= ' />';
		return $output;
	}

	function end_buttons() {
		return '';
	}

}
?>
