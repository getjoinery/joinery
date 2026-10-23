<?php
/**
 * Office editor bootstrap: tell Drive what this plugin opens and creates.
 * Registrations only (docs/plugin_developer_guide.md § Bootstrap).
 *
 * @version 0.1.0
 */

DriveHelper::registerOpener('office_editor', array(
	'label'      => 'Open in editor',
	'extensions' => OfficeEditor::EXTENSIONS,
	'url'        => array('OfficeEditor', 'openerUrl'),
	'explain'    => array('OfficeEditor', 'openerExplain'),
));

DriveHelper::registerCreator('office_document', array(
	'label'     => 'Document',
	'action'    => 'office_editor/office_editor_create',
	'params'    => array('kind' => 'document'),
	'available' => array('OfficeEditor', 'creatorAvailable'),
));

DriveHelper::registerCreator('office_spreadsheet', array(
	'label'     => 'Spreadsheet',
	'action'    => 'office_editor/office_editor_create',
	'params'    => array('kind' => 'spreadsheet'),
	'available' => array('OfficeEditor', 'creatorAvailable'),
));
