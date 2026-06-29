<?php
/**
 * Resolved member-read scope report for every AI-readable model.
 *
 * Prints, per model, how a non-admin member's reads are contained:
 *   owner    — scoped to WHERE <col> = me (the columns are shown)
 *   ownerless — catalog/config; members read all rows
 *   hidden   — not exposed to members (the reason is shown)
 *
 * Run after adding or reclassifying a model so an accidentally-exposed or
 * accidentally-hidden table is caught before it ships:
 *
 *   php plugins/joinery_ai/cli/owner_scope_report.php
 *
 * Admins always read cross-user; this report only describes the member fence.
 * CLI-only.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));

$models = ModelRegistry::all();
ksort($models);

$buckets = ['owner' => [], 'all' => [], 'hidden' => []];
foreach ($models as $class => $info) {
    $scope = $info['owner_scope'] ?? ['mode' => 'hidden', 'reason' => 'no owner_scope in registry'];
    $buckets[$scope['mode']][$class] = $scope;
}

$short = function (string $class): string {
    // Class names are unprefixed; the table name is the useful identifier.
    return isset($class::$tablename) ? $class::$tablename : $class;
};

echo "\nJoinery AI — member-read scope (admins always read cross-user)\n";
echo str_repeat('=', 62) . "\n";

printf("\nOWNER-SCOPED (%d) — member reads WHERE owner = me\n", count($buckets['owner']));
echo str_repeat('-', 62) . "\n";
foreach ($buckets['owner'] as $class => $scope) {
    printf("  %-34s %s\n", $short($class), implode(' OR ', $scope['columns']));
}

printf("\nOWNERLESS CATALOG (%d) — member reads all rows (\$ai_owner_field = false)\n", count($buckets['all']));
echo str_repeat('-', 62) . "\n";
foreach ($buckets['all'] as $class => $scope) {
    printf("  %s\n", $short($class));
}

printf("\nHIDDEN FROM MEMBERS (%d) — not exposed; confirm each is intended\n", count($buckets['hidden']));
echo str_repeat('-', 62) . "\n";
foreach ($buckets['hidden'] as $class => $scope) {
    printf("  %-34s %s\n", $short($class), $scope['reason'] ?? '');
}

echo "\n";
foreach (ModelRegistry::warnings() as $w) {
    printf("warning: %s — %s\n", $w['class'], $w['message']);
}
echo "\nTotal readable models: " . count($models) . "\n\n";
