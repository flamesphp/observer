<?php
declare(strict_types=1);

namespace Flames\Observer;

/**
 * Pure-PHP fallback implementation of the Flames Observer Service.
 *
 * Identical public API to the C extension (\Flames\Observer\App).
 * Use this when the C extension cannot be installed.
 *
 * How the shared state works (cross-process getCurrentHash):
 *   A JSON file is written to sys_get_temp_dir() whenever the hash changes.
 *   The filename is derived from the application root path using the same
 *   sanitisation rule as the C extension's shared-memory name:
 *     fo_<sanitised_root>.observer.service.dat
 *   Both the observer process and any external PHP process must resolve to
 *   the same root path for cross-process calls to succeed.
 *
 * Root path resolution order:
 *   1. Value set via Service::setRoot()           (PHP-only helper)
 *   2. ini_get('flames_observer_service.root')    (when C ext is also loaded)
 *   3. getcwd() at the time of the first call
 *
 * ── NOTE ──────────────────────────────────────────────────────────────────
 * Always call Service::setRoot(__DIR__) (or ini_set) at the top of your
 * start-observer.php so the root is the project directory rather than the
 * working directory where the PHP process was launched.
 */
final class App
{
    /** @var string[] */
    private static array $paths = [];

    /** In-process cache – set after every hash computation. */
    private static ?string $currentHash = null;

    /** Explicit root override (PHP-only – not needed with the C extension). */
    private static ?string $rootOverride = null;

    // ── Public API (identical to C extension) ──────────────────────────────

    public static function setPaths(array $paths): void
    {
        self::$paths = array_values($paths);
    }

    public static function getPaths(): array
    {
        return self::$paths;
    }

    /**
     * Returns the current SHA-1 hash of the observed file-system state,
     * or null if the observer has not yet completed its first scan.
     *
     * Works from any context:
     *   - Inside the change callback  → reads the in-process static property.
     *   - Any other PHP script        → reads the shared state file from tmp.
     */
    public static function getCurrentHash(): ?string
    {
        // Fast path: same process as the observer
        if (self::$currentHash !== null) {
            return self::$currentHash;
        }

        // Slow path: cross-process via state file
        $file = self::_stateFile();
        if (!file_exists($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = @json_decode($raw, true);
        if (!is_array($data) || empty($data['ready'])) {
            return null;
        }

        return isset($data['hash']) ? (string)$data['hash'] : null;
    }

    // ── PHP-only helper ────────────────────────────────────────────────────

    /**
     * Explicitly set the application root path used to derive the shared
     * state file name.
     *
     * Call this at the top of start-observer.php:
     *   Service::setRoot(__DIR__);
     *
     * Both the observer script and any script that calls getCurrentHash()
     * must use the same root.
     *
     * This method does not exist in the C extension; use
     * ini_set('flames_observer_service.root', ...) for the C extension.
     */
    public static function setRoot(string $root): void
    {
        self::$rootOverride = rtrim($root, '/\\');
    }

    // ── Internal helpers (used by \Flames\Observer\App\Register) ──

    /** @internal */
    public static function _root(): string
    {
        if (self::$rootOverride !== null) {
            return self::$rootOverride;
        }

        $ini = ini_get('flames_observer_service.root');
        if (is_string($ini) && $ini !== '') {
            return rtrim($ini, '/\\');
        }

        return rtrim((string)(getcwd() ?: sys_get_temp_dir()), '/\\');
    }

    /**
     * Path to the shared state file in the system temp directory.
     *
     * The sanitisation rule matches the C extension's SHM naming convention
     * (characters outside [a-zA-Z0-9._-] replaced with '_'):
     *   <tmp>/fo_<sanitised_root>.observer.service.dat
     *
     * Note: the C extension uses OS-level shared memory (POSIX shm_open on
     * Linux/macOS, named file mappings on Windows). The PHP fallback uses a
     * regular file in tmp as a portable equivalent — they are not interchangeable.
     *
     * @internal
     */
    public static function _stateFile(): string
    {
        $root = self::_root();
        $safe = preg_replace('/[^a-zA-Z0-9.\-]/', '_', $root);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fo_' . $safe . '.observer.service.dat';
    }

    /**
     * Scan all registered paths and return a sorted map of
     *   absolute_path => "mtime:size"
     *
     * @return array<string, string>
     * @internal
     */
    public static function _scan(): array
    {
        $files = [];

        foreach (self::$paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            if (is_file($path)) {
                $stat = @stat($path);
                if ($stat !== false) {
                    $files[realpath($path) ?: $path] =
                        $stat['mtime'] . ':' . $stat['size'];
                }
            } elseif (is_dir($path)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $path,
                        \RecursiveDirectoryIterator::SKIP_DOTS
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    /** @var \SplFileInfo $f */
                    if ($f->isFile()) {
                        $files[$f->getRealPath() ?: $f->getPathname()] =
                            $f->getMTime() . ':' . $f->getSize();
                    }
                }
            }
        }

        ksort($files);  // deterministic order for both comparison and hashing
        return $files;
    }

    /**
     * Compute a SHA-1 hash over a sorted file-state map.
     * Input must already be ksort'd (as returned by _scan()).
     *
     * @param array<string, string> $files
     * @internal
     */
    public static function _computeHash(array $files): string
    {
        $data = '';
        foreach ($files as $path => $info) {
            $data .= $path . "\t" . $info . "\n";
        }
        return sha1($data);
    }

    /**
     * Write the current hash to the in-process static and to the shared
     * state file so external processes can read it via getCurrentHash().
     *
     * @internal
     */
    public static function _writeState(string $hash): void
    {
        self::$currentHash = $hash;

        $payload = json_encode(['ready' => true, 'hash' => $hash]);
        @file_put_contents(self::_stateFile(), $payload, LOCK_EX);
    }
}
