<?php

declare(strict_types=1);

namespace Adn\WebTerm\Librenms;

/**
 * Marks an adapter that reaches into LibreNMS core.
 *
 * LibreNMS is a moving target and none of what we use is a published API with
 * compatibility guarantees. Every core symbol we touch is therefore named
 * explicitly here, and tests/Contract asserts each one still exists with the
 * expected shape when run against a real LibreNMS checkout (the nightly
 * integration job). A rename upstream then shows up as a failing contract test
 * naming the exact symbol, rather than as a mystery 500 in a user's bug report.
 *
 * The rule this interface enforces socially: nothing outside src/Librenms/ may
 * reference a LibreNMS class.
 */
interface CoreDependency
{
    /**
     * Core symbols this adapter requires, as 'Fully\Qualified\Class::method'
     * or 'Fully\Qualified\Class' for a plain class dependency.
     *
     * @return list<string>
     */
    public static function coreSymbols(): array;
}
