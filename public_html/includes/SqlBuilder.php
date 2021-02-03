<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');

class SQLBuilder {

	function __construct($table, $primary_key, $limit, $offset, $operation, $write_lock) {
		$this->table = $table;
		$this->primary_key = $primary_key;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->operation = $operation;
		$this->write_lock = $write_lock;

		$this->where_clauses = array();
		$this->bind_params = array();
		$this->join_clauses = array();
		$this->order_by_clauses = array();

		$this->use_two_pass_query = FALSE;
	}

	function bind($params) {
		foreach ($params as $param) {
			$this->bind_params[] = $param;
		}
	}

	function where($clause, $params=array()) {
		$this->where_clauses[] = $clause;
		$this->bind($params);
	}

	function where_boolean($column, $value=TRUE) {
		$this->where($column . ' = ' . ($value ? 'TRUE' : 'FALSE'));
	}

	function where_ilike($column, $value) {
		$this->where($column . ' ILIKE ?', array(array($value, PDO::PARAM_STR)));
	}

	function where_not_null($column, $value=TRUE) {
		$this->where($column . ' ' . ($value ? 'IS NOT NULL' : 'IS NULL'));
	}

	function inner_join($other_table, $join_clause) {
		$this->join_clauses[] = 
			'INNER JOIN ' . $other_table . ' ON ' . $join_clause;
	}

	function left_join($other_table, $join_clause) {
		$this->join_clauses[] = 
			'LEFT JOIN ' . $other_table . ' ON ' . $join_clause;
	}

	function order_by($column, $direction, $nulls_last=NULL, $params=array()) {
		$clause = $column . ' ' . $direction;
		if ($nulls_last === TRUE) {
			$clause .= ' NULLS LAST';
		} else if ($nulls_last === FALSE) {
			$clause .= ' NULL FIRST';
		}
		$this->order_by_clauses[] = $clause;
	}

	function order_by_random() {
		$this->order_by('RANDOM()', 'ASC');
		$this->use_two_pass_query = TRUE;
	}

	function count_sql() {
		$sql = 'SELECT COUNT(1) as total_count FROM ' . $this->table . ' ';
		$sql .= implode(' ', array_unique($this->join_clauses));
		$sql .= $this->_where_clause();
		return $sql;
	}

	private function _where_clause() {
		$sql = '';
		if (count($this->where_clauses)) {
			$sql .= ' WHERE ';
			$sql .= implode(' ' . $this->operation . ' ', $this->where_clauses);
		}
		return $sql;
	}

	function sql() {
		if (!$this->use_two_pass_query) {
			$sql = 'SELECT * FROM ' . $this->table . ' ';
			$sql .= implode(' ', array_unique($this->join_clauses));

			$sql .= $this->_where_clause();

			if (count($this->order_by_clauses)) {
				$sql .= ' ORDER BY ' . implode(', ', $this->order_by_clauses);
			}

			if ($this->limit !== NULL) {
				$sql .= ' LIMIT ' . $this->limit;
			}

			if ($this->offset !== NULL) {
				$sql .= ' OFFSET ' . $this->offset;
			}

			if ($this->write_lock === TRUE) {
				$sql .= ' FOR UPDATE';
			}
		} else {
			$sql = 'SELECT * FROM ' . $this->table . ' ';
			$sql .= 'WHERE ' . $this->primary_key . ' IN (';
			$sql .= 'SELECT ' . $this->primary_key . ' FROM ' . $this->table . ' ';
			$sql .= implode(' ', array_unique($this->join_clauses));

			$sql .= $this->_where_clause();

			if (count($this->order_by_clauses)) {
				$sql .= ' ORDER BY ' . implode(', ', $this->order_by_clauses);
			}

			if ($this->limit !== NULL) {
				$sql .= ' LIMIT ' . $this->limit;
			}

			if ($this->offset !== NULL) {
				$sql .= ' OFFSET ' . $this->offset;
			}

			$sql .= ')';

			if ($this->write_lock === TRUE) {
				$sql .= ' FOR UPDATE';
			}
		}

		return $sql;
	}

	function result() {
		$statement = DbConnector::GetPreparedStatement($this->sql());

		$total_params = count($this->bind_params);
		for($i=0;$i<$total_params;$i++) {
			list($param, $type) = $this->bind_params[$i];
			$statement->bindValue($i+1, $param, $type);
		}
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		return $statement;
	}

	function row_count() {
		$statement = DbConnector::GetPreparedStatement($this->count_sql());

		$total_params = count($this->bind_params);
		for($i=0;$i<$total_params;$i++) {
			list($param, $type) = $this->bind_params[$i];
			$statement->bindValue($i+1, $param, $type);
		}
		$statement->execute();
		$statement->setFetchMode(PDO::FETCH_OBJ);
		return $statement;
	}
}

?>
