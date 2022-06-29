<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');


class PublicMenuException extends SystemClassException {}

class PublicMenu extends SystemBase {
	public static $prefix = 'pmu';
	public static $tablename = 'pmu_public_menus';
	public static $pkey_column = 'pmu_public_menu_id';
	public static $permanent_delete_actions = array(
		'pmu_public_menu_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'pmu_public_menu_id' => 'ID of the public_menu',
		'pmu_name' => 'Display Name', 
		'pmu_link' => 'link to the page, starting with a slash',
		'pmu_is_active' => 'Is this public_menu active?',
		'pmu_parent_menu_id' => 'pmu_public_menu_id of parent if a subitem',
		'pmu_order' => 'Order of appearance'
	);

	public static $field_specifications = array(
		'pmu_public_menu_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pmu_name' => array('type'=>'varchar(100)'),
		'pmu_link' => array('type'=>'varchar(100)'),
		'pmu_is_active' => array('type'=>'bool'),
		'pmu_parent_menu_id' => array('type'=>'int4'),
		'pmu_order' => array('type'=>'int2'),
	);	

	public static $required_fields = array(
		'pmu_name', 'pmu_link');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'pmu_is_active' => 1, 
		);		
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();

		if ($session->get_permission() < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this public_menu.');
		}

	}

}

class MultiPublicMenu extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('pmu_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	
	static function get_sorted_array(){

		$menus = new MultiPublicMenu(
			array(),  //SEARCH CRITERIA
			array('order'=>'ASC'),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
			100,  //NUM PER PAGE
			0,  //OFFSET
			'OR');
		$menus->load();	
		$menus2 = $menus;

		$sorted_menu = array();
		foreach ($menus as $menu){
			if($menu->get('pmu_is_active')){
				if(!$menu->get('pmu_parent_menu_id')){
					$sorted_menu[$menu->key]['parent'] = true;
				}
				else{
					continue;
				}
				$sorted_menu[$menu->key]['id'] = $menu->key;
				$sorted_menu[$menu->key]['name'] = $menu->get('pmu_name');
				$sorted_menu[$menu->key]['order'] = $menu->get('pmu_order');
				$sorted_menu[$menu->key]['link'] = $menu->get('pmu_link');

				$submenu = array();
				foreach ($menus2 as $menu2){
					if($menu->key == $menu2->get('pmu_parent_menu_id')){
						$submenu[$menu2->key]['id'] = $menu2->key;
						$submenu[$menu2->key]['name'] = $menu2->get('pmu_name');
						$submenu[$menu2->key]['order'] = $menu2->get('pmu_order');
						$submenu[$menu2->key]['link'] = $menu2->get('pmu_link');
						$sorted_menu[$menu2->key]['parent'] = false;
					}
				}
				$sorted_menu[$menu->key]['submenu'] = $submenu;
			}
		}

		return $sorted_menu;
	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('public_menu_id', $this->options)) {
			$where_clauses[] = 'pmu_public_menu_id = ?';
			$bind_params[] = array($this->options['public_menu_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('parent_menu_id', $this->options)) {
			$where_clauses[] = 'pmu_parent_menu_id = ?';
			$bind_params[] = array($this->options['parent_menu_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('has_parent_menu_id', $this->options)) {
			$where_clauses[] = '(pmu_parent_menu_id IS NOT NULL OR pmu_parent_menu_id != 0)';
		}
		
		if (array_key_exists('has_no_parent_menu_id', $this->options)) {
			$where_clauses[] = '(pmu_parent_menu_id IS NULL OR pmu_parent_menu_id = 0)';
		}
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM pmu_public_menus ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM pmu_public_menus
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " pmu_public_menu_id ASC ";
			}
			else {
				if (array_key_exists('public_menu_id', $this->order_by)) {
					$sql .= ' pmu_public_menu_id ' . $this->order_by['public_menu_id'];
				}		
				if (array_key_exists('order', $this->order_by)) {
					$sql .= ' pmu_order ' . $this->order_by['order'];
				}	
			}				

			$sql .= ' '.$this->generate_limit_and_offset();				
		}
		

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new PublicMenu($row->pmu_public_menu_id);
			$child->load_from_data($row, array_keys(PublicMenu::$fields));
			$this->add($child);
		}
	}
}


?>
