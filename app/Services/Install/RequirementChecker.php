<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The pre-flight check, read straight out of `config('planvio.install')`.
 *
 * Nothing here is hard-coded: the PHP floor, the required and optional extension lists and
 * the writable paths all come from configuration, so the screen a customer sees and the list
 * INSTALLATION.md publishes cannot drift apart.
 *
 * Two checks are the installer's own rather than the configuration's. `.env` has to be
 * *creatable*, not merely writable — on a fresh extract the file does not exist yet, so the
 * question is whether its directory will accept it. And PHP's own limits are inspected
 * because `memory_limit=128M` is not a missing extension, it is the reason migrations die
 * halfway through on a shared host, and it is worth saying so before that happens.
 */
final class RequirementChecker
{
    /**
     * Bytes of memory below which a Planvio installation is likely to run out during
     * migrations or a large export. Warned about, never fatal: some hosts report `-1`.
     */
    private const RECOMMENDED_MEMORY_BYTES = 256 * 1024 * 1024;

    private const RECOMMENDED_EXECUTION_SECONDS = 60;

    public function __construct(private readonly InstallPaths $paths) {}

    public function check(): RequirementReport
    {
        return new RequirementReport([
            ...$this->php(),
            ...$this->extensions(),
            ...$this->filesystem(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * PHP itself
     * ------------------------------------------------------------------ */

    /**
     * @return list<Requirement>
     */
    private function php(): array
    {
        $minimum = (string) config('planvio.install.min_php', '8.3.0');
        $current = PHP_VERSION;
        $ok = version_compare($current, $minimum, '>=');

        $requirements = [
            new Requirement(
                key: 'php_version',
                group: Requirement::GROUP_PHP,
                label: __('PHP :version or newer', ['version' => $minimum]),
                status: $ok ? RequirementStatus::Pass : RequirementStatus::Fail,
                detail: __('Running PHP :version', ['version' => $current]),
                remedy: $ok ? null : __('In cPanel open “Select PHP Version”, choose :version or newer, and click Set as current. Ask your host if the version is not offered.', ['version' => $minimum]),
            ),
        ];

        $memory = $this->bytes((string) ini_get('memory_limit'));
        $memoryOk = $memory === null || $memory >= self::RECOMMENDED_MEMORY_BYTES;

        $requirements[] = new Requirement(
            key: 'memory_limit',
            group: Requirement::GROUP_PHP,
            label: __('Memory limit of 256M or more'),
            status: $memoryOk ? RequirementStatus::Pass : RequirementStatus::Warn,
            detail: $memory === null
                ? __('No limit set')
                : __('Currently :value', ['value' => (string) ini_get('memory_limit')]),
            remedy: $memoryOk ? null : __('In cPanel open “Select PHP Version” → Options and set memory_limit to 256M. Planvio will install below that, but large projects and AI runs may run out of memory.'),
            mandatory: false,
        );

        $execution = (int) ini_get('max_execution_time');
        $executionOk = $execution === 0 || $execution >= self::RECOMMENDED_EXECUTION_SECONDS;

        $requirements[] = new Requirement(
            key: 'max_execution_time',
            group: Requirement::GROUP_PHP,
            label: __('Script time limit of 60 seconds or more'),
            status: $executionOk ? RequirementStatus::Pass : RequirementStatus::Warn,
            detail: $execution === 0
                ? __('No limit set')
                : __('Currently :value seconds', ['value' => $execution]),
            remedy: $executionOk ? null : __('In cPanel open “Select PHP Version” → Options and set max_execution_time to 120. The database migration step is the one that needs the time.'),
            mandatory: false,
        );

        return $requirements;
    }

    /* ------------------------------------------------------------------ *
     * Extensions
     * ------------------------------------------------------------------ */

    /**
     * @return list<Requirement>
     */
    private function extensions(): array
    {
        $requirements = [];

        foreach ($this->list('required_extensions') as $extension) {
            $loaded = extension_loaded($extension);

            $requirements[] = new Requirement(
                key: 'ext_'.$extension,
                group: Requirement::GROUP_EXTENSIONS,
                label: $extension,
                status: $loaded ? RequirementStatus::Pass : RequirementStatus::Fail,
                detail: $loaded ? __('Loaded') : __('Not loaded'),
                remedy: $loaded ? null : $this->extensionRemedy($extension),
            );
        }

        foreach ($this->list('optional_extensions') as $extension) {
            $loaded = extension_loaded($extension);

            $requirements[] = new Requirement(
                key: 'ext_'.$extension,
                group: Requirement::GROUP_EXTENSIONS,
                label: $extension,
                status: $loaded ? RequirementStatus::Pass : RequirementStatus::Warn,
                detail: $loaded ? __('Loaded') : __('Not loaded — recommended'),
                remedy: $loaded ? null : $this->extensionRemedy($extension),
                mandatory: false,
            );
        }

        return $requirements;
    }

    private function extensionRemedy(string $extension): string
    {
        return __('In cPanel open “Select PHP Version” → Extensions, tick :extension and save. On a server you administer, install the php-:extension package and restart PHP.', [
            'extension' => $extension,
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Filesystem
     * ------------------------------------------------------------------ */

    /**
     * @return list<Requirement>
     */
    private function filesystem(): array
    {
        $requirements = [];

        foreach ($this->list('writable_paths') as $relative) {
            $absolute = $this->paths->base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $writable = is_dir($absolute) && is_writable($absolute);

            $requirements[] = new Requirement(
                key: 'writable_'.str_replace(['/', '\\'], '_', $relative),
                group: Requirement::GROUP_FILESYSTEM,
                label: $relative.'/',
                status: $writable ? RequirementStatus::Pass : RequirementStatus::Fail,
                detail: is_dir($absolute)
                    ? ($writable ? __('Writable') : __('Not writable'))
                    : __('Directory is missing'),
                remedy: $writable ? null : __('In cPanel open File Manager, right-click :path, choose Change Permissions and set it to 755 (or 775 if your host runs PHP as a different user). Apply to subdirectories.', ['path' => $relative]),
            );
        }

        $requirements[] = $this->envRequirement();

        $symlinks = function_exists('symlink');

        $requirements[] = new Requirement(
            key: 'symlink',
            group: Requirement::GROUP_FILESYSTEM,
            label: __('Symbolic links'),
            status: $symlinks ? RequirementStatus::Pass : RequirementStatus::Warn,
            detail: $symlinks ? __('Available') : __('Disabled by the host'),
            remedy: $symlinks ? null : __('Planvio will install without them. Avatars and workspace logos need public/storage to point at storage/app/public; if your host disables symlink(), create that link from the cPanel Terminal or ask support to create it.'),
            mandatory: false,
        );

        return $requirements;
    }

    /**
     * `.env` is the one file the installer must create, so the check has to answer the
     * question in whichever state the tree is in: replaceable if it already exists,
     * creatable if it does not.
     */
    private function envRequirement(): Requirement
    {
        $exists = is_file($this->paths->env);
        $directory = dirname($this->paths->env);
        $ok = $exists ? is_writable($this->paths->env) : (is_dir($directory) && is_writable($directory));

        return new Requirement(
            key: 'env_writable',
            group: Requirement::GROUP_FILESYSTEM,
            label: __('Configuration file (.env)'),
            status: $ok ? RequirementStatus::Pass : RequirementStatus::Fail,
            detail: $exists
                ? ($ok ? __('Present and writable') : __('Present but not writable'))
                : ($ok ? __('Can be created') : __('Cannot be created')),
            remedy: $ok ? null : __('The Planvio folder itself must be writable so the installer can save your settings. In cPanel File Manager set the permissions of the folder containing public/ to 755, then reload this page.'),
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        $values = config('planvio.install.'.$key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''),
        ));
    }

    /**
     * `null` means unlimited, which is how PHP reports `-1`.
     */
    private function bytes(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
