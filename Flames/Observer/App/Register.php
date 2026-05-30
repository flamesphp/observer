<?php
declare(strict_types=1);

namespace Flames\Observer\App;

use Flames\Observer\App as ObserverService;

/**
 * Pure-PHP fallback implementation of \Flames\Observer\App\Register.
 *
 * Identical public API to the C extension.
 * Use this when the C extension cannot be installed.
 *
 * @see \Flames\Observer\App  for setup (setPaths, setRoot)
 */
final class Register
{
    /**
     * Registers the change callback and immediately enters the blocking
     * observer loop. This method never returns normally.
     *
     * Usage:
     *   \Flames\Observer\App::setRoot(__DIR__);
     *   \Flames\Observer\App::setPaths(['.env', 'config.yml', 'App']);
     *
     *   \Flames\Observer\App\Register::change(function(string $hash): void {
     *       // called whenever any watched file is added, removed, or modified
     *       // $hash is a 40-char SHA-1 representing the new file-system state
     *       // call exit() to stop the observer
     *   });
     *
     * Polling interval (ms) is resolved in this order:
     *   1. ini_get('flames_observer_service.interval')  – when C ext is loaded
     *   2. 50 ms default
     *
     * Override before calling this method:
     *   ini_set('flames_observer_service.interval', '100');
     */
    public static function change(callable $callback): void
    {
        $interval = self::resolveInterval();

        // ── Initial scan ─────────────────────────────────────────────────
        $prev = ObserverService::_scan();
        $hash = ObserverService::_computeHash($prev);
        ObserverService::_writeState($hash);

        fwrite(STDERR, sprintf(
            "[Flames Observer] started (PHP) – paths=%d, interval=%dms, "
            . "state=%s\n"
            . "[Flames Observer] initial hash=%s\n",
            count(ObserverService::getPaths()),
            $interval,
            ObserverService::_stateFile(),
            $hash
        ));

        // ── Observer loop ─────────────────────────────────────────────────
        while (true) {
            usleep($interval * 1000);

            // Re-read interval each cycle so ini_set changes take effect
            $interval = self::resolveInterval();

            $curr = ObserverService::_scan();

            if ($curr !== $prev) {
                $hash = ObserverService::_computeHash($curr);
                ObserverService::_writeState($hash);

                fwrite(STDERR, sprintf(
                    "[Flames Observer] change detected – hash=%s\n", $hash));

                $callback($hash);
            }

            $prev = $curr;
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private static function resolveInterval(): int
    {
        $raw = ini_get('flames_observer_service.interval');
        $ms  = ($raw !== false && $raw !== '') ? (int)$raw : 50;

        if ($ms < 10)    $ms = 10;
        if ($ms > 60000) $ms = 60000;

        return $ms;
    }
}
