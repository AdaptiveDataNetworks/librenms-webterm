<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Console;

use AdaptiveDataNetworks\WebTerm\Audit\AuditLogger;
use AdaptiveDataNetworks\WebTerm\Audit\Event;
use AdaptiveDataNetworks\WebTerm\Models\Setting;
use AdaptiveDataNetworks\WebTerm\Support\RuntimeSettings;
use AdaptiveDataNetworks\WebTerm\Support\SettingValue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read and write runtime configuration.
 *
 * Config changes are audited: turning off step-up or widening allowed origins
 * is a security decision, and "who relaxed this, and when" is a question that
 * gets asked after an incident, not before.
 */
final class ConfigCommand extends Command
{
    protected $signature = 'webterm:config {action=list : list|get|set|unset} {key?} {value?}';

    protected $description = 'Read or change WebTerm runtime configuration';

    /**
     * Secrets are never settable here: they belong in files with owners and
     * modes, not in a database table that is backed up and replicated.
     */
    private const REFUSED = ['gateway.secret_file', 'credentials.database.key'];

    public function handle(AuditLogger $audit): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list(),
            'get' => $this->get(),
            'set' => $this->set($audit),
            'unset' => $this->unset($audit),
            default => $this->invalid(),
        };
    }

    private function list(): int
    {
        $rows = Setting::query()->orderBy('key')->get()
            ->map(fn (Setting $s): array => [$s->key, $this->display($s->key, (string) $s->value)])
            ->all();

        if ($rows === []) {
            $this->info('No runtime overrides; config/webterm.php values are in effect.');

            return self::SUCCESS;
        }

        $this->table(['key', 'value'], $rows);

        return self::SUCCESS;
    }

    private function get(): int
    {
        $key = (string) $this->argument('key');
        if ($key === '') {
            $this->error('A key is required.');

            return self::FAILURE;
        }

        $this->line($this->display($key, (string) config('webterm.'.$key)));

        return self::SUCCESS;
    }

    private function set(AuditLogger $audit): int
    {
        $key = (string) $this->argument('key');
        $value = (string) $this->argument('value');

        if ($key === '') {
            $this->error('A key is required.');

            return self::FAILURE;
        }

        if (in_array($key, self::REFUSED, true)) {
            $this->error(sprintf(
                '%s cannot be set here. It points at a file, and the file is where the secret belongs '
                .'-- a database row has no owner and no mode.',
                $key
            ));

            return self::FAILURE;
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => Carbon::now()]
        );

        config()->set('webterm.'.$key, SettingValue::coerce($value, config('webterm.'.$key)));
        RuntimeSettings::flush();

        $audit->log(Event::ConfigChanged, detail: ['key' => $key, 'value' => $this->display($key, $value)]);

        $this->info(sprintf('%s = %s', $key, $this->display($key, $value)));

        return self::SUCCESS;
    }

    private function unset(AuditLogger $audit): int
    {
        $key = (string) $this->argument('key');
        Setting::query()->where('key', $key)->delete();
        RuntimeSettings::flush();

        $audit->log(Event::ConfigChanged, detail: ['key' => $key, 'value' => '(removed)']);
        $this->info(sprintf('%s reset to its configured default.', $key));

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be one of: list, get, set, unset.');

        return self::FAILURE;
    }

    /**
     * Defence in depth: even though secrets cannot be stored here, anything
     * whose key looks secret is masked on the way out.
     */
    private function display(string $key, string $value): string
    {
        foreach (['secret', 'token', 'password', 'key_id', 'role_id'] as $needle) {
            if (str_contains(strtolower($key), $needle) && $value !== '') {
                return str_repeat('*', 8);
            }
        }

        return $value;
    }
}
