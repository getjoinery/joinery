<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class CommentException extends SystemBaseException {}
class CommentNotSentException extends CommentException {};

class Comment extends SystemBase {	public static $prefix = 'cmt';
	public static $tablename = 'cmt_comments';
	public static $pkey_column = 'cmt_comment_id';

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'cmt_comment_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cmt_comment_id_parent' => array('type'=>'int4'),
	    'cmt_usr_user_id' => array('type'=>'int4'),
	    'cmt_author_name' => array('type'=>'varchar(255)'),
	    'cmt_pst_post_id' => array('type'=>'int4', 'required'=>true),
	    'cmt_body' => array('type'=>'text', 'required'=>true),
	    'cmt_created_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'cmt_is_approved' => array('type'=>'bool', 'default'=>true),
	    'cmt_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array(

	);

	function display_title(){
		if($this->get('cmt_body')){
			return substr(strip_tags($this->get('cmt_body')), 0, 100);
		}
		else{
			return '';
		}
	}

	function get_sanitized_comment(){
		$url = '~(?:(https?)://([^\s<]+)|(www\.[^\s<]+?\.[^\s<]+))(?<![\.,:])~i'; 
		$body = $this->get('cmt_body');
		$body = htmlspecialchars($body);
		$body = preg_replace($url, '<a href="$0" rel="nofollow" title="$0">$0</a>', $body);	
		return $body;
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

	static function add_comment($post_id, $session, $data){
		$settings = Globalvars::get_instance();
		if(!$session->get_user_id()){
			if(strlen($data['email'] > 0)){
				LibraryFunctions::display_404_page();			
			}
			if(strlen($data['comment'] > 0)){
				LibraryFunctions::display_404_page();			
			}		

			require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
			$formwriter = new FormWriter('form1');

			if(!$formwriter->honeypot_check($data)){
				LibraryFunctions::display_404_page();		
			}

			if(!$formwriter->antispam_question_check($data, 'blog')){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}

			$captcha_success = $formwriter->captcha_check($data, 'blog');
			if (!$captcha_success) {
				$errormsg = 'Sorry, you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
		}

		$comment = new Comment(NULL);  
		if($session->get_user_id()){
			$comment->set('cmt_usr_user_id', $session->get_user_id()); 
		}
		
		$safe_comment = strip_tags(iconv(mb_detect_encoding($data['cmt'], mb_detect_order(), true), "UTF-8", $data['cmt']));
		$safe_name = strip_tags(iconv(mb_detect_encoding($data['name'], mb_detect_order(), true), "UTF-8", $data['name']));
		
		if(isset($data['cmt_comment_id_parent'])){
			$comment->set('cmt_comment_id_parent', $data['cmt_comment_id_parent']);
		}
		$comment->set('cmt_pst_post_id', $post_id);
		$comment->set('cmt_author_name', $safe_name);
		$comment->set('cmt_body', $safe_comment);
		if($settings->get_setting('default_comment_status') == 'approved'){
			$comment->set('cmt_is_approved', TRUE);
		}
		else{
			$comment->set('cmt_is_approved', FALSE);
		}
		$comment->prepare();	
		$comment->save();	

		return $comment;
	}

}

class MultiComment extends SystemMultiBase {
	protected static $model_class = 'Comment';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['author_id'])) {
            $filters['cmt_usr_user_id'] = [$this->options['author_id'], PDO::PARAM_INT];
        }
    
        if (isset($this->options['approved'])) {
            $filters['cmt_is_approved'] = $this->options['approved'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['cmt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        if (isset($this->options['post_id'])) {
            $filters['cmt_pst_post_id'] = [$this->options['post_id'], PDO::PARAM_INT];
        }
                
        if (isset($this->options['parent_id'])) {
            $filters['cmt_comment_id_parent'] = [$this->options['parent_id'], PDO::PARAM_INT];
        }
    
        if (isset($this->options['has_parent_id'])) {
            if($this->options['has_parent_id']){
                $filters['cmt_comment_id_parent'] = "IS NOT NULL";
            }
            else{
                $filters['cmt_comment_id_parent'] = "IS NULL";
            }
        }

        return $this->_get_resultsv2('cmt_comments', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
