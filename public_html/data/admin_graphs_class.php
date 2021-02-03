<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once('jpgraph/jpgraph.php');
require_once('jpgraph/jpgraph_line.php');
require_once('jpgraph/jpgraph_bar.php');
require_once('jpgraph/jpgraph_log.php');

class AdminGraphException extends SystemClassException {}

class AdminGraph extends SystemBase {

	public static $fields = array(
		'agp_admin_graph_id' => 'ID for the graph',
		'agp_graph_title' => 'Title for the graph',
		'agp_graph_sql' => 'SQL for the graph',
		'agp_hidden' => 'Is this graph hidden',
	);

	static function GetSections() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT LOWER(agt_tag) as agt_tag, COUNT(*) as count
			FROM agt_graph_tags
			JOIN agp_admin_graphs ON agt_agp_admin_graph_id = agp_admin_graph_id
			WHERE agp_hidden = FALSE
			GROUP BY LOWER(agt_tag)
			ORDER BY LOWER(agt_tag)';

		$q = $dblink->prepare($sql);
		$q->setFetchMode(PDO::FETCH_ASSOC);
		$q->execute();

		$results = $q->fetchAll();

		$section_list = array();
		foreach($results as $result) {
			$section_list[$result['agt_tag']] = $result['count'];
		}
		return $section_list;
	}

	function set_tags($tags) {
		if ($this->key === NULL) {
			throw new AdminGraphException('Can\'t set tags until admin graph is saved.');
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$dblink->beginTransaction();

		$dblink->exec('DELETE FROM agt_graph_tags WHERE agt_agp_admin_graph_id = ' . $this->key);

		foreach($tags as $tag) {
			$q = $dblink->prepare(
				'INSERT INTO agt_graph_tags VALUES (?, ?)');
			$q->bindValue(1, $this->key, PDO::PARAM_INT);
			$q->bindValue(2, trim($tag), PDO::PARAM_STR);
			$q->execute();
		}

		$dblink->commit();
	}

	function get_tags() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT agt_tag FROM agt_graph_tags WHERE agt_agp_admin_graph_id = ' . $this->key;

		$q = $dblink->prepare($sql);
		$q->setFetchMode(PDO::FETCH_ASSOC);
		$q->execute();

		$results = $q->fetchAll();

		$section_list = array();
		foreach($results as $result) {
			$section_list[] = $result['agt_tag'];
		}
		return $section_list;
	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('agp_admin_graphs', 'agp_admin_graph_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new AdminGraphException(
				'This graph does not exist');
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('agp_admin_graph_id' => $this->key);
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['agp_admin_graph_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'agp_admin_graphs', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['agp_admin_graph_id'];
	}

	function generate_graph($width=900, $height=400) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$dblink->beginTransaction();
		$dblink->exec('SET TRANSACTION READ ONLY');

		$q = $dblink->prepare($this->get('agp_graph_sql'));
		$q->setFetchMode(PDO::FETCH_ASSOC);
		$q->execute();

		$x_data = array();
		$bar_data = array();
		$line_data = array();

		$graph = new Graph($width, $height);
		$graph->setClipping(FALSE);
		$graph->SetScale('textlin');
		$graph->setMargin(40, 40, 20, 65);
		$graph->legend->setPos(0.5, 0.98, 'center', 'bottom');
		$graph->legend->SetLayout(LEGEND_HOR);

		$smooth_keys = array();

		foreach($q->fetchAll() as $row) {
			foreach($row as $key => $value) {
				list($key_type, $key_name) = explode('_', $key, 2);
				switch($key_type) {
					case 'x':
						// X-axis
						$x_data[$key_name][] = $value;
						break;
					case 'bar':
						$bar_data[$key_name][] = $value;
						$graph->SetY2Scale('lin');
						break;
					case 'smoothline':
						list($smooth_amount, $key_name) = explode('_', $key_name, 2);
						$smooth_keys[$key_name] = $smooth_amount;
					case 'line':
						$line_data[$key_name][] = $value;
						break;
					case 'log':
						if ($key_name == 'bar') {
							$graph->SetY2Scale('log');
						} else {
							$graph->SetScale('textlog');
						}
				}
			}
		}

		foreach($smooth_keys as $line_name => $smooth_factor) {
			$new_data = array();
			$old_data = $line_data[$line_name];

			$smooth_depth = intval($smooth_factor / 2);
			$old_data_count = count($old_data);
			for($i=0;$i<$old_data_count;$i++) {
				$values = array();
				for($j=-$smooth_depth;$j<=$smooth_depth;$j++) {
					$spot = $i + $j;
					if ($spot >= 0 && $spot < $old_data_count) {
						$values[] = $old_data[$spot];
					}
				}
				$new_data[] = array_sum($values) / count($values);
			}

			$line_data[$line_name] = $new_data;
		}

		// End the transaction, we are done with it
		$dblink->rollBack();

		foreach($x_data as $key => $value) {
			$graph->xaxis->title->Set($key);
			$graph->xaxis->SetTickLabels($value);
		}

		$colors = array_reverse(
			array('#8DD3C7', '#BEBADA', '#FB8072', '#80B1D3', '#FDB462', '#B3DE69', '#FCCDE5', '#BC80BD', '#A65628',
						'#386CB0', '#000000', '#D53E4F'));

		if ($bar_data) {
			if (count($bar_data) > 1) {
				$bar_plots = array();
				foreach($bar_data as $key => $value) {
					$bar_plot = new BarPlot($value);
					$bar_plot->SetLegend($key);
					$bar_plot->SetFillColor(array_pop($colors));
					$bar_plots[] = $bar_plot;
				}

				$graph->AddY2(new GroupBarPlot($bar_plots));
			} else {
				foreach($bar_data as $key => $value) {
					$bar_plot = new BarPlot($value);
					$bar_plot->SetLegend($key);
					$bar_plot->SetFillColor(array_pop($colors));
					$graph->AddY2($bar_plot);
				}
			}
		}

		foreach($line_data as $key => $value) {
			$line_plot = new LinePlot($value);
			$line_plot->SetLegend($key);
			$line_plot->SetWeight(2);
			$line_plot->SetColor(array_pop($colors));

			if ($bar_data) {
				$line_plot->SetBarCenter();
			}

			$graph->Add($line_plot);
		}

		$graph->Stroke($this->get_local_file());
	}

	function get_local_file() {
		return '/var/www/global_dirs/admin_graphs/' . $this->get_code() . '.png';
	}

	function get_code_img_src() {
		return '/admin/admin_graph_view?code=' . $this->get_code();
	}

	function get_code() {
		return md5($this->get('agp_graph_sql'));
	}

}

class MultiAdminGraph extends SystemMultiBase {

	private function _get_results($only_count=FALSE) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('hidden', $this->options)) {
			$where_clauses[] = 'agp_hidden = ' . ($this->options['hidden'] ? 'TRUE' : 'FALSE');
		}

		if (array_key_exists('tag', $this->options)) {
			$where_clauses[] = 'LOWER(agt_tag) = ?';
			$bind_params[] = array(strtolower($this->options['tag']), PDO::PARAM_STR);
		}

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM agp_admin_graphs
				INNER JOIN agt_graph_tags ON agt_agp_admin_graph_id = agp_admin_graph_id
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM agp_admin_graphs
				INNER JOIN agt_graph_tags ON agt_agp_admin_graph_id = agp_admin_graph_id
				' . $where_clause . '
				ORDER BY agp_graph_title ASC' . $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new AdminGraph($row->agp_admin_graph_id);
			$child->load_from_data($row, array_keys(AdminGraph::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}


?>
