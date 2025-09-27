<?php
require_once('PathHelper.php');
require_once('Globalvars.php');
require_once('LibraryFunctions.php');
require_once('EmailMessage.php');
require_once('EmailSender.php');

require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class EmailTemplateError extends Exception {}

class EmailTemplate {
    // Properties for template storage
    protected $inner_template;
    protected $outer_template;
    protected $footer;
    protected $orig_inner_template;
    protected $inner_html;
    
    // Template metadata
    protected $template_name;
    protected $utm_source = 'email';
    protected $utm_medium = 'email';
    protected $utm_content = 'email';
    protected $utm_campaign = '';
    
    // Processed content (access via getter methods)
    private $email_subject;
    private $email_html; 
    private $email_text;
    private $email_has_content = false;
    
    // Template values
    private $template_values = [];
    
    // Settings
    private $settings;
    
    // Legacy properties (no longer used)
    private $email_from;
    private $email_from_name;
    private $email_recipients = [];
    
    /**
     * Constructor for template processing
     * 
     * @param string $inner_template Template name
     * @param string $outer_template Outer template name (null for default)
     * @param string $footer Footer template name (null for default)
     */
    public function __construct($inner_template, $outer_template = null, $footer = null) {
        // Explicit check to prevent old usage pattern
        if ($outer_template instanceof User || is_object($outer_template)) {
            throw new EmailTemplateError(
                'EmailTemplate constructor no longer accepts User objects as second parameter. ' .
                'Use EmailMessage::fromTemplate() for proper template processing.'
            );
        }
        $this->settings = Globalvars::get_instance();
        
        // Load outer template
        if (!$outer_template) {
            $templates = new MultiEmailTemplateStore(
                array('email_template_name' => 'default_outer_template')
            );
            $templates->load();
            $count = $templates->count_all();
            if ($count) {
                $this_template = $templates->get(0);
                $this->outer_template = $this_template->get('emt_body');
            } else {
                throw new EmailTemplateError('We could not find the default template.');
            }
        }
        
        // Load footer template
        if (!$footer) {
            $templates = new MultiEmailTemplateStore(
                array('email_template_name' => 'default_footer')
            );
            $templates->load();
            $count = $templates->count_all();
            if ($count) {
                $this_template = $templates->get(0);
                $this->footer = $this_template->get('emt_body');
            } else {
                throw new EmailTemplateError('We could not find the default template.');
            }
        }
        
        // Load inner template
        if (!$this->inner_template) {
            $templates = new MultiEmailTemplateStore(
                array('email_template_name' => $inner_template)
            );
            $templates->load();
            $count = $templates->count_all();
            if ($count) {
                $this_template = $templates->get(0);
                $this->inner_template = $this_template->get('emt_body');
                $this->email_subject = $this_template->get('emt_subject');
            } else {
                throw new EmailTemplateError('We could not find the template ' . $inner_template);
            }
        }
        
        // Load outer template if string provided
        if (!$this->outer_template && $outer_template) {
            $templates = new MultiEmailTemplateStore(
                array('email_template_name' => $outer_template)
            );
            $templates->load();
            $count = $templates->count_all();
            if ($count) {
                $this_template = $templates->get(0);
                $this->outer_template = $this_template->get('emt_body');
            } else {
                throw new EmailTemplateError('We could not find the template ' . $outer_template);
            }
        }
        
        // Load footer if string provided
        if (!$this->footer && $footer) {
            $templates = new MultiEmailTemplateStore(
                array('email_template_name' => $footer)
            );
            $templates->load();
            $count = $templates->count_all();
            if ($count) {
                $this_template = $templates->get(0);
                $this->footer = $this_template->get('emt_body');
            } else {
                $this->footer = '';
            }
        }
        
        $this->orig_inner_template = $this->inner_template;
        
        // Extract template name
        $tmp_template_name = preg_split('/[\/\.]/', $inner_template);
        if (count($tmp_template_name) <= 1) {
            $this->template_name = $inner_template;
        } else {
            $this->template_name = $tmp_template_name[count($tmp_template_name) - 2];
        }
        
        // Initialize template values
        $this->template_values = array(
            'template_name' => $this->template_name,
            'web_dir' => LibraryFunctions::get_absolute_url(''),
            'email_vars' => $this->_generate_email_vars(),
        );
        
        $this->inner_html = null;
        $this->email_has_content = false;
    }
    
    /**
     * Reset template for reuse
     */
    public function reset() {
        $this->email_has_content = false;
        $this->inner_html = null;
        $this->inner_template = $this->orig_inner_template;
        $this->email_subject = null;
        $this->email_html = null;
        $this->email_text = null;
    }
    
    /**
     * Process template with values (main template processing method)
     */
    public function fill_template($values) {
        // Override tracking values if provided
        if (isset($values['utm_source'])) {
            $this->utm_source = $values['utm_source'];
        }
        if (isset($values['utm_medium'])) {
            $this->utm_medium = $values['utm_medium'];
        }
        if (isset($values['utm_campaign'])) {
            $this->utm_campaign = $values['utm_campaign'];
        }
        if (isset($values['utm_content'])) {
            $this->utm_content = $values['utm_content'];
        }
        $this->template_values['email_vars'] = $this->_generate_email_vars();
        
        // Merge values
        $values = array_merge($values, $this->template_values);
        $set_values = array();
        
        // Process conditionals
        list($email_body, $set_values) = $this->_process_conditionals($values, $this->inner_template);
        $values = array_merge($values, $set_values);
        
        // Add footer if exists
        if ($this->footer) {
            list($footer_string, $footer_set_values) = $this->_process_conditionals($values, $this->footer);
            $email_body .= $footer_string;
            $set_values = array_merge($set_values, $footer_set_values);
        }
        
        // Check for content
        if (!trim($email_body)) {
            return;
        }
        
        // Process template variables
        $split_template = preg_split(
            '/\*([^\*\| ]+(?:\|[^\*]+)?)\*/', $email_body, null,
            PREG_SPLIT_DELIM_CAPTURE
        );
        
        $all_values = array_merge($values, $set_values);
        
        $split_template_size = count($split_template);
        for ($i = 0; $i < $split_template_size; $i++) {
            if ($i % 2) {
                $pipe_search = explode('|', $split_template[$i]);
                
                $pipe_values = null;
                if (count($pipe_search) >= 2) {
                    $pipe_values = array_slice($pipe_search, 1);
                }
                
                $template_placeholder = $pipe_search[0];
                $value = $this->_process_value($template_placeholder, $all_values);
                
                if ($value instanceof DateTime) {
                    if ($pipe_values) {
                        if (count($pipe_values) == 1) {
                            $split_template[$i] = $value->format($pipe_values[0]);
                        } else if (count($pipe_values) == 2) {
                            $value->setTimeZone(new DateTimeZone($this->_process_value($pipe_values[1], $all_values)));
                            $split_template[$i] = $value->format($pipe_values[0]);
                        }
                    } else {
                        $split_template[$i] = $value->format(DATE_ATOM);
                    }
                } elseif (is_string($value)) {
                    if ($pipe_values) {
                        foreach ($pipe_values as $pipe_value) {
                            switch ($pipe_value) {
                                case 'nl2br':
                                    $value = nl2br($value);
                                    break;
                                default:
                                    break;
                            }
                        }
                    }
                    $split_template[$i] = $value;
                } else {
                    $split_template[$i] = $value;
                }
            }
        }
        
        $html = trim(implode('', $split_template));
        
        // Merge with outer template
        $html = str_replace('*!**mail_body**!*', $html, $this->outer_template);
        
        // Add tracking to links if needed
        if (isset($values['utm_source'])) {
            $this->utm_source = $values['utm_source'];
            $html = $this->_add_tracking_to_links($html);
        }
        
        // Set final processed content
        $this->email_html = $html;
        $this->email_text = LibraryFunctions::htmlToText($html);
        $this->email_has_content = true;
        
        return $set_values;
    }
    
    /**
     * Get processed HTML
     */
    public function getHtml() {
        return $this->email_html;
    }
    
    /**
     * Alias for getHtml
     */
    public function getEmailHtml() {
        return $this->email_html;
    }
    
    /**
     * Get processed text
     */
    public function getText() {
        return $this->email_text;
    }
    
    /**
     * Alias for getText
     */
    public function getEmailText() {
        return $this->email_text;
    }
    
    /**
     * Get extracted subject
     */
    public function getSubject() {
        return $this->email_subject;
    }
    
    /**
     * Alias for getSubject
     */
    public function getEmailSubject() {
        return $this->email_subject;
    }
    
    /**
     * Check if template has processable content
     */
    public function hasContent() {
        return $this->email_has_content;
    }
    
    /**
     * Alias for hasContent
     */
    public function is_sendable() {
        return $this->email_has_content;
    }
    
    /**
     * Create an EmailMessage from this template
     */
    public function createMessage($values = []) {
        if (!empty($values)) {
            $this->fill_template($values);
        }
        
        $message = new EmailMessage();
        if ($this->email_subject) {
            $message->subject($this->email_subject);
        }
        if ($this->email_html) {
            $message->html($this->email_html);
        }
        if ($this->email_text) {
            $message->text($this->email_text);
        }
        
        return $message;
    }
    
    // ===== INTERNAL HELPER METHODS =====
    
    protected function _generate_email_vars() {
        return 'utm_source=' . $this->utm_source . '&amp;utm_medium=' . $this->utm_medium . 
               '&amp;utm_content=' . $this->utm_content . '&amp;utm_campaign=' . $this->utm_campaign;
    }
    
    protected function _add_tracking_to_links($email_body) {
        $dom = new DOMDocument;
        @$dom->loadHTML($email_body);
        
        $links = $dom->getElementsByTagName('a');
        
        $tracking_text = $this->_generate_email_vars();
        foreach ($links as $link) {
            $start_text = $link->getAttribute('href');
            if (strpos($link->getAttribute('href'), '?')) {
                $replace_text = 'href="' . $link->getAttribute('href') . '&' . $tracking_text . '"';
            } else {
                $replace_text = 'href="' . $link->getAttribute('href') . '?' . $tracking_text . '"';
            }
            
            $search_text = 'href="' . $start_text . '"';
            $email_body = str_replace($search_text, $replace_text, $email_body);
        }
        
        return $email_body;
    }
    
    private function _value_exists($value, $values) {
        $value_levels = explode('->', $value);
        $current_array_level = $values;
        
        foreach ($value_levels as $array_key) {
            if (!is_array($current_array_level) || !array_key_exists($array_key, $current_array_level)) {
                return false;
            }
            $current_array_level = $current_array_level[$array_key];
        }
        
        return true;
    }
    
    private function _process_value($value, $values) {
        $value_levels = explode('->', $value);
        $current_array_level = $values;
        
        foreach ($value_levels as $array_key) {
            if (!is_array($current_array_level) || !array_key_exists($array_key, $current_array_level)) {
                return null;
            }
            $current_array_level = $current_array_level[$array_key];
        }
        
        return $current_array_level;
    }
    
    protected function _process_conditionals($values, $template_string) {
        $set_values = array();

        // First remove all comments
        $template_string = preg_replace('/\/\*\*.*?\*\*\//ms', '', $template_string);

        $split_template = preg_split(
            '/(?<!\\\){([^\}]+)}/', $template_string, NULL,
            PREG_SPLIT_DELIM_CAPTURE);

        $template_pairs = array();
        $parser_stack = array();
        $split_template_size = count($split_template);
        for($i=0;$i<$split_template_size;$i++) {
            if ($i % 2) {
                if (strpos($split_template[$i], 'end') === 0) {
                    // It an end conditional, check the stack
                    if (!$parser_stack) {
                        throw new EmailTemplateError(
                            'This template doesn\'t compile.  Please check the conditionals: Cannot find {end} at ' . $split_template[$i]);
                    } else {
                        // Otherwise its a pair, put it here
                        $initial_spot = array_pop($parser_stack);
                        $template_pairs[$initial_spot] = array($split_template[$initial_spot], $i);
                    }
                } else {
                    array_push($parser_stack, $i);
                }
            }
        }

        if ($parser_stack) {
            throw new EmailTemplateError(
                'This template doesn\'t compile.  There is an unmatched conditional somewhere.<br>');
                
        }

        $valid_values = array();
        $last_line = NULL;

        // First go through and handle all the conditionals
        $split_template_size = count($split_template);
        for($i=0;$i<$split_template_size;$i++) {
            if ($i % 2) {
                if (!array_key_exists($i, $template_pairs)) {
                    // Here we know its an end point, skip it!
                    continue;
                }

                // Pull out the conditional and test it to see if its true!
                list($conditional, $end_point) = $template_pairs[$i];

                $matches = array();

                if (preg_match('/^(~)?(\S+)$/', $conditional, $matches)) {
                    list($unused, $negative, $item) = $matches;
                    $item_value = $this->_process_value($item, $values);
                    if (($negative && $item_value) || (!$negative && !$item_value)) {
                        $i = $end_point;
                        continue;
                    }
                } else if (preg_match('/^(\S+?)([\+-][0-9]+)? (==|<>|!=|>|>=|<|<=|%%|&|includes) (.+)$/', $conditional, $matches)) {
                    list($unused, $first_item,
                        $first_item_qualifier, $operator, $second_item) = $matches;

                    if ($this->_value_exists($first_item, $values)) {
                        // The value could be processed from the array
                        $first_value = $this->_process_value($first_item, $values);
                    } else if (array_key_exists($first_item, $set_values)) {
                        $first_value = $set_values[$first_item];
                    } else {
                        throw new EmailTemplateError(
                            'The first element of the follow conditional doesn\'t exist: ' . $conditional);
                    }
                    if (is_numeric($first_value)) {
                        $first_value = intval($first_value);
                        if ($first_item_qualifier) {
                            $first_value += intval($first_item_qualifier);
                        }
                    } else if (is_string($first_value)) {
                        $first_value = '"' . $first_value . '"';
                    }

                    $temp_matches = array();
                    if (is_numeric($second_item)) {
                        $second_value = intval($second_item);
                    } else if (strtolower($second_item) == 'true') {
                        $second_value = TRUE;
                    } else if (strtolower($second_item) == 'false') {
                        $second_value = FALSE;
                    } else if (preg_match('/^(".+")$/', $second_item, $temp_matches)) {
                        $second_value = $temp_matches[1];
                    } else if ($this->_value_exists($second_item, $values)) {
                        $second_value = $this->_process_value($second_item, $values);
                        if (is_numeric($second_value)) {
                            $second_value = intval($second_value);
                        } else if (is_string($second_value)) {
                            $second_value = '"' . $second_value . '"';
                        }
                    } else {
                        throw new EmailTemplateError(
                            'Could not process the second element of this conditional: ' . $conditional);
                    }

                    if ($first_value === NULL) {
                        throw new EmailTemplateError(
                            'Could not process the first element of this conditional: ' . $conditional);
                    }

                    $statement_positive = FALSE;
                    switch ($operator) {
                        case '%%':
                            $statement_positive = (($first_value % $second_value) == 0);
                            break;
                        case '==':
                            $statement_positive = ($first_value == $second_value);
                            break;
                        case '<>':
                        case '!=':
                            $statement_positive = ($first_value != $second_value);
                            break;
                        case '<':
                            $statement_positive = ($first_value < $second_value);
                            break;
                        case '<=':
                            $statement_positive = ($first_value <= $second_value);
                            break;
                        case '>':
                            $statement_positive = ($first_value > $second_value);
                            break;
                        case '>=':
                            $statement_positive = ($first_value >= $second_value);
                            break;
                        case '&':
                        case 'includes':
                            $statement_positive = (($first_value & $second_value) != 0);
                            break;
                        default:
                            throw new EmailTemplateError('Invalid Operator - Programming Error');
                    }
                    if ($statement_positive === FALSE) {
                        $i = $end_point;
                    }
                } else {
                        throw new EmailTemplateError(
                            'The follow conditional is malformed: ' . $conditional);
                }
            } else {
                // Its the in between of conditionals, so lets pull out all the [] counter
                // operations and process them
                $operations = preg_split(
                    '/\[([^\]]+)\]/', $split_template[$i], NULL,
                    PREG_SPLIT_DELIM_CAPTURE);

                $operations_size = count($operations);
                for($ii=0;$ii<$operations_size;$ii++) {
                    if ($ii % 2) {
                        $operation_matches = array();
                        if (preg_match('/^([a-zA-Z_]+)(=|\+=|-=| setbit )([0-9]+)$/', $operations[$ii], $operation_matches)) {
                            list($unused_full, $variable, $operation_type, $value) = $operation_matches;
                            switch($operation_type) {
                                case '=':
                                    $set_values[$variable] = intval($value);
                                    break;
                                case '+=':
                                    $set_values[$variable] += intval($value);
                                    break;
                                case '-=':
                                    $set_values[$variable] += intval($value);
                                    break;
                                case ' setbit ':
                                    // Handle the "setbit" operation here, which just flips the bit on the variable
                                    // This is 1-based (not 0 based), so setbit 1 === 2^0, setbit 2 == 2^1, etc.
                                    if (!isset($set_values[$variable])) {
                                        $set_values[$variable] = 1 << (intval($value) - 1);
                                    } else {
                                        $set_values[$variable] |= 1 << (intval($value) - 1);
                                    }
                                    break;
                            }
                        } else if (preg_match('/^([a-zA-Z_]+)="(.+)"$/', $operations[$ii], $operation_matches)) {
                            list($unused_full, $variable, $value) = $operation_matches;
                            $set_values[$variable] = $value;
                            // Handle special conditions here
                            if ($variable == 'template_name') {
                                $this->template_name = $value;
                            } else if ($variable == 'utm_source') {
                                $this->utm_source = $value;
                            }

                            // If any of these special conditions are used, update the email_vars
                            if ($variable == 'template_name' || $variable == 'utm_source') {
                                $values['email_vars'] = $this->_generate_email_vars();
                            }
                            // Done handling special conditions
                        } else if (preg_match('/^([a-zA-Z_]+)$/', $operations[$ii], $operation_matches)) {
                            $set_values[$operation_matches[1]] = TRUE;
                            if ($operation_matches[1] == 'last') {
                                // It is a last, which is a special operator.
                                // Stop processing the template, its over!
                                return array(implode('', $valid_values), $set_values);
                            }
                        } else {
                            throw new EmailTemplateError('Invalid Operation, Programming Error - ' . $operations[$ii]);
                        }
                    } else {
                        $valid_values[] = $operations[$ii];
                    }
                }
            }
        }

        return array(implode('', $valid_values), $set_values);
    }
    
}