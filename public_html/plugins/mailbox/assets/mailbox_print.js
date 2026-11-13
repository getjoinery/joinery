/*
 * The message print sheet (includes/message_export.php) prints itself once
 * it has loaded. The sheet's policy allows this file and no other script.
 *
 * @version 1.0
 */
window.addEventListener('load', function () { window.print(); });
