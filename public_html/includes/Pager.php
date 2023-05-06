<?php

class Pager{

	private $numperpage = NULL;
	private $numrecords = NULL;
	private $prefix = NULL;
	private $remaining_url_vars = NULL;
	private $remaining_var_string = NULL;
	private $offset = NULL;
	private $sort = NULL;
	private $filter = NULL;
	private $sdirection = NULL;
	private $searchterm = NULL;
	private $numpagestotal = NULL;
	private $currentpage = NULL;
	private $base_url = NULL;
	private $url_vars = array();
	

	function __construct($options=array(), $prefix=''){
		
		$url = $_SERVER['REQUEST_URI'];
		if($options['getvars']){
			$url = $options['getvars'];
		}

		if($options['numperpage']){
			$this->numperpage = $options['numperpage'];
		}
		else{
			$this->numperpage = 30;
		}
		

		$this->numrecords = $options['numrecords'];
		$this->prefix = $prefix;

		$url_pieces = parse_url($url);
		parse_str($url_pieces['query'], $url_vars);
		$this->url_vars = $url_vars;
		$this->base_url = $url_pieces['path'];

		if(isset($url_vars[$prefix . 'offset'])){
			$this->offset = $url_vars[$prefix . 'offset'];
			unset($url_vars[$prefix . 'offset']);
		}

		if(isset($url_vars[$prefix . 'sort'])){
			$this->sort = $url_vars[$prefix . 'sort'];
			unset($url_vars[$prefix . 'sort']);
		}

		if(isset($url_vars[$prefix . 'filter'])){
			$this->filter = $url_vars[$prefix . 'filter'];
			unset($url_vars[$prefix . 'filter']);
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
	
	function get_sort(){
		return $this->sort;
	}
	
	function get_filter(){
		return $this->filter;
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
	
	function base_url(){
		return $this->base_url;
	}
	
	function url_vars_as_hidden_input($exclude=array()){
		
		$out_string = '';
		
		foreach($this->url_vars as $name=>$value){
			if(!in_array($name, $exclude)){
				$out_string .= '<input type="hidden"  name="'.$prefix . $name.'" value="'.$value.'">';
			}
		}
		return $out_string;
	}
	
	function current_url($exclude=array()){
		$base_url = $this->base_url;
		$prefix = $this->prefix;
		
		$url_vars = array();
		if(isset($this->offset)){
			if(in_array('offset', $exclude)){
				$url_vars[$prefix . 'offset'] = $this->offset;
			}
		}

		if(isset($this->sort)){
			if(in_array('sort', $exclude)){
				$url_vars[$prefix . 'sort'] = $this->sort;
			}
		}
		
		if(isset($this->filter)){
			if(in_array('filter', $exclude)){
				$url_vars[$prefix . 'filter'] = $this->filter;
			}
		}
		
		if(isset($this->sdirection)){
			if(in_array('sdirection', $exclude)){
				$url_vars[$prefix . 'sdirection'] = $this->sdirection;
			}
		}
		
		if(isset($this->searchterm)){
			if(in_array('searchterm', $exclude)){
				$url_vars[$prefix . 'searchterm'] = $this->searchterm;
			}
		}
		
		$url_pieces = array();
		foreach ($url_vars as $name=>$value){
			$url_pieces[] = $prefix . $name.'='.$value;
		}
		$url_string = $base_url .'?'. implode('&', $url_pieces). $this->remaining_var_string;
		return $url_string;
		
	}
	
	function records_per_page(){
		return $this->numperpage;
	}
	
	function current_record_start(){
		return ($this->currentpage - 1) * $this->numperpage + 1;
	}
	
	function current_record_end(){
		return (($this->currentpage - 1) * $this->numperpage) + $this->numperpage;
	}
	
	function get_url($page_string=NULL, $new_page=NULL){
		if(!$page_string){
			$page_number = $this->currentpage;
		}
		
		if(!$new_page){
			$new_page = $this->base_url;
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

		if($this->filter){
			$new_get_vars[] = $this->prefix.'filter='.$this->filter;
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
