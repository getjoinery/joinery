<?php

define('SINGLE_ROW_ALL_COLUMNS', -1);
define('ALL_COLUMNS', -1);

function _RowFetch($table, $key_column, $key_value, $key_pdo_type,
		$result_columns, $order_by=NULL, $order_direction=NULL, $limit=NULL, $for_update=FALSE) {

	if (!$key_value) {
		return NULL;
	}

	require_once('DbConnector.php');
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	if ($result_columns === ALL_COLUMNS) {
		$sql_select = '*';
	} else if (gettype($result_columns) == 'array') {
		$sql_select = implode($result_columns, ',');
	} else {
		$sql_select = $result_columns;
	}

	$sql = 'SELECT ' . $sql_select . '
		FROM ' . $table . ' 
		WHERE ' . $key_column . ' = :key_value';

	if ($order_by !== NULL && $order_direction !== NULL) {
		$sql .= ' ORDER BY ' . $order_by . ' ' . $order_direction;
	}

	if ($limit !== NULL) {
		$sql .= ' LIMIT ' . $limit;
	}

	if ($for_update) {
		$sql .= ' FOR UPDATE';
	}

	try {
		$q = $dblink->prepare($sql);
		$q->bindParam(':key_value', $key_value, $key_pdo_type);
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	} catch(PDOException $e) {
		$dbhelper->handle_query_error($e);
	}

	if (!$q->rowCount()) {
		return NULL;
	}

	return $q;
}

/**
* This function accesses a single row from a single table, given
* a field and a key.  It can select either one column, multiple columns
* or all the columns in the row.  The columns are returned as an object.
* If you pass in a key/value that has multiple results, only the first
* row will be returned, in random order.  IE, do not use this function
* if you are querying for a key that has more than 1 possible results.
*
* NOTE: Only $key_value is protected from SQL injection attacks, it is not
* safe to pass any user submitted data into any other field.

* @param string $table The name of the table
* @param string $key_column The name of the column you wish to use as a key
* @param mixed $key_value The value of the key column you are looking for
* @param PDO::enum $key_pdo_type The PDO type of the key you are looking for
* @param mixed $result_columns The names of the result columns you are
*		looking for.  Pass a string, and it will be a single column, pass
*		an array and it will return those list, pass the special value
*		SINGLE_ROW_ALL_COLUMNS to return all the columns
* @param boolean $for_update Whether or not we should retrieve this row
* with an exclusive lock

* @return mixed An object where you can access the columns you requested, or
*		NULL if no results were returned.
*/
function SingleRowFetch($table, $key_column, $key_value, $key_pdo_type,
		$result_columns, $for_update=FALSE) {
	$q = _RowFetch($table, $key_column, $key_value, $key_pdo_type, $result_columns, NULL, NULL, NULL, $for_update);

	if (!$q) {
		return NULL;
	}

	// We just return the first row, regardless of if there are more
	return $q->fetch();
}


/**
* This function accesses multiple row from a single table, given
* a field and a key.  It can select either one column, multiple columns
* or all the columns in the row.  The columns are returned as an object.
* If you pass in a key/value that has multiple results, only the first
* row will be returned, in random order.

* NOTE: Only $key_value is protected from SQL injection attacks, it is not
* safe to pass any user submitted data into any other field.

* @param string $table The name of the table
* @param string $key_column The name of the column you wish to use as a key
* @param mixed $key_value The value of the key column you are looking for
* @param PDO::enum $key_pdo_type The PDO type of the key you are looking for
* @param mixed $result_columns The names of the result columns you are
*		looking for.  Pass a string, and it will be a single column, pass
*		an array and it will return those list, pass the special value
*		SINGLE_ROW_ALL_COLUMNS to return all the columns

* @return mixed An object where you can access the columns you requested, or
*		NULL if no results were returned.
*/
function MultipleRowFetch($table, $key_column, $key_value, $key_pdo_type,
		$result_columns, $order_by=NULL, $order_direction=NULL, $limit=NULL) {
	$q = _RowFetch($table, $key_column, $key_value, $key_pdo_type, $result_columns,
		$order_by, $order_direction, $limit);

	if (!$q) {
		return NULL;
	}

	// We just return the first row, regardless of if there are more
	return $q->fetchAll();
}

?>
