# Spec: save() Performance Optimization

## Problem

`SystemBase::save()` delegates all SQL generation and execution to `LibraryFunctions::edit_table()`. That function is a general-purpose utility that doesn't know what class called it, so it performs three expensive lookups to discover information that `save()` already has:

1. **information_schema.columns query** (~2.2ms per save): Queries the database for column types to determine PDO parameter types. But `static::$field_specifications` already has the `'type'` for every field.

2. **discover_model_classes() on INSERT** (~19ms per INSERT): Scans the filesystem with `glob()`, reads every `*_class.php` file, and tokenizes them — just to find `$pkey_column`. But `save()` already knows `static::$pkey_column`.

3. **information_schema.sequences query on INSERT**: Checks if the sequence exists before calling `lastInsertId()`. The sequence name is deterministic (`tablename_pkeycolumn_seq`).

Since `save()` is the only caller of `edit_table()`, the fix is to inline the SQL logic directly into `save()`, using `static::$field_specifications` for type resolution instead of querying the database.

### Benchmark Results

| Component | Current | Optimized | Speedup |
|---|---|---|---|
| Column type lookup (every save) | 2.17 ms | 0.01 ms | 250x |
| INSERT overhead (discovery + sequence) | 19.21 ms | 0.0003 ms | 67,643x |
| Typical form submission (1 INSERT + 2 UPDATEs) | ~24 ms overhead | ~0 ms | - |
| Batch import (100 INSERTs) | ~2,100 ms overhead | ~1 ms | - |

## Changes

### 1. Replace `edit_table()` call in `save()` with inline SQL

Replace the `LibraryFunctions::edit_table()` call and the lines leading up to it (from `$rowdata = array()` through `$this->key = $p_keys_return[...]`) with the code below. All code before this block (validation, defaults, required checks) and after it (cache invalidation) stays unchanged.

The binding code is copied verbatim from `edit_table()`. The only new code is the `$column_meta` building block, which maps `field_specifications` types to the same `data_type` and `is_nullable` strings that `information_schema.columns` returns.

```php
		$rowdata = array();
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array(static::$pkey_column => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata[static::$pkey_column]);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Build column metadata from field_specifications
		// Maps spec types to the same data_type/is_nullable strings that
		// information_schema.columns returns, so the binding code below
		// (copied verbatim from edit_table) works identically.
		$column_meta = array();
		foreach (static::$field_specifications as $col_name => $spec) {
			$spec_type = strtolower(preg_replace('/\(.*\)/', '', $spec['type'] ?? 'varchar'));
			switch ($spec_type) {
				case 'int4':
				case 'integer':
				case 'serial':
					$data_type = 'integer';
					break;
				case 'int2':
				case 'smallint':
					$data_type = 'smallint';
					break;
				case 'int8':
				case 'bigint':
				case 'bigserial':
					$data_type = 'bigint';
					break;
				case 'bool':
				case 'boolean':
					$data_type = 'boolean';
					break;
				case 'json':
					$data_type = 'json';
					break;
				case 'jsonb':
					$data_type = 'jsonb';
					break;
				default:
					$data_type = 'character varying';
					break;
			}
			$column_meta[$col_name]['data_type'] = $data_type;
			// Default to 'YES' (nullable) — PostgreSQL columns are nullable unless
			// explicitly declared NOT NULL. Only field_specifications with
			// 'is_nullable' => false map to 'NO'. This matches information_schema behavior.
			$column_meta[$col_name]['is_nullable'] = (isset($spec['is_nullable']) && $spec['is_nullable'] === false) ? 'NO' : 'YES';
		}

		// --- BEGIN: SQL generation and execution (from edit_table) ---

		if(count($rowdata) == 0){
			return FALSE;
		}

		if(is_null($p_keys)){
			$op = 'add';
			$sql = 'INSERT INTO ' . static::$tablename . ' ';

			$colphrase="";
			$valphrase="";
			foreach($rowdata as $column_name=>$column_val){
				if((string)$column_val != "-NOUPDATE-"){
					$colphrase .= $column_name . ',';
						$valphrase .= ':' . $column_name . ',';

				}
			}

			$colphrase[strlen($colphrase)-1] = ' ';
			$valphrase[strlen($valphrase)-1] = ' ';

			$sql .= '(' . $colphrase . ') VALUES (' . $valphrase . ') ';
		}
		else{
			$op = 'edit';
			$sql = 'UPDATE ' . static::$tablename . ' SET ';

			foreach($rowdata as $column_name=>$column_val){
				if((string)$column_val != "-NOUPDATE-"){
						$sql .= $column_name . '=:' . $column_name . ',';
				}
			}

			$sql[strlen($sql)-1] = ' ';

			//ADD WHERE CLAUSE
			$sql .= 'WHERE ';
			foreach($p_keys as $pname=>$pvalue){
				$sql .= $pname . '=:' . $pname . ' ';
				$sql .= ' AND ';
			}
			//REMOVE THE LAST ' AND '
			$sql = substr($sql, 0, strlen($sql)-5);
		}

		//BIND VALUES AND PREPARE STATEMENT
		$dbhelper->prepare_query($sql);

		foreach($rowdata as $column_name=>$column_val){
			if((string)$column_val != "-NOUPDATE-"){
				if($column_meta[$column_name]['data_type'] == 'integer' || $column_meta[$column_name]['data_type'] == 'smallint'){
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_INT);
				}
				else if($column_meta[$column_name]['data_type'] == 'boolean'){
					if($column_val===NULL){
						if($column_meta[$column_name]['is_nullable'] == 'YES') {
							$dbhelper->bind_value(":$column_name", NULL, PDO::PARAM_BOOL);
						} else {
							$dbhelper->bind_value(":$column_name", FALSE, PDO::PARAM_BOOL);
						}
					}
					else if($column_val==TRUE){
						$dbhelper->bind_value(":$column_name", TRUE, PDO::PARAM_BOOL);
					}
					else if($column_val==FALSE){
						$dbhelper->bind_value(":$column_name", FALSE, PDO::PARAM_BOOL);
					}
				}
				else if($column_meta[$column_name]['data_type'] == 'json' || $column_meta[$column_name]['data_type'] == 'jsonb'){
					// JSON columns - auto-encode arrays/objects
					if (is_array($column_val) || is_object($column_val)) {
						$column_val = json_encode($column_val);
					}
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
				else{
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
			}
		}

		if($op == 'edit'){
			foreach($p_keys as $pname=>$pvalue){
				if($column_meta[$pname]['data_type'] == 'integer' || $column_meta[$pname]['data_type'] == 'smallint'){
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_INT);
				}
				else{
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_STR);
				}
			}
		}

		if($debug){
			$error_var_statement = '<pre>';
			$error_var_statement .= "Table: " . static::$tablename . "\n";
			foreach ($rowdata as $col=>$val){
				$error_var_statement .= "[$col]=>";
				if(is_null($val)) {
					$error_var_statement .= 'NULL';
				}
				else if($val === '') {
					$error_var_statement .= "''";
				}
				else if($val === FALSE) {
					$error_var_statement .= "FALSE";
				}
				else if($val === TRUE) {
					$error_var_statement .= "TRUE";
				}
				else  {
					$error_var_statement .= "$val";
				}
				$error_var_statement .= "\n";
			}
			if(is_null($p_keys)){
				$error_var_statement .= 'pkeys is null ' . "\n";
			}
			$error_var_statement .= 'Number of Keys: '. count($p_keys) . "\n";
			echo $error_var_statement;
			echo '</pre>';
		}

		try {
			$dbhelper->execute_query();
		} catch (PDOException $e) {
			// Add context about the operation
			$operation = $op == 'add' ? 'INSERT' : 'UPDATE';
			$context = "Database $operation failed on table '" . static::$tablename . "'";

			if ($op == 'edit' && $p_keys) {
				$context .= " for record: " . json_encode($p_keys);
			}

			$dbhelper->handle_query_error(
				new PDOException($context . " - " . $e->getMessage(), (int)$e->getCode(), $e)
			);
		}

		if($op == 'edit'){
			if($debug){
				exit;
			}
			$this->key = $p_keys[static::$pkey_column];
		}
		else{
			$seq = static::$tablename . '_' . static::$pkey_column . '_seq';

			if($debug){
				echo "Sequence: $seq\n";
				exit;
			}

			$this->key = $dblink->lastInsertId($seq);
		}

		// --- END: SQL generation and execution ---
```

### 2. Delete `LibraryFunctions::edit_table()`

With `save()` handling its own SQL, `edit_table()` has zero callers:
- `SystemBase::save()` — replaced by inline SQL (step 1)
- `OrderItem::_unsafe_edit()` — dead code being removed (step 3)

Delete the entire `edit_table()` method and its doc block (lines 1202-1460) from `includes/LibraryFunctions.php`.

### 3. Delete dead code: `OrderItem::_unsafe_edit()`

The `_unsafe_edit()` method in `data/order_items_class.php` (lines 89-107) is never called anywhere in the codebase. Delete it.

## Edge Case Analysis

### is_nullable default — CRITICAL

The `is_nullable` mapping defaults to `'YES'` when not specified in field_specifications:
```php
$column_meta[$col_name]['is_nullable'] = (isset($spec['is_nullable']) && $spec['is_nullable'] === false) ? 'NO' : 'YES';
```

**Why this matters:** 46 of 47 boolean fields in the system do NOT set `is_nullable` in their field_specifications, but ARE nullable in PostgreSQL (which defaults to nullable). Only `sct_is_active` has `'is_nullable'=>false`.

If we defaulted to `'NO'`, a `NULL` boolean would be incorrectly bound as `FALSE` instead of `NULL` for those 46 fields. Defaulting to `'YES'` matches information_schema behavior.

### int8/bigint primary keys bound as PARAM_STR

The current `edit_table()` binding code only checks for `'integer'` and `'smallint'` — NOT `'bigint'`. This means `int8` primary keys (like `usr_user_id`) are bound as `PARAM_STR` on UPDATE. The new code produces `'bigint'` for int8 types, which also falls through to `PARAM_STR`. **Behavior is identical.**

### NULL, empty string, 0, and FALSE in the -NOUPDATE- check

The existing `(string)$column_val != "-NOUPDATE-"` check is preserved exactly. Behavior for edge values:
- `NULL` → `(string)NULL = ""` → included (not NOUPDATE) ✓
- `""` → `""` → included ✓
- `0` → `"0"` → included ✓
- `FALSE` → `(string)FALSE = ""` → included ✓
- `"-NOUPDATE-"` → excluded ✓

### NULL integer binding

For integer columns with NULL values: `bind_value(":col", NULL, PDO::PARAM_INT)` sends NULL to the database, not 0. This is correct PostgreSQL/PDO behavior and unchanged from current code.

### Boolean loose comparison with 0, "", "0"

The boolean binding uses loose comparison (`==`), preserving existing behavior:
- `0 == FALSE` → TRUE → binds FALSE ✓
- `"" == FALSE` → TRUE → binds FALSE ✓
- `"0" == FALSE` → TRUE → binds FALSE ✓
- `1 == TRUE` → TRUE → binds TRUE ✓
- `"1" == TRUE` → TRUE → binds TRUE ✓

### Transaction handling removed

`edit_table()` had transaction support via the `$use_transaction` parameter. `save()` always passed `FALSE`, so this code path was never executed from `save()`. It is not replicated.

### count($p_keys) in debug with NULL

The debug output includes `count($p_keys)` which would warn/error when `$p_keys` is NULL (INSERT path). This is a pre-existing bug in `edit_table()` that only triggers in debug mode. It is preserved as-is.

### Error handling consolidation

The current flow is: `edit_table` catches PDOException → wraps with context → re-throws → `save()` catches → `handle_query_error`. The new code catches PDOException → wraps with context → calls `handle_query_error` directly. Same end result, one fewer exception throw.

### `$p_keys_return` variable eliminated

The old code set `$this->key = $p_keys_return[static::$pkey_column]`. The new code sets `$this->key` directly from `$p_keys[static::$pkey_column]` (UPDATE) or `$dblink->lastInsertId($seq)` (INSERT), avoiding the intermediate variable.

## Files Modified

| File | Change |
|---|---|
| `includes/SystemBase.php` | Replace `edit_table()` call in `save()` with inline SQL using `field_specifications` for type resolution |
| `includes/LibraryFunctions.php` | Delete `edit_table()` and its doc block |
| `data/order_items_class.php` | Delete `_unsafe_edit()` method |

## Backward Compatibility

- `save()` produces identical SQL and PDO bindings — the only difference is where type info comes from
- `edit_table()` is deleted — it had exactly two callers, both removed by this spec

## Testing

1. Run model tests: `php tests/models/run_automated.php`
2. Test INSERT path: create a new record, verify it gets a key assigned
3. Test UPDATE path: modify an existing record, verify changes persist
4. Test boolean nullable edge case: save a record with a NULL boolean field, verify it reads back as NULL (not FALSE)
5. Test JSON field: save a record with array data in a JSON column
6. Check error logs after testing
