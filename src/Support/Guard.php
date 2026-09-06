<?php

declare(strict_types=1);

namespace Adn\WebTerm\Support;

use Throwable;

/**
 * The single chokepoint for "this code path must never throw".
 *
 * LibreNMS's PluginManager catches any Throwable escaping a hook, writes it to
 * logs/librenms.log, raises a "Plugin disabled" notification to the user, and
 * sets plugin_active = 0. For a plugin whose whole job is talking to flaky SSH
 * targets and a possibly-absent sidecar, letting that happen would mean a
 * single unreachable device silently uninstalls the terminal for everyone.
 *
 * So every hook entry point and every device-page render goes through here.
 * Keeping it in one place means the swallow-and-report behaviour is auditable,
 * and there is exactly one thing to change if that policy ever changes.
 */
final class Guard
{
    /**
     * Run $operation, returning $fallback if it throws for any reason.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @param  T  $fallback
     * @return T
     */
    public static function safely(callable $operation, mixed $fallback, string $context)
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            self::report($e, $context);

            return $fallback;
        }
    }

    /**
     * Report without ever throwing from the reporter itself. If the container
     * is half-built or the log target is unwritable, report() can throw -- and
     * an exception raised while handling an exception is exactly how a plugin
     * takes down the page it was trying to protect.
     */
    private static function report(Throwable $e, string $context): void
    {
        try {
            if (function_exists('report')) {
                report($e);
            }
        } catch (Throwable) {
            // Deliberately empty: there is nowhere left to report to.
        }
    }
}
