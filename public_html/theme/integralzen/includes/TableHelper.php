<?php

class RowAlternate {
	function __construct($options) {
		$this->options = $options;
		$this->option_count = count($options);
		$this->current_count = 0;
	}

	function next_row_class() {
		++$this->current_count;
		return $this->options[$this->current_count % $this->option_count];
	}

	function get_count() {
		return $this->current_count;
	}
}

class GenericTable {
	function __construct($row_alternator=NULL) {
		$this->row_alternator = $row_alternator;
		?>
    	<table width="100%" cellspacing="0" cellpadding="0" border="0" class="generic-table"> 
		<?php
	}

	function add_headers($headers) {
		echo '<thead><tr>';
		$header_count = count($headers);
		for($i=0;$i<$header_count;++$i) {
			if ($i == 0) {
				echo '<th scope="col" class="first">';
			} else if ($i == ($header_count - 1)) {
				echo '<th scope="col" class="last">';
			} else {
				echo '<th>';
			}
			echo "$headers[$i]</th>";
		}
		echo "</tr></thead><tbody>";
	}

	function add_row($row) {
		if ($this->row_alternator) {
			echo '<tr class="' . $this->row_alternator->next_row_class() . '">';
		} else {
			echo '<tr>';
		}

		$column_count = count($row);
		for($i=0;$i<$column_count;++$i) {
			if ($i == ($column_count - 1)) {
				echo '<td class="last">';
			} else {
				echo '<td>';
			}
			echo "$row[$i]</td>";
		}
		echo "</tr>";
	}

	function end_table() {
		?>
          </tbody>
         </table>
		<?php		
	}

}

?>
