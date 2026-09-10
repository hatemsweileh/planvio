<?php

declare(strict_types=1);

/**
 * Planvio release builder.
 *
 * Produces dist/planvio-v<version>.zip containing everything a customer needs and
 * nothing they do not: vendor/ with production dependencies only, compiled frontend
 * assets, and no development tooling, tests, secrets or VCS metadata.
 *
 * Run from the repository root:
 *
 *     ./php scripts/build-release.php
 *     ./php scripts/build-release.php --skip-assets    (reuse an existing public/build)
 *
 * The build happens in a staging directory so the working tree keeps its dev
 * dependencies and stays usable immediately afterwards.
 */
const ROOT = __DIR__.'/..';

$options = getopt('', ['skip-assets', 'skip-composer', 'keep-staging']);

$php = detectPhp();
$composer = detectComposer();

$version = readVersion();
$stage = ROOT.'/build/staging';
$dist = ROOT.'/dist';
$zipPath = $dist.'/planvio-v'.$version.'.zip';

title('Planvio release builder');
line("Version        : {$version}");
line("PHP            : {$php}");
line('Composer       : '.($composer ?? 'not found'));
line('Staging        : '.realish($stage));
line('Output         : '.realish($zipPath));

/* ------------------------------------------------------------------ *
 * 1. Preflight
 * ------------------------------------------------------------------ */
step('Preflight');

if (! is_file(ROOT.'/composer.json')) {
    fail('composer.json not found. Run this from the repository root.');
}
if (! class_exists(ZipArchive::class)) {
    fail('The zip extension is required to build a release.');
}

/* ------------------------------------------------------------------ *
 * 2. Frontend assets
 * ------------------------------------------------------------------ */
step('Frontend assets');

if (isset($options['skip-assets'])) {
    line('Skipped (--skip-assets).');
} else {
    run('npm ci --no-audit --no-fund', ROOT, allowFailure: true)
        ?: run('npm install --no-audit --no-fund', ROOT);
    run('npm run build', ROOT);
}

if (! is_file(ROOT.'/public/build/manifest.json')) {
    fail('public/build/manifest.json is missing. The release cannot ship without compiled assets.');
}
line('Compiled assets present.');

/* ------------------------------------------------------------------ *
 * 3. Stage the source tree
 * ------------------------------------------------------------------ */
step('Staging source tree');

rmrf($stage);
mkdirp($stage);

$excludeDirs = [
    '.git', '.github', '.idea', '.vscode', 'node_modules', 'vendor',
    'build', 'dist', 'tests', 'design', '.claude', 'scratchpad',
    // A working tree may hold a prepared copy of the public repository at ./github.
    // Staging it would put the whole source inside the release a second time.
    'github',
    // Wiki pages belong to the separate planvio.wiki.git repository and are project
    // furniture, not product: a customer extracting the ZIP has no wiki.
    'wiki',
];

$excludeFiles = [
    '.env', '.env.local', '.env.testing', '.env.backup',
    'php', 'composer', 'phpunit.xml', 'pint.json',
    'package-lock.json',
    '.gitignore', '.gitattributes', '.editorconfig', '.npmrc',
    '.phpunit.result.cache',
    // Repository furniture. Useful to somebody working ON Planvio, noise to somebody
    // running it: a customer who extracts the ZIP has no pull requests to open.
    'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'CHANGELOG.md',
    // Agent working notes. They are not in the published repository, so a release built
    // from a clone never sees them — but a release built from a development tree would,
    // and instructions written for a coding agent are not part of the product.
    'CLAUDE.md', 'AGENTS.md',
];

$copied = copyTree(ROOT, $stage, $excludeDirs, $excludeFiles);
line("Copied {$copied} files.");

// Storage must ship as an empty, writable skeleton, never with local data.
resetStorage($stage);
line('Storage skeleton reset.');

// The customer never receives a pre-built .env; the installer writes it.
@unlink($stage.'/.env');

// Development-only artefacts: the design gallery view and anything it rendered.
rmrf($stage.'/resources/views/dev');
foreach (glob($stage.'/public/_*') ?: [] as $devArtefact) {
    rmrf($devArtefact);
}
line('Development-only artefacts removed.');

/* ------------------------------------------------------------------ *
 * 4. Production dependencies
 * ------------------------------------------------------------------ */
step('Production dependencies');

if (isset($options['skip-composer'])) {
    line('Skipped (--skip-composer). vendor/ will be missing from the archive.');
} else {
    if ($composer === null) {
        fail('Composer not found and --skip-composer was not passed.');
    }
    run(
        escapeshellarg($php).' '.escapeshellarg($composer)
        .' install --no-dev --no-interaction --no-progress'
        .' --optimize-autoloader --classmap-authoritative --no-scripts',
        $stage,
    );

    if (! is_dir($stage.'/vendor')) {
        fail('composer install produced no vendor directory.');
    }
    line('vendor/ built with production dependencies only.');
}

/* ------------------------------------------------------------------ *
 * 5. Trim vendor
 * ------------------------------------------------------------------ */
step('Trimming vendor');

$trimmed = trimVendor($stage.'/vendor');
line("Removed {$trimmed} development files from vendor/ (tests, docs, CI configs).");

/* ------------------------------------------------------------------ *
 * 6. Safety sweep
 * ------------------------------------------------------------------ */
step('Safety sweep');

$leaks = scanForSecrets($stage);
if ($leaks !== []) {
    foreach ($leaks as $leak) {
        line('  ! '.$leak);
    }
    fail('Refusing to package: the staging tree contains files that must never ship.');
}
line('No .env, key material, database file or VCS metadata found in the staging tree.');

$required = [
    'public/index.php', 'public/.htaccess', '.htaccess', 'artisan',
    'public/build/manifest.json', 'config/planvio.php', 'config/ai.php',
    'bootstrap/app.php',
];
foreach ($required as $path) {
    if (! file_exists($stage.'/'.$path)) {
        fail("Required file missing from the release: {$path}");
    }
}
line('All required files present.');

/* ------------------------------------------------------------------ *
 * 7. Archive
 * ------------------------------------------------------------------ */
step('Creating archive');

mkdirp($dist);
@unlink($zipPath);

$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail("Could not create {$zipPath}");
}

$entries = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1));

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);

        continue;
    }

    $zip->addFile($item->getPathname(), $relative);
    $entries++;

    // No periodic close() here, deliberately. Closing is what actually writes the
    // archive, so flushing every N files rewrites everything already added and turns the
    // whole step quadratic - measurably minutes on a 15k-file tree. libzip reads each
    // source file as it writes it rather than holding 15k descriptors open, so the
    // exhaustion this was guarding against does not occur.
}

$zip->close();

if (! isset($options['keep-staging'])) {
    rmrf($stage);
}

/* ------------------------------------------------------------------ *
 * Done
 * ------------------------------------------------------------------ */
$size = filesize($zipPath);

title('Release ready');
line('File     : '.realish($zipPath));
line('Entries  : '.number_format($entries));
line('Size     : '.formatBytes($size));
line('SHA-256  : '.hash_file('sha256', $zipPath));
echo PHP_EOL;
line('Deploy: upload, extract, point the document root at public/, open the domain.');
echo PHP_EOL;

exit(0);

/* ==================================================================== *
 * Helpers
 * ==================================================================== */

function detectPhp(): string
{
    $candidates = [PHP_BINARY, 'C:/tools/php84/php.exe', '/usr/bin/php', 'php'];

    foreach ($candidates as $candidate) {
        if ($candidate !== 'php' && is_file($candidate)) {
            return $candidate;
        }
    }

    return PHP_BINARY;
}

function detectComposer(): ?string
{
    foreach (['C:/tools/composer/composer.phar', ROOT.'/composer.phar', '/usr/local/bin/composer'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Read the version WITHOUT requiring the config file.
 *
 * config/planvio.php calls env(), which does not exist until the framework has booted.
 * This script deliberately never boots Laravel — it has to run against a tree whose vendor
 * directory is about to be replaced — so the value is parsed out of the source instead.
 */
function readVersion(): string
{
    $config = ROOT.'/config/planvio.php';

    if (is_file($config)) {
        $source = (string) file_get_contents($config);

        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $source, $matches) === 1) {
            return $matches[1];
        }
    }

    return '1.0.0';
}

function copyTree(string $from, string $to, array $excludeDirs, array $excludeFiles): int
{
    $count = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($from, $excludeDirs): bool {
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($from) + 1));
                $top = explode('/', $relative)[0];

                return ! in_array($top, $excludeDirs, true);
            },
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));

        if ($item->isDir()) {
            mkdirp($to.'/'.$relative);

            continue;
        }

        if (in_array(basename($relative), $excludeFiles, true)) {
            continue;
        }

        /*
         * Never copy a local database, key or archive, whatever it is called or wherever it
         * sits. The safety sweep already refuses to package these — it caught
         * database/database.sqlite on the first real run — but a guard that only fires at
         * the end means every build fails until someone edits a list by hand. This is the
         * same rule applied early enough to be useful.
         */
        if (preg_match('/\.(sqlite|sqlite3|db|pem|key|p12|pfx|bak)$/i', $relative) === 1) {
            continue;
        }

        mkdirp(dirname($to.'/'.$relative));
        copy($item->getPathname(), $to.'/'.$relative);
        $count++;
    }

    return $count;
}

function resetStorage(string $stage): void
{
    rmrf($stage.'/storage');

    $tree = [
        'storage/app/private',
        'storage/app/public',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
    ];

    foreach ($tree as $dir) {
        mkdirp($stage.'/'.$dir);
        file_put_contents($stage.'/'.$dir.'/.gitignore', "*\n!.gitignore\n");
    }

    // Re-apply the directory guard the copy step just removed.
    $guard = <<<'GUARD'
# Planvio - this directory must never be served or executed over the web.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

Options -Indexes -ExecCGI

<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule lsapi_module>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8
</IfModule>
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .inc
GUARD;

    file_put_contents($stage.'/storage/.htaccess', $guard);
    file_put_contents($stage.'/storage/app/.htaccess', $guard);

    rmrf($stage.'/bootstrap/cache');
    mkdirp($stage.'/bootstrap/cache');
    file_put_contents($stage.'/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
}

function trimVendor(string $vendor): int
{
    if (! is_dir($vendor)) {
        return 0;
    }

    $dropDirs = ['tests', 'Tests', 'test', 'docs', 'doc', 'examples', 'example',
        '.github', '.git', 'benchmarks', 'phpstan', 'psalm'];
    $dropFiles = ['.gitignore', '.gitattributes', '.travis.yml', 'phpunit.xml',
        'phpunit.xml.dist', '.php-cs-fixer.dist.php', 'psalm.xml', 'phpstan.neon',
        'phpstan.neon.dist', 'Makefile', 'CONTRIBUTING.md', 'CHANGELOG.md',
        '.editorconfig', '.scrutinizer.yml', 'appveyor.yml'];

    $removed = 0;

    // Only two levels deep: vendor/<org>/<package>/<here>. Going deeper risks
    // deleting a directory a package genuinely ships as source.
    foreach (glob($vendor.'/*/*', GLOB_ONLYDIR) ?: [] as $package) {
        foreach ($dropDirs as $dir) {
            if (is_dir($package.'/'.$dir)) {
                $removed += rmrf($package.'/'.$dir);
            }
        }
        foreach ($dropFiles as $file) {
            if (is_file($package.'/'.$file)) {
                @unlink($package.'/'.$file);
                $removed++;
            }
        }
    }

    return $removed;
}

function scanForSecrets(string $stage): array
{
    $problems = [];

    $forbidden = ['.env', '.env.local', '.env.testing', 'database/database.sqlite'];
    foreach ($forbidden as $path) {
        if (file_exists($stage.'/'.$path)) {
            $problems[] = "forbidden file present: {$path}";
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $name = $item->getFilename();
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1));

        if (str_starts_with($relative, 'vendor/')) {
            continue;
        }
        if (preg_match('/\.(sqlite|sqlite3|db|pem|key|p12|pfx)$/i', $name)) {
            $problems[] = "credential or database artefact: {$relative}";
        }
        if ($name === '.git' || str_contains($relative, '/.git/')) {
            $problems[] = "VCS metadata: {$relative}";
        }
    }

    return $problems;
}

/**
 * Run a child process, capturing its output through temporary FILES rather than pipes.
 *
 * The obvious implementation — two `['pipe','w']` descriptors and stream_get_contents() on
 * each in turn — deadlocks, and it deadlocks precisely on the command this script exists to
 * run. stream_get_contents() on stdout blocks until that stream closes; meanwhile Composer,
 * which is voluble on stderr, fills the stderr pipe's OS buffer (64KB on Windows) and blocks
 * writing. Neither side can move. The build sits there with a half-populated vendor
 * directory and no output, forever.
 *
 * Files have no buffer to fill, so the child never blocks and the parent reads once the
 * child has exited. Slower in theory; not measurable against a Composer install.
 */
function run(string $command, string $cwd, bool $allowFailure = false): bool
{
    line("\$ {$command}");

    $stdout = tempnam(sys_get_temp_dir(), 'planvio-out');
    $stderr = tempnam(sys_get_temp_dir(), 'planvio-err');

    $descriptors = [
        1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (! is_resource($process)) {
        @unlink($stdout);
        @unlink($stderr);
        $allowFailure ? line('  (could not start)') : fail("Could not run: {$command}");

        return false;
    }

    $code = proc_close($process);

    $out = (string) @file_get_contents($stdout).(string) @file_get_contents($stderr);
    @unlink($stdout);
    @unlink($stderr);

    foreach (array_slice(array_filter(explode("\n", trim($out))), -6) as $tail) {
        line('  '.rtrim($tail));
    }

    if ($code !== 0) {
        if ($allowFailure) {
            return false;
        }
        fail("Command failed with exit code {$code}: {$command}");
    }

    return true;
}

function mkdirp(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0o755, true);
    }
}

function rmrf(string $path): int
{
    if (! file_exists($path)) {
        return 0;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return 1;
    }

    $count = 0;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $count += rmrf($path.'/'.$entry);
    }
    @rmdir($path);

    return $count;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return sprintf('%.1f %s', $value, $units[$i]);
}

function realish(string $path): string
{
    return str_replace('\\', '/', realpath(dirname($path)) ?: dirname($path)).'/'.basename($path);
}

function title(string $text): void
{
    echo PHP_EOL.str_repeat('=', 68).PHP_EOL.'  '.$text.PHP_EOL.str_repeat('=', 68).PHP_EOL;
}

function step(string $text): void
{
    echo PHP_EOL.'-- '.$text.' '.str_repeat('-', max(0, 62 - strlen($text))).PHP_EOL;
}

function line(string $text): void
{
    echo $text.PHP_EOL;

    // Piped stdout is block-buffered, so without this a backgrounded build shows nothing
    // at all until it finishes - which makes a slow step indistinguishable from a hung one.
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function fail(string $text): never
{
    echo PHP_EOL.'BUILD FAILED: '.$text.PHP_EOL.PHP_EOL;
    exit(1);
}
