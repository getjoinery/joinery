<?php
/**
 * AJAX endpoint: POST /ajax/add_discovered_nodes
 *
 * Bulk-create ManagedNode records from a discovery result. One request adds
 * N nodes; the single-node path in views/admin/node_add.php still exists and
 * is unchanged. Already-added slugs are skipped silently.
 *
 * Expects a JSON body:
 *   {
 *     "host": "23.239.11.53", "ssh_user": "root", "ssh_key_path": "...",
 *     "ssh_port": 22,
 *     "instances": [
 *       { "name": "...", "slug": "...", "container_name": "...",
 *         "web_root": "...", "site_url": "..." },
 *       ...
 *     ]
 *   }
 *
 * Returns: { ok: bool, created: int, skipped: int, errors: [{slug, message}] }
 *
 * Requires superadmin (level 10).
 *
 * @version 1.0
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$session = SessionControl::get_instance();
if ($session->get_permission() < 10) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'message' => 'Permission denied']);
	exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['instances']) || !is_array($payload['instances'])) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'message' => 'Missing or invalid instances array']);
	exit;
}

$host         = trim((string)($payload['host'] ?? ''));
$ssh_user     = trim((string)($payload['ssh_user'] ?? 'root')) ?: 'root';
$ssh_key_path = trim((string)($payload['ssh_key_path'] ?? ''));
$ssh_port     = intval($payload['ssh_port'] ?? 22) ?: 22;

if ($host === '' || $ssh_key_path === '') {
	http_response_code(400);
	echo json_encode(['ok' => false, 'message' => 'Missing host or ssh_key_path']);
	exit;
}

$created = 0;
$skipped = 0;
$errors = [];

// Cache existing slugs so we skip duplicates without a DB round-trip each loop.
$existing = new MultiManagedNode(['deleted' => false]);
$existing->load();
$existing_slugs = [];
foreach ($existing as $en) {
	$existing_slugs[$en->get('mgn_slug')] = true;
}

foreach ($payload['instances'] as $inst) {
	$slug = trim((string)($inst['slug'] ?? ''));
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
		$node->set('mgn_name',            (string)($inst['name'] ?? $slug));
		$node->set('mgn_slug',            $slug);
		$node->set('mgn_host',            $host);
		$node->set('mgn_ssh_user',        $ssh_user);
		$node->set('mgn_ssh_key_path',    $ssh_key_path);
		$node->set('mgn_ssh_port',        $ssh_port);
		$node->set('mgn_container_name',  (string)($inst['container_name'] ?? ''));
		$node->set('mgn_web_root',        (string)($inst['web_root'] ?? ''));
		$node->set('mgn_site_url',        (string)($inst['site_url'] ?? ''));
		$node->set('mgn_enabled',         true);
		$node->set('mgn_skip_joinery_checks', false);
		$node->prepare();
		$node->save();
		$existing_slugs[$slug] = true;
		$created++;
	} catch (Exception $e) {
		$errors[] = ['slug' => $slug, 'message' => $e->getMessage()];
	}
}

echo json_encode([
	'ok'      => true,
	'created' => $created,
	'skipped' => $skipped,
	'errors'  => $errors,
]);
exit;
?>
