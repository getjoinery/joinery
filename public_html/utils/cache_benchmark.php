<?php
// Simple cache performance benchmarking tool
require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));

$session = SessionControl::get_instance();
// Check for admin permission (level 9)
try {
    $session->check_permission(8);
} catch (Exception $e) {
    die("Admin access required");
}

// Test URL (can be changed via parameter)
$test_url = $_GET['url'] ?? '/events';
$iterations = (int)($_GET['iterations'] ?? 5);

// Build full URL
if (!preg_match('/^https?:\/\//', $test_url)) {
    $test_url = 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($test_url, '/');
}

// Function to measure page load time
function measure_load_time($url, $use_cache = true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // Add header to bypass cache if needed
    if (!$use_cache) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cache-Control: no-cache', 'X-Skip-Cache: 1']);
    }

    $start = microtime(true);
    $content = curl_exec($ch);
    $end = microtime(true);

    $info = curl_getinfo($ch);
    curl_close($ch);

    return [
        'time' => ($end - $start) * 1000, // Convert to milliseconds
        'size' => strlen($content),
        'http_code' => $info['http_code']
    ];
}

// Clear cache for the test URL first to ensure clean test
$cache = new StaticPageCache();
$parsed = parse_url($test_url);
$path = $parsed['path'] ?? '/';
$cache->invalidateUrl($path . (isset($parsed['query']) ? '?' . $parsed['query'] : ''));

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cache Performance Benchmark</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .results { margin: 20px 0; padding: 20px; background: #f5f5f5; border-radius: 5px; }
        .metric { margin: 10px 0; }
        .improvement { color: green; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #e9e9e9; }
        .faster { background: #d4edda; }
    </style>
</head>
<body>
    <h2>Cache Performance Benchmark</h2>
    <p>Testing URL: <code><?= htmlspecialchars($test_url) ?></code></p>
    <p>Running <?= $iterations ?> iterations for each test...</p>

    <?php
    // Run tests
    $uncached_times = [];
    $cached_times = [];

    echo "<h3>Test Progress:</h3>";
    echo "<pre>";

    // First load (will be cached)
    echo "Initial load (to populate cache): ";
    $initial = measure_load_time($test_url, false);
    echo sprintf("%.2f ms\n", $initial['time']);

    // Test cached loads
    echo "\nTesting cached loads:\n";
    for ($i = 0; $i < $iterations; $i++) {
        echo "  Iteration " . ($i + 1) . ": ";
        $result = measure_load_time($test_url, true);
        $cached_times[] = $result['time'];
        echo sprintf("%.2f ms\n", $result['time']);
        flush();
    }

    // Clear cache
    echo "\nClearing cache...\n";
    $cache->invalidateUrl($path . (isset($parsed['query']) ? '?' . $parsed['query'] : ''));

    // Test uncached loads
    echo "\nTesting uncached loads:\n";
    for ($i = 0; $i < $iterations; $i++) {
        echo "  Iteration " . ($i + 1) . ": ";
        // Clear cache before each uncached test to ensure it's truly uncached
        $cache->invalidateUrl($path . (isset($parsed['query']) ? '?' . $parsed['query'] : ''));
        $result = measure_load_time($test_url, false);
        $uncached_times[] = $result['time'];
        echo sprintf("%.2f ms\n", $result['time']);
        flush();
    }
    echo "</pre>";

    // Calculate statistics
    $avg_cached = array_sum($cached_times) / count($cached_times);
    $avg_uncached = array_sum($uncached_times) / count($uncached_times);
    $min_cached = min($cached_times);
    $max_cached = max($cached_times);
    $min_uncached = min($uncached_times);
    $max_uncached = max($uncached_times);

    $improvement = (($avg_uncached - $avg_cached) / $avg_uncached) * 100;
    $speedup = $avg_uncached / $avg_cached;
    ?>

    <div class="results">
        <h3>Results Summary:</h3>

        <table>
            <tr>
                <th>Metric</th>
                <th>Cached</th>
                <th>Uncached</th>
                <th>Difference</th>
            </tr>
            <tr class="faster">
                <td><strong>Average Load Time</strong></td>
                <td><?= sprintf("%.2f ms", $avg_cached) ?></td>
                <td><?= sprintf("%.2f ms", $avg_uncached) ?></td>
                <td class="improvement"><?= sprintf("%.1fx faster", $speedup) ?></td>
            </tr>
            <tr>
                <td>Minimum Time</td>
                <td><?= sprintf("%.2f ms", $min_cached) ?></td>
                <td><?= sprintf("%.2f ms", $min_uncached) ?></td>
                <td><?= sprintf("%.2f ms", $min_uncached - $min_cached) ?></td>
            </tr>
            <tr>
                <td>Maximum Time</td>
                <td><?= sprintf("%.2f ms", $max_cached) ?></td>
                <td><?= sprintf("%.2f ms", $max_uncached) ?></td>
                <td><?= sprintf("%.2f ms", $max_uncached - $max_cached) ?></td>
            </tr>
        </table>

        <div class="metric improvement">
            ✅ Performance Improvement: <?= sprintf("%.1f%%", $improvement) ?> faster with caching
        </div>

        <div class="metric">
            📊 The cached version loads in <?= sprintf("%.2f ms", $avg_cached) ?> compared to <?= sprintf("%.2f ms", $avg_uncached) ?> uncached
        </div>
    </div>

    <div style="margin-top: 20px; padding: 10px; background: #e3f2fd; border-radius: 5px;">
        <strong>Try another URL:</strong><br>
        <form method="get" style="margin-top: 10px;">
            <input type="text" name="url" placeholder="/events" style="width: 300px; padding: 5px;">
            <input type="number" name="iterations" value="5" min="1" max="20" style="width: 60px; padding: 5px;">
            <button type="submit" style="padding: 5px 10px;">Test</button>
        </form>
    </div>

    <p style="margin-top: 20px; color: #666;">
        <small>Note: These times include network latency. Actual server processing time improvements may be even more significant.</small>
    </p>
</body>
</html>