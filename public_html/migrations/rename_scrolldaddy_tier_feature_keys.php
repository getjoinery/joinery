<?php
/**
 * Rename ScrollDaddy tier-feature keys to the plugin-namespaced form.
 *
 * tier_features.json lives in plugins/dns_filtering/, so getAllAvailableFeatures()
 * prefixes each definition key with the plugin name: scrolldaddy_* becomes
 * dns_filtering_scrolldaddy_*. The admin tier form posts (and setFeatures() stores)
 * the prefixed keys, and every runtime read uses the prefixed keys. Existing rows
 * stored the bare scrolldaddy_* keys, so rename them in place to match.
 *
 * Idempotent: rows already carrying only prefixed keys are left untouched.
 */
function rename_scrolldaddy_tier_feature_keys() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $rows = $dblink->query("
        SELECT sbt_subscription_tier_id, sbt_features
        FROM sbt_subscription_tiers
        WHERE sbt_features IS NOT NULL AND sbt_features::text LIKE '%scrolldaddy_%'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $stmt = $dblink->prepare("
        UPDATE sbt_subscription_tiers
        SET sbt_features = :features
        WHERE sbt_subscription_tier_id = :id
    ");

    foreach ($rows as $row) {
        $features = json_decode($row['sbt_features'], true);
        if (!is_array($features)) {
            continue;
        }
        $changed = false;
        $renamed = [];
        foreach ($features as $key => $value) {
            // Rename bare scrolldaddy_* keys; leave already-prefixed keys alone.
            if (strpos($key, 'scrolldaddy_') === 0) {
                $renamed['dns_filtering_' . $key] = $value;
                $changed = true;
            } else {
                $renamed[$key] = $value;
            }
        }
        if ($changed) {
            $stmt->execute([
                ':features' => json_encode($renamed),
                ':id'       => $row['sbt_subscription_tier_id'],
            ]);
            $updated++;
        }
    }

    echo "Renamed ScrollDaddy feature keys on $updated tier(s).\n";
    return true;
}
?>
