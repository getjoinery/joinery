<?php
/**
 * server_manager/add_discovered_nodes — bulk-create ManagedNode records from a
 * discovery result. One request adds N nodes; already-added slugs are skipped
 * silently. Superadmin only (floor 10).
 *
 * Input: host, ssh_user, ssh_key_path, ssh_port, instances[] (each with name,
 * slug, container_name, web_root, site_url). Returns { ok, created, skipped,
 * errors: [{slug, message}] }.
 *
 * @version 1.0.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function add_discovered_nodes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

	if (empty($input['instances']) || !is_array($input['instances'])) {
		return LogicResult::render(['ok' => false, 'message' => 'Missing or invalid instances array']);
	}

	$host         = trim((string) ($input['host'] ?? ''));
	$ssh_user     = trim((string) ($input['ssh_user'] ?? 'root')) ?: 'root';
	$ssh_key_path = trim((string) ($input['ssh_key_path'] ?? ''));
	$ssh_port     = intval($input['ssh_port'] ?? 22) ?: 22;

	if ($host === '' || $ssh_key_path === '') {
		return LogicResult::render(['ok' => false, 'message' => 'Missing host or ssh_key_path']);
	}

	$created = 0;
	$skipped = 0;
	$errors = [];

	// Cache existing slugs so duplicates skip without a DB round-trip each loop.
	$existing = new MultiManagedNode(['deleted' => false]);
	$existing->load();
	$existing_slugs = [];
	foreach ($existing as $en) {
		$existing_slugs[$en->get('mgn_slug')] = true;
	}

	foreach ($input['instances'] as $inst) {
		$slug = trim((string) ($inst['slug'] ?? ''));
		if ($slug === '') {
			$errors[] = ['slug' => '', 'message' => 'Missing slug'];
			continue;
		}
		if (isset($existing_slugs[$slug])) {
			$skipped++;
			continue;
		}

		try {
			$node = new ManagedNode(NULL);
			$node->set('mgn_name',                (string) ($inst['name'] ?? $slug));
			$node->set('mgn_slug',                $slug);
			$node->set('mgn_host',                $host);
			$node->set('mgn_ssh_user',            $ssh_user);
			$node->set('mgn_ssh_key_path',        $ssh_key_path);
			$node->set('mgn_ssh_port',            $ssh_port);
			$node->set('mgn_container_name',      (string) ($inst['container_name'] ?? ''));
			$node->set('mgn_web_root',            (string) ($inst['web_root'] ?? ''));
			$node->set('mgn_site_url',            (string) ($inst['site_url'] ?? ''));
			$node->set('mgn_enabled',             true);
			$node->set('mgn_skip_joinery_checks', false);
			$node->prepare();
			$node->save();
			$existing_slugs[$slug] = true;
			$created++;
		} catch (Exception $e) {
			$errors[] = ['slug' => $slug, 'message' => $e->getMessage()];
		}
	}

	return LogicResult::render([
		'ok'      => true,
		'created' => $created,
		'skipped' => $skipped,
		'errors'  => $errors,
	]);
}

function add_discovered_nodes_logic_descriptor(): array {
	return [
		'description' => 'Bulk-create managed nodes from a discovery result.',
		'mutates'     => true,
		'requires_session'        => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'host'         => ['type' => 'string', 'required' => false, 'label' => 'Host'],
			'ssh_user'     => ['type' => 'string', 'required' => false, 'label' => 'SSH user'],
			'ssh_key_path' => ['type' => 'string', 'required' => false, 'label' => 'SSH key path'],
			'ssh_port'     => ['type' => 'int',    'required' => false, 'label' => 'SSH port'],
			// 'items' must declare every field the logic reads: item coercion
			// keeps only declared fields (unlike the top-level schema overlay).
			'instances'    => ['type' => 'array',  'required' => false, 'label' => 'Instances to add',
				'items' => [
					'slug'           => ['type' => 'string', 'required' => false, 'label' => 'Instance slug'],
					'name'           => ['type' => 'string', 'required' => false, 'label' => 'Display name'],
					'container_name' => ['type' => 'string', 'required' => false, 'label' => 'Container name'],
					'web_root'       => ['type' => 'string', 'required' => false, 'label' => 'Web root path'],
					'site_url'       => ['type' => 'string', 'required' => false, 'label' => 'Site URL'],
				],
			],
		],
	];
}
?>
