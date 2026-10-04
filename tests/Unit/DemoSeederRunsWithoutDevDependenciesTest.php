<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The demo seeder must not reach for anything that is only installed for development.
 *
 * `planvio:demo` is a shipped command: it is in the release, it is in the README, and it
 * has an explicit `--force` path for production. But `fakerphp/faker` is a `require-dev`
 * dependency, Laravel defines the `fake()` helper only when Faker's classes are present,
 * and every release ships `vendor/` built with `composer install --no-dev`. A single
 * `Model::factory()` call in this path is therefore a fatal error on exactly the
 * installations the command exists for — and on a shared host the customer cannot run
 * Composer to fix it.
 *
 * That defect shipped, and no test caught it, because the suite runs with dev
 * dependencies installed. `fake()` is defined here, so the seeder works here, and the
 * failure only ever appears on somebody else's server.
 *
 * A test that ran the seeder would therefore prove nothing. This reads the source
 * instead, which is the only place the answer is visible from inside a dev environment.
 */
final class DemoSeederRunsWithoutDevDependenciesTest extends TestCase
{
    /**
     * Everything the production path must not touch, and why each one is a problem.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        '/::factory\(\)/' => 'a model factory, whose definition() calls fake()',
        '/(?<![\w>$])fake\(\)/' => 'the fake() helper, which Laravel does not define without Faker',
        '/\\\\?Faker\\\\/' => 'a Faker class directly',
        '/\bWithFaker\b/' => 'the WithFaker trait',
    ];

    /**
     * Files that run when `planvio:demo` runs. `database/factories` is deliberately not
     * here: factories are a test mechanism and Faker is the right tool inside them.
     *
     * @var list<string>
     */
    private const PRODUCTION_SEED_PATH = [
        'database/seeders/DemoDataSeeder.php',
        'database/seeders/DefaultDataSeeder.php',
        'database/seeders/DatabaseSeeder.php',
        'app/Console/Commands/PlanvioDemoCommand.php',
    ];

    #[Test]
    public function the_demo_seed_path_uses_nothing_from_require_dev(): void
    {
        $offences = [];

        foreach (self::PRODUCTION_SEED_PATH as $relative) {
            $path = dirname(__DIR__, 2).'/'.$relative;

            if (! is_file($path)) {
                continue;
            }

            $source = self::withoutCommentsOrStrings((string) file_get_contents($path));

            foreach (self::FORBIDDEN as $pattern => $why) {
                if (preg_match($pattern, $source) === 1) {
                    $offences[] = "{$relative} uses {$why}";
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['The demo seeder must run on an installation built with `composer install --no-dev`:'],
            $offences,
            ['', 'Create the models directly instead. Every value these factories generated was'],
            ['already being overridden — the demo data is written by hand.'],
        )));
    }

    /**
     * Faker really is dev-only, so the rule above has something to protect.
     *
     * If somebody moves it into `require` this fails, which is the right moment to have
     * the conversation: it would put a test-data generator into every production install
     * to support one optional command.
     */
    #[Test]
    public function faker_is_a_development_dependency(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
        );

        $this->assertIsArray($composer);
        $this->assertArrayNotHasKey('fakerphp/faker', $composer['require'] ?? []);
        $this->assertArrayHasKey('fakerphp/faker', $composer['require-dev'] ?? []);
    }

    /**
     * Comments and string literals are stripped before matching, so the prose explaining
     * why factories are absent does not itself trip the rule that keeps them absent.
     */
    private static function withoutCommentsOrStrings(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
