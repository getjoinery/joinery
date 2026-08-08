<?php

/**
 * Spawns a detached CLI worker to run one chat turn, so an /api/v1 chat action
 * can return a poll handle immediately while the turn runs in its own process.
 * The same mechanism recipe runs use (RecipeWorkerSpawner) — decoupled from the
 * web request lifecycle, so a long local-model turn is never at the mercy of an
 * fpm idle-worker recycle or a proxy timeout. The web page keeps its own
 * in-process fastcgi_finish_request detach; this is the API surface's path.
 */
class ChatWorkerSpawner {

    /**
     * Kick off run_chat_turn.php for $message_id. Returns true when a worker
     * was spawned, false when background execution isn't available (exec
     * disabled) so the caller can fall back to running the turn synchronously.
     */
    public static function spawn(int $message_id): bool {
        if (!function_exists('exec')) return false;

        $script = PathHelper::getIncludePath('plugins/joinery_ai/cli/run_chat_turn.php');
        // Site logs live at site-root/logs/, not under public_html — shared with
        // the recipe worker log for one AI audit trail.
        $log = PathHelper::getSiteRoot() . '/logs/joinery_ai_worker.log';

        $cmd = escapeshellarg(self::phpBinary()) . ' ' . escapeshellarg($script) . ' ' . (int)$message_id;
        // The trailing & detaches; redirecting stdio to the log keeps an audit
        // trail without blocking. Failures inside the worker still surface via
        // the assistant row's failed status + error.
        $cmd .= ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        exec($cmd);
        return true;
    }

    /**
     * An absolute path to the CLI php binary. Under php-fpm, PHP_BINARY is the
     * fpm binary (not a CLI php) and the request environment usually has no PATH
     * (fpm's clear_env defaults on), so a bare `php` can't be found — the spawn
     * would silently no-op. Resolve a real CLI php: PHP_BINDIR is a compile-time
     * constant shared by both SAPIs, so PHP_BINDIR/php is the reliable first
     * choice, with the common locations after it.
     */
    private static function phpBinary(): string {
        $candidates = [
            PHP_BINDIR . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        foreach ($candidates as $c) {
            if (@is_executable($c)) return $c;
        }
        return 'php';
    }
}
