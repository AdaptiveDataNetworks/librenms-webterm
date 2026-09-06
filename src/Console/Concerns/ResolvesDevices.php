<?php

declare(strict_types=1);

namespace Adn\WebTerm\Console\Concerns;

/**
 * Shared device and user lookup for the commands.
 *
 * Accepts an id or a hostname/username, because an operator reaching for the
 * CLI during an incident has the hostname in front of them, not the id.
 */
trait ResolvesDevices
{
    protected function findDevice(string $reference): ?object
    {
        $model = 'App\Models\Device';
        if ($reference === '' || ! class_exists($model)) {
            return null;
        }

        if (ctype_digit($reference)) {
            $byId = $model::query()->find((int) $reference);
            if ($byId !== null) {
                return $byId;
            }
        }

        return $model::query()->where('hostname', $reference)->first();
    }

    protected function findUser(string $reference): ?object
    {
        $model = 'App\Models\User';
        if ($reference === '' || ! class_exists($model)) {
            return null;
        }

        if (ctype_digit($reference)) {
            $byId = $model::query()->find((int) $reference);
            if ($byId !== null) {
                return $byId;
            }
        }

        return $model::query()->where('username', $reference)->first();
    }
}
