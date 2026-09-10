<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The default entry point for `db:seed`, and therefore for `migrate --seed`.
 *
 * It calls {@see DefaultDataSeeder} and nothing else. Demo data is deliberately not
 * reachable from here: `migrate --seed` runs during installation and during upgrades, and
 * a fake workspace appearing in somebody's production install would be indistinguishable
 * from a security incident. Demo data is installed only through `planvio:demo`, or by
 * naming {@see DemoDataSeeder} explicitly.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(DefaultDataSeeder::class);
    }
}
