<?php

declare(strict_types=1);

namespace App\Services\Install;

use JsonException;
use Throwable;

/**
 * The installation's progress, on disk (spec §139).
 *
 * A file rather than a row, for the obvious reason: the thing most likely to fail is the
 * database, and progress that lives in the database it is trying to create cannot survive
 * the failure it exists to record. A file rather than the session, because a session that
 * expired — or a browser closed on a slow migration — must not turn a half-finished
 * installation into one that starts again from zero and migrates the same tables twice.
 *
 * ### What it deliberately does not hold
 *
 * No credentials of any kind. Not the database password, not the administrator's password,
 * not the SMTP password, not the AI key. Those stay in the wizard session for the length of
 * the installation and are written only to the places that own them — `.env` and the
 * encrypted `ai_providers.api_key` column. What is here is a list of completed step keys,
 * two timestamps and, if something went wrong, the same four safe fields the failure screen
 * shows.
 */
final class InstallCheckpoint
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $empty = [
            'started_at' => null,
            'completed_at' => null,
            'completed' => [],
            'failure' => null,
        ];

        if (! is_file($this->path) || ! is_readable($this->path)) {
            return $this->cache = $empty;
        }

        $contents = @file_get_contents($this->path);

        if (! is_string($contents) || $contents === '') {
            return $this->cache = $empty;
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A truncated file from a crash mid-write is progress we cannot trust. Starting
            // over is correct: every step is written to be safe to repeat.
            return $this->cache = $empty;
        }

        return $this->cache = is_array($decoded) ? [...$empty, ...$decoded] : $empty;
    }

    public function isCompleted(InstallStep $step): bool
    {
        $completed = $this->all()['completed'];

        return is_array($completed) && in_array($step->value, $completed, true);
    }

    /**
     * The first step that still has to run, or null when every step is done.
     */
    public function nextStep(): ?InstallStep
    {
        foreach (InstallStep::sequence() as $step) {
            if (! $this->isCompleted($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    public function failure(): ?array
    {
        $failure = $this->all()['failure'];

        return is_array($failure) && $failure !== [] ? array_map(strval(...), $failure) : null;
    }

    public function isFinished(): bool
    {
        return is_string($this->all()['completed_at']);
    }

    public function hasStarted(): bool
    {
        return is_string($this->all()['started_at']);
    }

    /* ------------------------------------------------------------------ *
     * Writing
     * ------------------------------------------------------------------ */

    public function start(): void
    {
        $state = $this->all();

        $this->write([
            ...$state,
            'started_at' => $state['started_at'] ?? now()->toIso8601String(),
            'failure' => null,
        ]);
    }

    public function complete(InstallStep $step): void
    {
        $state = $this->all();
        $completed = is_array($state['completed']) ? $state['completed'] : [];

        if (! in_array($step->value, $completed, true)) {
            $completed[] = $step->value;
        }

        $this->write([...$state, 'completed' => array_values($completed), 'failure' => null]);
    }

    public function fail(InstallationFailed $failure): void
    {
        $this->write([...$this->all(), 'failure' => $failure->toArray()]);
    }

    public function clearFailure(): void
    {
        $this->write([...$this->all(), 'failure' => null]);
    }

    /**
     * Forget `$step` and everything after it.
     *
     * Used when somebody goes back and edits a setting a completed step already acted on:
     * new database credentials make the `.env` that was written from the old ones wrong, so
     * the file is written again rather than trusted. Every step from there on is idempotent,
     * which is what makes re-running them safe rather than merely tolerable.
     */
    public function rewindTo(InstallStep $step): void
    {
        $state = $this->all();
        $completed = is_array($state['completed']) ? $state['completed'] : [];

        $drop = [];
        $reached = false;

        foreach (InstallStep::sequence() as $candidate) {
            $reached = $reached || $candidate === $step;

            if ($reached) {
                $drop[] = $candidate->value;
            }
        }

        $this->write([
            ...$state,
            'completed' => array_values(array_diff($completed, $drop)),
            'completed_at' => null,
            'failure' => null,
        ]);
    }

    public function finish(string $version): void
    {
        $this->write([
            ...$this->all(),
            'completed_at' => now()->toIso8601String(),
            'version' => $version,
            'failure' => null,
        ]);
    }

    public function forget(): void
    {
        $this->cache = null;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $this->cache = $state;

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            return;
        }

        if (@file_put_contents($this->path, $encoded, LOCK_EX) !== false) {
            @chmod($this->path, 0600);
        }
    }
}
