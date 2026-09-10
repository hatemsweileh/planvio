<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiMode;
use App\Models\AiSetting;
use App\Models\Locale;
use App\Models\ProjectTemplate;
use App\Support\Settings;
use App\Support\Version;
use Illuminate\Database\Seeder;

/**
 * The rows every Planvio installation needs, demo mode or not.
 *
 * Four things: the platform settings the updater and the maintenance screen read, the
 * languages the installation offers, the global `ai_settings` row a workspace inherits until
 * it saves its own, and the system project templates (ARCHITECTURE.md §5.6, §5.7).
 *
 * Every write is idempotent, because this seeder runs again on upgrade. That has one
 * deliberate asymmetry: the version rows describe the code that just ran and are always
 * rewritten, while operational settings such as maintenance mode are only written when
 * absent — re-seeding an installation must never quietly take it out of maintenance.
 *
 * System templates are matched on `slug` among the rows with a null `workspace_id`, so a
 * shipped template is corrected in place on upgrade while a workspace's own template of the
 * same name is left alone.
 */
final class DefaultDataSeeder extends Seeder
{
    public function __construct(private readonly Settings $settings) {}

    public function run(): void
    {
        $this->seedSettings();
        $this->seedLocales();
        $this->seedGlobalAiSettings();

        $templates = $this->seedSystemTemplates();

        $this->command?->getOutput()->writeln(
            '  <fg=green>✓</> '.__('Default data seeded: :count system project templates.', ['count' => $templates]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Languages
     * ------------------------------------------------------------------ */

    /**
     * The languages every installation starts with, from {@see Locale::SHIPPED} — the same
     * list the installer offers before this table exists.
     *
     * Matched on `code`, and only the shipped facts are corrected on re-seed.
     *
     * `is_enabled` is left alone once a row exists: an administrator who switched a language
     * off did so on purpose, and an upgrade turning it back on would put a half-translated
     * product back in front of their users without anybody choosing that.
     */
    private function seedLocales(): void
    {
        foreach (Locale::SHIPPED as $locale) {
            $row = Locale::query()->firstOrNew(['code' => $locale['code']]);

            $row->fill([
                'name' => $locale['name'],
                'native_name' => $locale['native_name'],
                'direction' => $locale['direction'],
                'position' => $locale['position'],
            ]);

            if (! $row->exists) {
                $row->is_enabled = true;
                $row->is_default = $locale['is_default'];
            }

            $row->save();
        }
    }

    /* ------------------------------------------------------------------ *
     * Platform settings
     * ------------------------------------------------------------------ */

    private function seedSettings(): void
    {
        // The release that owns the current schema. The upgrade screen compares these
        // against App\Support\Version to decide whether migrations are outstanding.
        $this->settings->set('app_version', Version::app());
        $this->settings->set('db_version', Version::db());

        $this->setIfMissing((string) config('planvio.maintenance.setting_key', 'maintenance.enabled'), false);

        /*
         | No message is seeded, deliberately.
         |
         | A row here is a fixed English sentence, and it is the one thing an Arabic
         | installation's visitors read while the product is closed — App\Http\Middleware\
         | MaintenanceMode prefers the stored value over its own fallback, so seeding one
         | replaces a translated default with an untranslatable row nobody chose. Left
         | unset, the middleware answers with __('Planvio is temporarily unavailable while
         | maintenance is carried out. Please try again shortly.') in the reader's language,
         | and Admin → Maintenance mode shows exactly that sentence as the field's
         | placeholder. An administrator who writes their own still wins.
         */

        $this->settings->flush();
    }

    private function setIfMissing(string $key, mixed $value): void
    {
        if ($this->settings->get($key) === null) {
            $this->settings->set($key, $value);
        }
    }

    /* ------------------------------------------------------------------ *
     * AI defaults
     * ------------------------------------------------------------------ */

    /**
     * The global fallback row: `workspace_id` null, AI off.
     *
     * Disabled is the only defensible default for a self-hosted install — there is no
     * provider configured yet, and an installation that starts by calling out to a third
     * party nobody chose would be a surprise, not a feature (ARCHITECTURE.md §5.8).
     */
    private function seedGlobalAiSettings(): void
    {
        AiSetting::query()->firstOrCreate(
            ['workspace_id' => null],
            [
                'is_enabled' => false,
                'ai_provider_id' => null,
                'default_mode' => AiMode::Assistant,
                'autonomous_enabled' => false,
                'max_tool_calls_per_run' => (int) config('ai.limits.max_tool_calls_per_run', 25),
                'max_run_seconds' => (int) config('ai.limits.max_run_seconds', 180),
                'error_threshold' => (int) config('ai.limits.max_errors_per_run', 3),
                'retention_days' => null,
                'notify_on_action' => true,
                'kill_switch_engaged' => false,
            ],
        );
    }

    /* ------------------------------------------------------------------ *
     * System project templates
     * ------------------------------------------------------------------ */

    private function seedSystemTemplates(): int
    {
        $count = 0;

        foreach (SystemProjectTemplates::all() as $blueprint) {
            ProjectTemplate::query()
                ->whereNull('workspace_id')
                ->where('slug', $blueprint['slug'])
                ->firstOrNew()
                ->fill([
                    'workspace_id' => null,
                    'name' => $blueprint['name'],
                    'slug' => $blueprint['slug'],
                    'description' => $blueprint['description'],
                    'icon' => $blueprint['icon'],
                    'color' => $blueprint['color'],
                    'type' => $blueprint['type'],
                    'definition' => $blueprint['definition'],
                    'is_system' => true,
                    'is_active' => true,
                ])
                ->save();

            $count++;
        }

        return $count;
    }
}
