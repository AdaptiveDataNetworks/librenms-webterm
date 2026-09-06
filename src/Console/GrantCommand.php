<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Console\Concerns\ResolvesDevices;
use AdaptiveDataNetworks\WebTerm\Models\Grant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Grant or deny terminal access.
 *
 * One command for both effects, because they share every argument and the
 * distinction is a single flag -- and because an operator who has just used
 * --deny should not have to learn a second syntax to undo it.
 */
final class GrantCommand extends Command
{
    use ResolvesDevices;

    protected $signature = 'webterm:grant
        {--user= : Username or id}
        {--role= : LibreNMS role name (alternative to --user)}
        {--device= : Hostname or id}
        {--group= : Device group id (alternative to --device)}
        {--deny : Create a deny grant instead of an allow}
        {--until= : Expiry, e.g. "2026-12-31 17:00"}
        {--note= : Free-text note}
        {--remove : Remove the matching grant instead of creating it}';

    protected $description = 'Grant or deny a user or role terminal access to a device or group';

    public function handle(AuditLogger $audit): int
    {
        [$subjectType, $subjectRef] = $this->subject();
        if ($subjectRef === null) {
            $this->error('Provide --user or --role.');

            return self::FAILURE;
        }

        [$objectType, $objectId] = $this->object();
        if ($objectId === null) {
            $this->error('Provide --device or --group.');

            return self::FAILURE;
        }

        $effect = $this->option('deny') ? Grant::DENY : Grant::ALLOW;

        $attributes = [
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'effect' => $effect,
        ];

        if ($this->option('remove')) {
            $removed = Grant::query()->where($attributes)->delete();
            $audit->log(Event::GrantRemoved, detail: $attributes);
            $this->info(sprintf('Removed %d grant(s).', $removed));

            return self::SUCCESS;
        }

        Grant::query()->updateOrCreate($attributes, [
            'ends_at' => $this->option('until') ? Carbon::parse((string) $this->option('until')) : null,
            'note' => $this->option('note'),
            'created_at' => Carbon::now(),
        ]);

        $audit->log(Event::GrantCreated, detail: $attributes);

        $this->info(sprintf(
            '%s %s %s -> %s %s',
            $effect === Grant::DENY ? 'DENY' : 'ALLOW',
            $subjectType,
            $subjectRef,
            $objectType,
            $objectId
        ));

        if ($effect === Grant::ALLOW) {
            // Saying this once here saves a support round trip: an allow grant
            // is necessary but not sufficient.
            $this->line('');
            $this->line('  Remember the user also needs the "use" ability and the device must be an');
            $this->line('  enabled target. Check with: ./lnms webterm:why --user=... --device=...');
        }

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string|null} */
    private function subject(): array
    {
        if ($role = $this->option('role')) {
            return [Grant::SUBJECT_ROLE, (string) $role];
        }

        $user = $this->findUser((string) $this->option('user'));

        return [Grant::SUBJECT_USER, $user === null ? null : (string) $user->getAuthIdentifier()];
    }

    /** @return array{0: string, 1: int|null} */
    private function object(): array
    {
        if ($group = $this->option('group')) {
            return [Grant::OBJECT_GROUP, (int) $group];
        }

        $device = $this->findDevice((string) $this->option('device'));

        return [Grant::OBJECT_DEVICE, $device === null ? null : (int) $device->device_id];
    }
}
