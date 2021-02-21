<?php

class Pager{

	private $numperpage = NULL;
	private $numrecords = NULL;
	private $prefix = NULL;
	private $remaining_url_vars = NULL;
	private $remaining_var_string = NULL;
	private $offset = NULL;
	private $sort = NULL;
	private $sdirection = NULL;
	private $searchterm = NULL;
	private $numpagestotal = NULL;
	private $currentpage = NULL;
	private $currentfile = NULL;
	

	function __construct($options=array(), $prefix=''){
		
		$url = $_SERVER[REQUEST_URI];
		if($options[getvars]){
			$url = $options[getvars];
		}

		if($options[numperpage]){
			$this->numperpage = $options[numperpage];
		}
		else{
			$this->numperpage = 30;
		}

		$this->numrecords = $options[numrecords];
		$this->prefix = $prefix;

		$url_pieces = parse_url($url);
		parse_str($url_pieces[query], $url_vars);
		
		$this->currentfile = $url_pieces['path'];

		if(isset($url_vars[$prefix . 'offset'])){
			$this->offset = $url_vars[$prefix . 'offset'];
			unset($url_vars[$prefix . 'offset']);
		}

		if(isset($url_vars[$prefix . 'sort'])){
			$this->sort = $url_vars[$prefix . 'sort'];
			unset($url_vars[$prefix . 'sort']);
		}

		if(isset($url_vars[$prefix . 'sdirection'])){
			$this->sdirection = $url_vars[$prefix . 'sdirection'];
			unset($url_vars[$prefix . 'sdirection']);
		}
		
		if(isset($url_vars[$prefix . 'searchterm'])){
			$this->searchterm = $url_vars[$prefix . 'searchterm'];
			unset($url_vars[$prefix . 'searchterm']);
		}
		
		$this->remaining_url_vars = $url_vars;
		
		foreach($url_vars as $key => $value){
			$this->remaining_var_string .= '&'. $key.'='.$value;
		}

		$self = $_SERVER['PHP_SELF'];

		$this->numpagestotal = ceil($this->numrecords/$this->numperpage);	
		$this->currentpage = floor($this->offset / $this->numperpage)+1;
	}	

	function prefix(){
		return $this->prefix;
	}
	
	function url_vars(){
		return $this->remaining_url_vars;
	}
	
	function num_per_page(){
		return $this->numperpage;
	}
	
	function search_term(){
		return $this->searchterm;
	}
	
	function num_records(){
		return $this->numrecords;
	}
	
	function sort(){
		return $this->sort;
	}
	
	function sort_direction(){
		return $this->sdirection;
	}

	function total_pages(){
		return $this->numpagestotal;
	}		
	
	function is_valid_page($page_string){
		if($page_string[0] == '+'){
			$page_add = substr($page_string, 1);
			$page_number = $this->currentpage + $page_add;
		}
		else if($page_string[0] == '-'){
			$page_sub = substr($page_string, 1);
			$page_number = $this->currentpage - $page_sub;
		}
		else{
			$page_number = $page_string;
		}
		

		
		if($page_number < 1){
			return false;
		}

		if($page_number > $this->numpagestotal){
			return false;
		}
					
		return $page_number;
	}
	
	function current_page(){
		return $this->currentpage;
	}
	
	function records_per_page(){
		return $this->numperpage;
	}
	
	function current_record_start(){
		return $this->currentpage * $this->numperpage;
	}
	
	function current_record_end(){
		return ($this->currentpage * $this->numperpage) + ($this->numperpage - 1);
	}
	
	function get_url($page_string=NULL, $new_page=NULL){
		if(!$page_string){
			$page_number = $this->currentpage;
		}
		
		if(!$new_page){
			$new_page = $this->currentfile;
		}		
		
		if(!$page_number = $this->is_valid_page($page_string)){
			return false;
		}

		$newoffset = ($page_number * $this->numperpage) - $this->numperpage;		

		$new_get_vars = array();
		if($newoffset){
			$new_get_vars[] = $this->prefix.'offset='.$newoffset;
		}

		if($this->sort){
			$new_get_vars[] = $this->prefix.'sort='.$this->sort;
		}
		
		if($this->sdirection){
			$new_get_vars[] = $this->prefix.'sdirection='.$this->sdirection;
		}
		
		if($this->searchterm){
			$new_get_vars[] = $this->prefix.'searchterm='.$this->searchterm;
		}
		$out = $new_page.'?'.implode('&', $new_get_vars);
		$out .= $this->remaining_var_string;
		return $out;
		
	}

}

?>
