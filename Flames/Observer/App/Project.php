<?php

declare(strict_types=1);

namespace Flames\Observer\App;

use Flames\Observer\App as ObserverApp;

/**
 * Shared project-wide observer configuration (paths, cache dir, hash helpers).
 */
final class Project
{
    public static function configure(string $root, string $observerCacheDir, bool $watchVendor = false): void
    {
        ObserverApp::setRoot($root);
        ObserverApp::setCachePath(rtrim($observerCacheDir, '/') . '/');
        ObserverApp::setPaths(self::paths($root, $watchVendor));
    }

    /** @return list<string> */
    public static function paths(string $root, bool $watchVendor = false): array
    {
        $root = rtrim($root, '/\\') . '/';

        $paths = [
            $root . '.env',
            $root . 'App',
            $root . 'Microservice',
            $root . 'public',
        ];

        if ($watchVendor) {
            $paths[] = $root . 'vendor/flamesphp';
        }

        return $paths;
    }

    public static function currentHash(): string
    {
        $hash = ObserverApp::getCurrentHash();
        if ($hash !== null) {
            return $hash;
        }

        return ObserverApp::_computeHash(ObserverApp::_scan());
    }
}
