<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
$logic_path = LibraryFunctions::get_logic_file_path('cart_clear_logic.php');
require_once ($logic_path);
?>