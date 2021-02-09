<?php
	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('email_recipient_id', $this->options)) {
		 	$where_clauses[] = 'erc_erc_email_recipient_id = ?';
		 	$bind_params[] = array($this->options['email_recipient_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'erc_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}			

		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'erc_is_deleted = ' . ($this->options['deleted'] ? 'TRUE' : 'FALSE');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM erc_email_recipients ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM erc_email_recipients
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " erc_email_recipient_id ASC ";
			}
			else {
				if (array_key_exists('email_recipient_id', $this->order_by)) {
					$sql .= ' erc_email_recipient_id ' . $this->order_by['email_recipient_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new EmailRecipient($row->erc_email_recipient_id);
			$child->load_from_data($row, array_keys(EmailRecipient::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
	?>