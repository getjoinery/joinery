<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');

class CommentException extends SystemClassException {}
class CommentNotSentException extends CommentException {};

class Comment extends SystemBase {

	public static $prefix = 'cmt';
	public static $tablename = 'cmt_comments';
	public static $pkey_column = 'cmt_comment_id';
	public static $permanent_delete_actions = array(
		'cmt_comment_id_parent' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'cmt_comment_id' => 'Comment id',
		'cmt_comment_id_parent' => 'Parent comment for threaded',
		'cmt_usr_user_id' => 'Comment author',
		'cmt_author_name' => 'Author name',
		'cmt_pst_post_id' => 'Post to attach to the comment',
		'cmt_body' => 'The comment',
		'cmt_created_time' => 'Time_sent',
		'cmt_is_approved' => 'Is it deleted',
		'cmt_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'cmt_comment_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cmt_comment_id_parent' => array('type'=>'int4'),
		'cmt_usr_user_id' => array('type'=>'int4'),
		'cmt_author_name' => array('type'=>'varchar(255)'),
		'cmt_pst_post_id' => array('type'=>'int4'),
		'cmt_body' => array('type'=>'text'),
		'cmt_created_time' => array('type'=>'timestamp(6)'),
		'cmt_is_approved' => array('type'=>'bool'),
		'cmt_delete_time' => array('type'=>'timestamp(6)'),
	);


	public static $timestamp_fields = array(
		'usr_email_is_verified_time', 'usr_lastlogin_time', 'usr_admin_disabled_time',
		'usr_signup_date');


	public static $required_fields = array(
		'cmt_body', 'cmt_pst_post_id');

	public static $field_constraints = array(

	);
	
	public static $zero_variables = array(
				);
				
	public static $initial_default_values = array(
		'cmt_created_time'=> 'now()',
		'cmt_is_approved' => TRUE,
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
		
			$formwriter = LibraryFunctions::get_formwriter_object();

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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Comment($row->cmt_comment_id);
			$child->load_from_data($row, array_keys(Comment::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}



?>
