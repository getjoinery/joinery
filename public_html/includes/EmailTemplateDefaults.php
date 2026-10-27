<?php
/**
 * EmailTemplateDefaults — the core email templates every site starts with.
 *
 * The defaults live in public_html/email_templates/: email_templates.json lists
 * each template (name, type, subject, file) and one HTML file per template holds
 * its body. The install builder writes them into joinery-install.sql, and
 * update_database adds any listed template a site does not have. A site's
 * existing rows are never changed here: admins edit templates, and their wording
 * is theirs. A soft-deleted row counts as present, so a template removed on a
 * site is not brought back.
 *
 * @version 1.0
 */

class EmailTemplateDefaults {

	/**
	 * Every listed template, in file order, as
	 * ['name' => ..., 'type' => int, 'subject' => ..., 'body' => ...].
	 *
	 * @throws RuntimeException when the manifest or a listed file is missing or malformed
	 */
	public static function all(): array {
		$dir = PathHelper::getIncludePath('email_templates');
		$manifest_path = $dir . '/email_templates.json';
		if (!is_file($manifest_path)) {
			throw new RuntimeException('email_templates.json not found at ' . $manifest_path);
		}
		$manifest = json_decode(file_get_contents($manifest_path), true);
		if (!is_array($manifest) || !isset($manifest['templates']) || !is_array($manifest['templates'])) {
			throw new RuntimeException('email_templates.json is missing the templates array or is not valid JSON');
		}

		$templates = array();
		foreach ($manifest['templates'] as $i => $entry) {
			foreach (array('name', 'type', 'subject', 'file') as $field) {
				if (!isset($entry[$field]) || $entry[$field] === '') {
					throw new RuntimeException("email_templates.json entry $i is missing '$field'");
				}
			}
			if (!in_array((int)$entry['type'], array(EmailTemplate::TEMPLATE_TYPE_OUTER, EmailTemplate::TEMPLATE_TYPE_INNER, EmailTemplate::TEMPLATE_TYPE_FOOTER), true)) {
				throw new RuntimeException("email_templates.json entry {$entry['name']} has an unknown type");
			}
			$file = $dir . '/' . basename($entry['file']);
			if (!is_file($file)) {
				throw new RuntimeException("email template file {$entry['file']} (for {$entry['name']}) not found");
			}
			$templates[] = array(
				'name' => $entry['name'],
				'type' => (int)$entry['type'],
				'subject' => $entry['subject'],
				// The file's own closing newline is not part of the body.
				'body' => rtrim(file_get_contents($file), "\n"),
			);
		}
		return $templates;
	}

	/** One listed template by name, or NULL when it is not listed. */
	public static function get(string $name): ?array {
		foreach (self::all() as $template) {
			if ($template['name'] === $name) {
				return $template;
			}
		}
		return NULL;
	}

	/**
	 * Adds each listed template no row of that name holds, live or deleted.
	 * Returns the names added.
	 */
	public static function seed(): array {
		$dblink = DbConnector::get_instance()->get_db_link();
		$exists = $dblink->prepare('SELECT 1 FROM emt_email_templates WHERE emt_name = ? LIMIT 1');
		$insert = $dblink->prepare(
			'INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
			 VALUES (?, ?, ?, ?, NOW(), NOW())');

		$added = array();
		foreach (self::all() as $template) {
			$exists->execute(array($template['name']));
			if ($exists->fetchColumn() !== false) {
				continue;
			}
			$insert->execute(array($template['name'], $template['type'], $template['subject'], $template['body']));
			$added[] = $template['name'];
		}
		return $added;
	}
}
