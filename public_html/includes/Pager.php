<?php

class Pager{

	private $numperpage = NULL;
	private $numrecords = NULL;
	private $prefix = NULL;
	private $numpagestotal = NULL;
	private $currentpage = NULL;
	private $base_url = NULL;
	
	private $url_vars = array();	

	private $remaining_url_vars = NULL;
	private $remaining_var_string = NULL;
	private $offset = NULL;
	private $sort = NULL;
	private $filter = NULL;
	private $sdirection = NULL;
	private $searchterm = NULL;
	

	function __construct($options=array(), $prefix=''){
		
		$url = $_SERVER['REQUEST_URI'];
		if(isset($options['getvars']) && $options['getvars']){
			$url = $options['getvars'];
		}

		if(isset($options['numperpage']) && $options['numperpage']){
			$this->numperpage = $options['numperpage'];
		}
		else{
			$this->numperpage = 30;
		}
		

		$this->numrecords = $options['numrecords'] ?? 0;
		$this->prefix = $prefix;

		$url_pieces = parse_url($url);
		parse_str($url_pieces['query'], $url_vars);
		$this->url_vars = $url_vars;
		$this->base_url = $url_pieces['path'];

		if(isset($options['offset']) && $options['offset']){
			$this->offset = $options['offset'];
			$this->url_vars['offset'] = $options['offset'];
			unset($url_vars[$prefix . 'offset']);
		}
		else{
			if(isset($url_vars[$prefix . 'offset'])){
				$this->offset = $url_vars[$prefix . 'offset'];
				unset($url_vars[$prefix . 'offset']);
			}
		}

		if(isset($options['sort']) && $options['sort']){
			$this->sort = $options['sort'];
			$this->url_vars['sort'] = $options['sort'];
			unset($url_vars[$prefix . 'sort']);
		}
		else{
			if(isset($url_vars[$prefix . 'sort'])){
				$this->sort = $url_vars[$prefix . 'sort'];
				unset($url_vars[$prefix . 'sort']);
			}
		}
		
		if(isset($options['filter']) && $options['filter']){
			$this->filter = $options['filter'];
			$this->url_vars['filter'] = $options['filter'];	
			unset($url_vars[$prefix . 'filter']);			
		}
		else{
			if(isset($url_vars[$prefix . 'filter'])){
				$this->filter = $url_vars[$prefix . 'filter'];
				unset($url_vars[$prefix . 'filter']);
			}
		}

		if(isset($options['sdirection']) && $options['sdirection']){
			$this->sdirection = $options['sdirection'];
			$this->url_vars['sdirection'] = $options['sdirection'];
			unset($url_vars[$prefix . 'sdirection']);	
		}
		else{
			if(isset($url_vars[$prefix . 'sdirection'])){
				$this->sdirection = $url_vars[$prefix . 'sdirection'];
				unset($url_vars[$prefix . 'sdirection']);
			}
		}
		
		if(isset($options['searchterm']) && $options['searchterm']){
			$this->searchterm = $options['searchterm'];
			$this->url_vars['searchterm'] = $options['searchterm'];
			unset($url_vars[$prefix . 'searchterm']);	
		}
		else{
			if(isset($url_vars[$prefix . 'searchterm'])){
				$this->searchterm = $url_vars[$prefix . 'searchterm'];
				unset($url_vars[$prefix . 'searchterm']);
			}
		}
		
		$this->remaining_url_vars = $url_vars;
		
		foreach($url_vars as $key => $value){
			$this->remaining_var_string .= '&'. $key.'='.$value;
		}
		
		if(is_null($this->offset)){
			$this->offset = 0;
		}

		if($this->numrecords){
			$this->numpagestotal = ceil($this->numrecords/$this->numperpage);	
		}
		else{
			$this->numpagestotal = 1;	
		}
		
		$this->currentpage = floor($this->offset / $this->numperpage)+1;
	}	

	function prefix(){
		return $this->prefix;
	}
	
	function base_url(){
		return $this->base_url;
	}
	
	function url_vars(){
		return $this->remaining_url_vars;
	}
	
	function search_term(){
		return $this->searchterm;
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
	
	function num_per_page(){
		if(is_null($this->numperpage)){
			throw new SystemDisplayablePermanentError("Numperpage is not set for paging.");
		}
		return $this->numperpage;
	}
	
	function num_records(){
		return $this->numrecords;
	}
	

	function total_pages(){
		if(is_null($this->numpagestotal)){
			throw new SystemDisplayablePermanentError("Numpagestotal is not set for paging.");
		}
		return $this->numpagestotal;
	}		
	
	function is_valid_page($page_string){
		if(is_null($this->currentpage)){
			throw new SystemDisplayablePermanentError("Current page is not set for paging.");
		}

		if(is_null($this->numpagestotal)){
			throw new SystemDisplayablePermanentError("Numpagestotal is not set for paging.");
		}
		
		$page_string_str = (string)$page_string;
		if($page_string_str[0] == '+'){
			$page_add = substr($page_string_str, 1);
			$page_number = $this->currentpage + $page_add;
		}
		else if($page_string_str[0] == '-'){
			$page_sub = substr($page_string_str, 1);
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
		if(is_null($this->currentpage)){
			throw new SystemDisplayablePermanentError("Currentpage is not set for paging.");
		}
		return $this->currentpage;
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
		if(is_null($this->numperpage)){
			throw new SystemDisplayablePermanentError("Numperpage is not set for paging.");
		}
		return $this->numperpage;
	}
	
	function current_record_start(){
		if(is_null($this->numperpage)){
			throw new SystemDisplayablePermanentError("Numperpage is not set for paging.");
		}
		
		if(is_null($this->currentpage)){
			throw new SystemDisplayablePermanentError("Currentpage is not set for paging.");
		}
		
		return ($this->currentpage - 1) * $this->numperpage + 1;
	}
	
	function current_record_end(){
		if(is_null($this->numperpage)){
			throw new SystemDisplayablePermanentError("Numperpage is not set for paging.");
		}
		
		if(is_null($this->currentpage)){
			throw new SystemDisplayablePermanentError("Currentpage is not set for paging.");
		}
		
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

	/**
	 * Output record count info for card footers
	 * Shows "X records" or "X records (showing Y)" format
	 *
	 * @param int $showing_count Number of records currently displayed
	 * @return string HTML output for record count display
	 */
	function record_count_info($showing_count = null) {
		if ($this->numrecords == 0) {
			return '';
		}

		$output = '<div class="card-footer bg-body-tertiary py-2">';
		$output .= '<div class="fs-10 text-600">';
		$output .= $this->numrecords . ' record' . ($this->numrecords != 1 ? 's' : '');

		if ($showing_count !== null && $showing_count < $this->numrecords) {
			$output .= ' (showing ' . $showing_count . ')';
		}

		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

}

?>
