<?php

// We're overriding the default exception handler
define('SKIP_DEFAULT_EXCEPTION_HANDLER', 1);

function ajax_exception_handler($e) { 
	require_once('Globalvars.php');
	$show_errors = Globalvars::get_instance()->get_setting('show_errors');
	$msg = "";
	if ($e instanceof SystemAjaxError) { 
		$msg = $e->getMessage();	
	} else if ($e instanceof SystemDisplayableError) {
		error_log('EXCEPTION: (DISPLAYABLE ERROR) ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
		$msg = $e->getMessage();
	} else {
		error_log('EXCEPTION: ' . $e->getMessage() . ' TRACE: ' . $e->getTraceAsString());
		if ($show_errors) { 
			$msg = $e->getMessage() . ' ' . $e->getTraceAsString();
		} else { 
			$msg = "There was an error processing this operation.";
		}
	}

	// standard JSON error message
	echo json_encode(array(
		"error" => $msg,
		"errorCode" => $e->getCode()
	));
}

class SystemAjaxError extends Exception {}

set_exception_handler('ajax_exception_handler');

?>
