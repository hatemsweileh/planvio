<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * One row of the requirements screen.
 *
 * `remedy` is the part that matters. Most people installing Planvio are on cPanel with no
 * shell, and "ext-intl is missing" is not an instruction — "Select PHP Version → Extensions,
 * tick intl, save" is. Every failing check carries the sentence that fixes it.
 */
final readonly class Requirement
{
    public const GROUP_PHP = 'php';

    public const GROUP_EXTENSIONS = 'extensions';

    public const GROUP_FILESYSTEM = 'filesystem';

    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public RequirementStatus $status,
        public string $detail,
        public ?string $remedy = null,
        public bool $mandatory = true,
    ) {}

    /**
     * Whether this row is on its own enough to stop the installation.
     *
     * An optional check that fails is a warning, never a wall: Planvio runs without `intl`,
     * it simply formats a few things less well.
     */
    public function blocks(): bool
    {
        return $this->mandatory && $this->status->isFailure();
    }
}
