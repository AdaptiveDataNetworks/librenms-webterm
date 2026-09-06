<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Models\Ability;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class AbilityCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:ability
        {action=list : list|grant|revoke}
        {--user= : Username or id}
        {--ability=use : use|admin|audit.view}';

    protected $description = 'Manage WebTerm feature abilities';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $ability = (string) $this->option('ability');

        if ($action === 'list') {
            $rows = Ability::query()->orderBy('user_id')->get()
                ->map(fn (Ability $a): array => [$a->user_id, $a->ability])->all();

            $rows === []
                ? $this->warn('Nobody holds any WebTerm ability, so nobody can open a terminal.')
                : $this->table(['user_id', 'ability'], $rows);

            return self::SUCCESS;
        }

        if (! in_array($ability, Ability::ALL, true)) {
            $this->error(sprintf('Unknown ability "%s". Valid: %s', $ability, implode(', ', Ability::ALL)));

            return self::FAILURE;
        }

        $user = $this->findUser((string) $this->option('user'));
        if ($user === null) {
            $this->error(sprintf('No such user: %s', $this->option('user')));

            return self::FAILURE;
        }

        $userId = (int) $user->getAuthIdentifier();

        if ($action === 'revoke') {
            Ability::query()->where('user_id', $userId)->where('ability', $ability)->delete();
            $this->info(sprintf('Revoked "%s" from %s.', $ability, $user->username ?? $userId));

            return self::SUCCESS;
        }

        Ability::query()->updateOrCreate(
            ['user_id' => $userId, 'ability' => $ability],
            ['granted_at' => Carbon::now()]
        );

        $this->info(sprintf('Granted "%s" to %s.', $ability, $user->username ?? $userId));

        return self::SUCCESS;
    }
}
