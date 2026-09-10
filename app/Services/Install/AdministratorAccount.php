<?php

declare(strict_types=1);

namespace App\Services\Install;

use SensitiveParameter;

/**
 * The first account: a platform super-admin who also owns the first workspace.
 *
 * The password is held in plain text for exactly as long as the wizard is open — it lives in
 * the file-backed session the installer runs on and is hashed by the `hashed` cast the moment
 * the account is created. It is never written to the checkpoint file, never logged, and never
 * rendered back into a form field.
 */
final readonly class AdministratorAccount
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
