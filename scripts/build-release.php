<?php

declare(strict_types=1);

/**
 * Planvio release builder.
 *
 * Produces two archives for the version in config/planvio.php:
 *
 *   dist/planvio-v<version>.zip          Fresh install. Empty storage skeleton, installer,
 *                                        production vendor, compiled assets. Extract into
 *                                        an empty directory.
 *   dist/planvio-v<version>-update.zip   In-place update for an installation that is
 *                                        already running. Same application files, without
 *                                        storage/ or bootstrap/cache/, so extracting it
 *                                        over the live directory leaves .env, uploads and
 *                                        the install lock where they are. The running
 *                                        application records the new version itself.
 *
 * Run it with PHP from anywhere; it finds the repository from its own location:
 *
 *     ./php scripts/build-release.php
 *     ./php scripts/build-release.php --skip-assets    (reuse an existing public/build)
 *
 * The build happens in a staging directory so the working tree keeps its dev
 * dependencies and stays usable immediately afterwards. What goes into that directory is
 * decided by .gitignore, read through git, with this script's own explicit rules applied
 * first, and alone when there is no git to ask (see stagingCandidates() and ignoredByGit()).
 */
const ROOT = __DIR__.'/..';

$options = getopt('', ['skip-assets', 'skip-composer', 'keep-staging']);

$php = detectPhp();
$composerSearch = detectComposer($php);
$composer = $composerSearch['path'];

$version = readVersion();
$dbVersion = readDbVersion();
$stage = ROOT.'/build/staging';
$dist = ROOT.'/dist';
$zipPath = $dist.'/planvio-v'.$version.'.zip';
$updateZipPath = $dist.'/planvio-v'.$version.'-update.zip';

title('Planvio release builder');
line("Version        : {$version}");
line("PHP            : {$php}");
line('Composer       : '.($composer === null ? 'not found' : "{$composer} ({$composerSearch['version']})"));
foreach ($composerSearch['rejected'] as $rejection) {
    line('                 rejected '.$rejection);
}
line('Staging        : '.realish($stage));
line('Fresh install  : '.realish($zipPath));
line('Update         : '.realish($updateZipPath));

/* ------------------------------------------------------------------ *
 * 1. Preflight
 * ------------------------------------------------------------------ */
step('Preflight');

if (! is_file(ROOT.'/composer.json')) {
    fail('composer.json not found one level above scripts/. This script must sit in a Planvio checkout.');
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

/*
 * The explicit rules. They hold whether or not git is there to ask, and they are the only
 * thing that keeps out what git TRACKS but a customer has no use for - tests/, design/,
 * .github/, phpunit.xml, the contributing guide - because .gitignore says nothing about a
 * committed file. Where they overlap with .gitignore (vendor, editor state, logs, local
 * scripts) the overlap is deliberate: a release built from a source download, a CI cache or
 * any other copy without .git still has to come out clean, and a line deleted from
 * .gitignore for some unrelated reason must not quietly start shipping what it used to hide.
 */
$excludeDirs = [
    '.git', '.hg', '.svn', '.github', '.idea', '.vscode', 'node_modules', 'vendor',
    'build', 'dist', 'tests', 'design', '.claude', 'scratchpad',
    // Editor and tool state, as in .gitignore.
    '.zed', '.nova', '.fleet', '.cursor', '.codex', '.phpunit.cache',
    // A working tree may hold a prepared copy of the public repository at ./github.
    // Staging it would put the whole source inside the release a second time.
    'github',
    // Wiki pages belong to the separate planvio.wiki.git repository and are project
    // furniture, not product: a customer extracting the ZIP has no wiki.
    'wiki', 'planvio-wiki', 'planvio.wiki',
];

// Environment files are not in this list: stagingCandidates() skips every `.env`, `.env.*`
// and the like except `.env.example`, files and directories alike, because a list of names
// only covers the names somebody thought of. See isEnvironmentFile().
$excludeFiles = [
    'php', 'composer', 'php.bat', 'composer.bat', 'composer.phar', 'phpunit.xml', 'pint.json',
    'package-lock.json',
    '.gitignore', '.gitattributes', '.editorconfig', '.npmrc',
    '.phpunit.result.cache',
    // Composer's credential file. .gitignore keeps it beside .env in "never commit".
    'auth.json',
    // IDE helpers and operating-system litter, as in .gitignore.
    '.phpactor.json', '_ide_helper.php', '_ide_helper_models.php', '.phpstorm.meta.php',
    '.DS_Store', 'Thumbs.db', 'Homestead.json', 'Homestead.yaml',
    // Repository furniture. Useful to somebody working ON Planvio, noise to somebody
    // running it: a customer who extracts the ZIP has no pull requests to open.
    'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'CHANGELOG.md',
    // Agent working notes. They are not in the published repository, so a release built
    // from a clone never sees them — but a release built from a development tree would,
    // and instructions written for a coding agent are not part of the product.
    'CLAUDE.md', 'AGENTS.md',
    // One-off maintenance scripts, ignored by git for the same reason. The release is
    // staged from the filesystem rather than from git, so .gitignore only protects it
    // when git is there to ask: without that, a demo-seeding script left in a working
    // tree would land in the web root of every installation built from this ZIP.
    'seed-demo.php',
];

$candidates = stagingCandidates(ROOT, $excludeDirs, $excludeFiles);
$ignored = ignoredByGit(ROOT, array_column($candidates, 0));

$copied = 0;
$excludedByGit = [];
$excludedAnyway = 0;

foreach ($candidates as [$relative, $isDir, $source]) {
    if ($ignored !== null && isset($ignored[$relative])) {
        // A directory is left out as well as its contents, so an ignored directory that
        // happens to exist locally - storage/pail, or public/storage made as a real folder
        // rather than a link - does not reach the release even as an empty shell. An empty
        // public/storage would stop the installer from creating the real link.
        if ($isDir) {
            continue;
        }

        if (discardedLater($relative)) {
            $excludedAnyway++;
        } else {
            $excludedByGit[] = $relative;
        }

        continue;
    }

    if ($isDir) {
        mkdirp($stage.'/'.$relative);

        continue;
    }

    mkdirp(dirname($stage.'/'.$relative));
    copy($source, $stage.'/'.$relative);
    $copied++;
}

line("Copied {$copied} files.");

if ($ignored !== null) {
    line(describeGitExclusions($excludedByGit, $excludedAnyway));
}

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
        fail('No working Composer found (see "Composer" at the top) and --skip-composer was not passed.');
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
 * 5. Filament assets
 * ------------------------------------------------------------------ */
step('Filament assets');

/*
 * The admin panel loads its own JavaScript, CSS and fonts from public/{js,css,fonts}/
 * filament. They are copies of files in vendor/, written by `filament:assets`, and they are
 * ignored by git. Staging leaves them out when it can ask git, but a build without git
 * stages whatever Filament version was last installed in the builder's working tree - or
 * nothing at all, in a fresh clone. The install above runs with --no-scripts, so nothing
 * refreshes them. A release could then pair one version's PHP and Blade with another
 * version's JavaScript, which breaks the panel as soon as a Blade view calls a function the
 * old script does not have.
 *
 * So any staged copies are thrown away and published again from the vendor/ that ships.
 * filament:assets needs no .env and no database; it only boots the framework to find the
 * registered assets.
 */
$filamentAssets = ['public/js/filament', 'public/css/filament', 'public/fonts/filament'];

foreach ($filamentAssets as $directory) {
    rmrf($stage.'/'.$directory);
}

if (isset($options['skip-composer'])) {
    line('Skipped (--skip-composer). There is no vendor/ to publish them from.');
} else {
    run(escapeshellarg($php).' artisan filament:assets --no-interaction', $stage);

    foreach (['public/js/filament/filament/app.js', 'public/css/filament/filament/app.css'] as $path) {
        if (! is_file($stage.'/'.$path)) {
            fail("filament:assets did not produce {$path}.");
        }
    }

    // Booting the framework wrote package manifests to bootstrap/cache and may have started a
    // log. The release ships both as empty skeletons, so lay them out again.
    resetStorage($stage);

    line('Published from the staged vendor/.');
}

/* ------------------------------------------------------------------ *
 * 6. Trim vendor
 * ------------------------------------------------------------------ */
step('Trimming vendor');

$trimmed = trimVendor($stage.'/vendor');
line("Removed {$trimmed} development files from vendor/ (tests, docs, CI configs).");

/* ------------------------------------------------------------------ *
 * 7. Safety sweep
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
 * 8. Archives
 * ------------------------------------------------------------------ */
step('Creating archives');

mkdirp($dist);

/*
 * The update archive is the same tree with the two directories a live installation
 * already has, and must keep, left out. storage/ holds the install lock, the uploads
 * and the directory guards. bootstrap/cache/ holds compiled configuration. Neither is
 * something an overwrite should replace; the application clears the compiled files
 * itself once it notices the new version. UPDATE.txt is added only to this archive so
 * the fresh-install ZIP stays the installer package it has always been.
 */
$updateSkip = ['storage', 'bootstrap/cache'];
$updateNotes = <<<NOTES
Planvio {$version} — update archive

This archive is for an installation that is already running an older Planvio.
It is not a fresh install. A new installation needs planvio-v{$version}.zip and
the browser installer. See docs/INSTALLATION.md.

Shipped application version: {$version}
Shipped database version: {$dbVersion}

1. Back up the database, .env, storage/app/private and storage/app/public.
2. Turn on maintenance mode (Admin → Settings → General).
3. Upload this ZIP into the existing Planvio directory — the one that already
   contains artisan and .env. Do not create a new directory, and do not delete
   the existing one first.
4. Extract here and overwrite the existing files when asked.
5. Do not replace .env, storage/app/private, storage/app/public or
   storage/app/planvio-installed.lock. This archive does not contain them.
6. Open the site, or wait for the next cron run. Planvio records application
   version {$version} and clears its compiled caches. The document root and
   the cron jobs stay where they are. Planvio does not run migrations.
7. Confirm Admin → System Information shows application version {$version}.
   When the database version stored before this update was already {$dbVersion},
   it stays {$dbVersion}. Then turn maintenance mode off.

If System Information shows a database version older than {$dbVersion}, this
release added migrations. Restore the backup and follow the side-by-side steps
in docs/UPGRADING.md. Do not keep serving this code against the old schema.

Planvio does not download updates. You uploaded this archive; the application
only adopts the files that are already on disk.

NOTES;

$entries = writeArchive($zipPath, $stage, []);
$updateEntries = writeArchive($updateZipPath, $stage, $updateSkip, 'UPDATE.txt', $updateNotes);

assertArchiveContains($zipPath, [
    'artisan',
    'public/index.php',
    'public/build/manifest.json',
    'storage/.htaccess',
    'storage/app/.htaccess',
    'bootstrap/cache/.gitignore',
], [
    'UPDATE.txt',
    '.env',
    'storage/app/planvio-installed.lock',
]);

assertArchiveContains($updateZipPath, [
    'artisan',
    'public/index.php',
    'public/build/manifest.json',
    'UPDATE.txt',
    'config/planvio.php',
], [
    '.env',
    'storage/.htaccess',
    'storage/app/.htaccess',
    'bootstrap/cache/.gitignore',
    'storage/app/planvio-installed.lock',
]);

assertArchiveOmitsPrefixes($updateZipPath, $updateSkip);

if (! isset($options['keep-staging'])) {
    rmrf($stage);
}

/* ------------------------------------------------------------------ *
 * Done
 * ------------------------------------------------------------------ */
title('Release ready');
reportArchive('Fresh install', $zipPath, $entries);
echo PHP_EOL;
line('Extract into an empty directory, point the document root at public/, open the domain.');
echo PHP_EOL;
reportArchive('Update', $updateZipPath, $updateEntries);
echo PHP_EOL;
line('Extract over an existing installation. Storage, .env and the install lock are not in this archive.');
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

/**
 * The first Composer that actually runs.
 *
 * The places to look, in order: C:/tools/composer/composer.phar, composer.phar in the
 * repository root, /usr/local/bin/composer, and then `composer` and `composer.phar` in each
 * directory on PATH. Existence used to be the whole test, and the repository-root
 * composer.phar is exactly the file for which existence proves nothing: it is the one
 * somebody drops in by hand, so it is also the one that turns out to be a download that
 * stopped half-way, an HTML error page saved under that name, or something unrelated left
 * behind. Whatever it was became the build's Composer, and the build then died at the
 * install step with an error that pointed at Composer rather than at the file - while a
 * working Composer sat one place further down the list.
 *
 * So each candidate that exists is run as `<php> <candidate> --version` and accepted only if
 * it exits with 0 and prints Composer's own "Composer version ..." line. One that does not
 * is reported with the reason, and the search moves on to the next. A candidate that does
 * not exist is passed over without comment; on most machines most of them will not.
 *
 * PATH comes last, so the documented locations keep the precedence they have always had,
 * and it is only consulted when none of them works. What it finds also only counts when it
 * is a PHP script: the `composer` on a PATH is often a wrapper instead - a shell script
 * from a version manager, say - and PHP handed a wrapper simply prints it.
 *
 * @return array{path: ?string, version: ?string, rejected: list<string>}
 */
function detectComposer(string $php): array
{
    $rejected = [];
    $tried = [];

    foreach (composerCandidates() as $source => $candidate) {
        if (! is_file($candidate)) {
            continue;
        }

        // The same file, minus the `scripts/..` that ROOT carries, so the report reads cleanly.
        $candidate = realish($candidate);

        // /usr/local/bin/composer is very often also what PATH finds. Running it twice
        // tells nobody anything.
        $identity = realpath($candidate) ?: $candidate;
        if (isset($tried[$identity])) {
            continue;
        }
        $tried[$identity] = true;

        if ($source === 'path' && ! isPhpScript($candidate)) {
            $rejected[] = "{$candidate} (from PATH): not a PHP script - a shell wrapper, presumably -"
                .' so PHP cannot run it';

            continue;
        }

        $result = capture(escapeshellarg($php).' '.escapeshellarg($candidate).' --version', ROOT, '');

        if ($result === null) {
            $rejected[] = "{$candidate}: could not be started";

            continue;
        }

        // Colour codes are stripped in case something in the environment forces them on.
        $output = (string) preg_replace('/\e\[[0-9;]*m/', '', $result['stdout']."\n".$result['stderr']);

        if ($result['code'] !== 0) {
            $rejected[] = "{$candidate}: `--version` exited with code {$result['code']}".printed($output, ', printing ');

            continue;
        }

        if (preg_match('/^Composer version \S.*$/m', $output, $matches) !== 1) {
            $rejected[] = "{$candidate}: `--version` did not print a Composer version".printed($output, '; it printed ');

            continue;
        }

        return ['path' => $candidate, 'version' => trim($matches[0]), 'rejected' => $rejected];
    }

    return ['path' => null, 'version' => null, 'rejected' => $rejected];
}

/**
 * Where to look for Composer, keyed by where the candidate came from.
 *
 * A generator, so PATH is only searched once the fixed locations have all failed. It is
 * searched here, one directory at a time, rather than by asking the shell: `command -v`
 * names only the first `composer` on PATH, so a wrapper early in PATH would hide a working
 * Composer later in it, and Windows' `where` behaves differently again. Each directory
 * offers `composer.phar` as well as `composer`, because Composer-Setup on Windows puts the
 * phar beside the composer.bat it adds to PATH, and the batch file is no use to PHP.
 *
 * @return Generator<string, string>
 */
function composerCandidates(): Generator
{
    yield 'fixed' => 'C:/tools/composer/composer.phar';
    yield 'fixed' => ROOT.'/composer.phar';
    yield 'fixed' => '/usr/local/bin/composer';

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        // A Windows PATH entry is sometimes written in quotes, which Windows ignores.
        $directory = trim($directory, '"');

        if ($directory === '') {
            continue;
        }

        yield 'path' => rtrim($directory, '/\\').'/composer';
        yield 'path' => rtrim($directory, '/\\').'/composer.phar';
    }
}

/**
 * Whether PHP can run the file: an optional `#!` line that names PHP, then the opening tag.
 * Composer's own phar starts that way, and so does the script Linux distributions package.
 */
function isPhpScript(string $path): bool
{
    $head = (string) @file_get_contents($path, false, null, 0, 512);

    return preg_match('/\A(#![^\n]*php[^\n]*\n)?<\?php/i', $head) === 1;
}

/**
 * The first line a rejected candidate printed, quoted and shortened, for the report.
 */
function printed(string $output, string $prefix): string
{
    $first = firstLine($output);

    return $first === '' ? $prefix.'nothing' : $prefix.'"'.$first.'"';
}

function firstLine(string $text): string
{
    foreach (preg_split('/\R/', $text) ?: [] as $candidate) {
        $candidate = trim($candidate);

        if ($candidate !== '') {
            return strlen($candidate) > 100 ? substr($candidate, 0, 97).'...' : $candidate;
        }
    }

    return '';
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

    fail('Could not read version from config/planvio.php.');
}

/**
 * The schema generation, read the same way as {@see readVersion()}. The update
 * archive's instructions quote it so a release that did add migrations does not
 * inherit a note written for one that did not.
 */
function readDbVersion(): string
{
    $config = ROOT.'/config/planvio.php';

    if (is_file($config)) {
        $source = (string) file_get_contents($config);

        if (preg_match("/'db_version'\s*=>\s*'([^']+)'/", $source, $matches) === 1) {
            return $matches[1];
        }
    }

    fail('Could not read db_version from config/planvio.php.');
}

/**
 * Add the staged tree to one archive and close it once.
 *
 * No periodic close() along the way. Closing is what actually writes the archive, so
 * flushing every N files rewrites everything already added and turns the whole step
 * quadratic — measurably minutes on a 17k-file tree. libzip reads each source file as it
 * writes it rather than holding 17k descriptors open, so the exhaustion a periodic close
 * was guarding against does not occur.
 *
 * @param list<string> $skipPrefixes relative directories left out of this archive
 */
function writeArchive(
    string $zipPath,
    string $stage,
    array $skipPrefixes,
    ?string $extraName = null,
    ?string $extraContents = null,
): int {
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

        if (skippedPrefix($relative, $skipPrefixes)) {
            continue;
        }

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);

            continue;
        }

        $zip->addFile($item->getPathname(), $relative);
        $entries++;
    }

    if ($extraName !== null) {
        $zip->addFromString($extraName, (string) $extraContents);
        $entries++;
    }

    $zip->close();

    return $entries;
}

/**
 * @param list<string> $prefixes
 */
function skippedPrefix(string $relative, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $present
 * @param list<string> $absent
 */
function assertArchiveContains(string $zipPath, array $present, array $absent): void
{
    $zip = new ZipArchive;

    if ($zip->open($zipPath) !== true) {
        fail("Could not reopen {$zipPath}");
    }

    foreach ($present as $name) {
        if ($zip->locateName($name) === false) {
            $zip->close();
            fail("{$zipPath} is missing {$name}");
        }
    }

    foreach ($absent as $name) {
        if ($zip->locateName($name) !== false) {
            $zip->close();
            fail("{$zipPath} must not contain {$name}");
        }
    }

    $zip->close();
}

/**
 * @param list<string> $prefixes
 */
function assertArchiveOmitsPrefixes(string $zipPath, array $prefixes): void
{
    $zip = new ZipArchive;

    if ($zip->open($zipPath) !== true) {
        fail("Could not reopen {$zipPath}");
    }

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (skippedPrefix(rtrim($name, '/'), $prefixes)) {
            $zip->close();
            fail("{$zipPath} contains {$name}, which an in-place update must not overwrite");
        }
    }

    $zip->close();
}

function reportArchive(string $label, string $zipPath, int $entries): void
{
    $size = filesize($zipPath);

    line($label);
    line('File     : '.realish($zipPath));
    line('Entries  : '.number_format($entries));
    line('Size     : '.($size === false ? 'unknown' : formatBytes($size)));
    line('SHA-256  : '.hash_file('sha256', $zipPath));
}

/**
 * Every directory and file under $from that the explicit rules let into the release, as
 * [relative path, is a directory, path on disk] triples with each directory before its
 * contents.
 *
 * Nothing is copied here: ignoredByGit() has to see the whole list first, so that git is
 * asked once rather than once per file. A list rather than an array keyed by path, because
 * PHP turns a key such as "404" into an integer, and strict types would then reject it the
 * first time it reached a string function. The path on disk travels with the relative one
 * because the two are not always the same file: the relative path has `\` turned into `/`
 * for Windows, and on Linux a backslash is an ordinary character in a file name.
 *
 * @return list<array{0: string, 1: bool, 2: string}>
 */
function stagingCandidates(string $from, array $excludeDirs, array $excludeFiles): array
{
    $candidates = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($from, $excludeDirs): bool {
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($from) + 1));
                $top = explode('/', $relative)[0];

                // Environment names are refused here rather than with the other files so a
                // directory called `.env` (a Python virtualenv, say) is never walked either.
                return ! in_array($top, $excludeDirs, true) && ! isEnvironmentFile($current->getFilename());
            },
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));

        /*
         * Links are skipped, not followed and not copied. The one a working tree usually has
         * is public/storage, made by `storage:link` for a local instance: it points at an
         * absolute path on this machine, means nothing on the server, and the installer
         * creates the real one there. Copying it failed with a warning; following it would
         * have packaged somebody's uploaded files.
         */
        if ($item->isLink()) {
            continue;
        }

        if ($item->isDir()) {
            $candidates[] = [$relative, true, $item->getPathname()];

            continue;
        }

        if (in_array(basename($relative), $excludeFiles, true)) {
            continue;
        }

        // A Vite dev server writes public/hot and removes it on exit; an interrupted one leaves
        // it behind. Shipped, it makes every page load its assets from that dev server.
        if ($relative === 'public/hot') {
            continue;
        }

        // The rest of the one-off scripts .gitignore keeps out of the repository: any
        // `*.local.php` in the root, for the same reason as seed-demo.php above.
        if (! str_contains($relative, '/') && str_ends_with($relative, '.local.php')) {
            continue;
        }

        /*
         * Never copy a local database, key or backup, whatever it is called or wherever it
         * sits. The safety sweep refuses to package these too — it caught
         * database/database.sqlite on the first real run — but a guard that only fires at
         * the end means every build fails until someone edits a list by hand. This is the
         * same rule applied early enough to be useful. Local logs are skipped with them.
         */
        if (isSecretArtefact($relative) || str_ends_with(strtolower($relative), '.log')) {
            continue;
        }

        $candidates[] = [$relative, false, $item->getPathname()];
    }

    return $candidates;
}

/**
 * Which of $paths git ignores, as a set keyed by path, or null when git cannot be asked.
 *
 * .gitignore is the list people actually keep up to date. A new tool that leaves a cache or
 * a log behind shows up in `git status` the first day it is used, so it gets a line there;
 * nothing prompts anybody to add the same line to this script. The explicit rules were a
 * hand-kept copy of .gitignore, and a copy drifts: whatever only .gitignore knew about went
 * into the release. Git is asked rather than .gitignore parsed here, because the syntax -
 * negation, anchoring, `**`, directory-only patterns, a .gitignore in any directory,
 * .git/info/exclude and the user's core.excludesFile - is git's to interpret, and a
 * re-implementation would be one more copy to drift.
 *
 * check-ignore never reports a tracked file, even one a pattern matches, and that is the
 * right answer: something committed in spite of .gitignore is in the repository on purpose.
 * Nothing the release needs is untracked apart from public/build - vendor/ is installed in
 * staging and Filament's assets are published there - and public/build is never asked
 * about at all. It is ignored so that compiled assets cannot produce merge conflicts, but
 * production has no Node, so the release has to carry it.
 *
 * Everything goes to one `git check-ignore --stdin -z`: one process for the whole tree
 * rather than one per file, with the paths fed in through a file by capture() - which
 * cannot deadlock the way two pipes can - and NUL-separated in both directions. Each path
 * goes in with `./` in front, because git reads them as pathspecs: a name that begins with
 * a colon would otherwise be taken for pathspec magic, so that `:php` is read as `php`,
 * and `:(x)y` is refused outright and fails the whole query. With the prefix every name is
 * matched as written, whatever characters it holds, and git hands the prefix back on each
 * path it reports.
 *
 * @param list<string> $paths
 * @return array<string, true>|null
 */
function ignoredByGit(string $root, array $paths): ?array
{
    $git = 'git -C '.escapeshellarg($root);
    $where = realpath($root) ?: $root;

    $tracked = capture($git.' ls-files -z -- composer.json', $root, '');

    if ($tracked === null || $tracked['code'] !== 0) {
        $version = capture('git --version', $root, '');
        $reason = firstLine($tracked['stderr'] ?? '');

        line($version === null || $version['code'] !== 0
            ? 'git is not available, so .gitignore was not consulted: staging with the explicit rules only.'
            : "{$where} is not inside a git work tree".($reason === '' ? '' : " (git: {$reason})")
                .', so .gitignore was not consulted: staging with the explicit rules only.');

        return null;
    }

    /*
     * The rules git applies here are Planvio's only when this tree is a checkout of Planvio,
     * and the test for that is that git tracks composer.json. A copy sitting untracked inside
     * some other repository - a source download unpacked into a project of its own, say - is
     * subject to that repository's ignore rules instead, and they may cover all of it, or
     * only composer.lock through a `*.lock` of their own. Either way files the release needs
     * would quietly go missing, so such a tree is staged as if there were no git to ask.
     */
    if (! in_array('composer.json', explode("\0", $tracked['stdout']), true)) {
        line("git does not track composer.json at {$where}, so the ignore rules there need not be Planvio's:"
            .' staging with the explicit rules only.');

        return null;
    }

    $asked = array_values(array_filter(
        $paths,
        fn (string $path): bool => $path !== 'public/build' && ! str_starts_with($path, 'public/build/'),
    ));

    if ($asked === []) {
        return [];
    }

    $input = '';
    foreach ($asked as $path) {
        $input .= './'.$path."\0";
    }

    $answer = capture($git.' check-ignore --stdin -z', $root, $input);

    // 0 means at least one path is ignored and 1 means none is; anything else is git failing.
    if ($answer === null || ! in_array($answer['code'], [0, 1], true)) {
        $reason = firstLine($answer['stderr'] ?? '');

        line('git check-ignore '.($answer === null ? 'could not be started' : "failed with exit code {$answer['code']}")
            .($reason === '' ? '' : " ({$reason})")
            .', so .gitignore was not consulted: staging with the explicit rules only.');

        return null;
    }

    $ignored = [];
    foreach (explode("\0", $answer['stdout']) as $reported) {
        if ($reported !== '') {
            $ignored[str_starts_with($reported, './') ? substr($reported, 2) : $reported] = true;
        }
    }

    return $ignored;
}

/**
 * Whether a later step of the build throws $relative away, whatever staging does with it:
 * storage/ and bootstrap/cache/ are laid out again empty, the design gallery and everything
 * in public/ it rendered (public/_*) are deleted, and Filament's assets are published afresh
 * from the vendor/ that ships. .gitignore excluding one of these changes nothing, and a
 * working tree holds hundreds of them once the test suite or a local instance has run, so
 * describeGitExclusions() gives them as a total and keeps them out of the breakdown.
 */
function discardedLater(string $relative): bool
{
    $places = [
        'storage/', 'bootstrap/cache/', 'resources/views/dev/', 'public/_',
        'public/js/filament/', 'public/css/filament/', 'public/fonts/filament/',
    ];

    foreach ($places as $place) {
        if (str_starts_with($relative, $place)) {
            return true;
        }
    }

    return false;
}

/**
 * One line saying how many files .gitignore kept out beyond the explicit rules, broken down
 * by where they were, so that a surprise - something that should have shipped - stands out.
 * Those a later step would have discarded anyway are only counted, in brackets.
 *
 * @param list<string> $files
 */
function describeGitExclusions(array $files, int $discardedAnyway): string
{
    $anyway = $discardedAnyway === 0
        ? ''
        : " (not counting {$discardedAnyway} that later steps would have discarded anyway)";

    if ($files === []) {
        return ".gitignore excluded no files beyond the explicit rules{$anyway}.";
    }

    $byPlace = [];
    foreach ($files as $file) {
        $place = str_contains($file, '/') ? explode('/', $file)[0].'/' : $file;
        $byPlace[$place] = ($byPlace[$place] ?? 0) + 1;
    }
    ksort($byPlace, SORT_STRING);

    $parts = [];
    foreach ($byPlace as $place => $count) {
        $parts[] = "{$place} {$count}";
    }

    $noun = count($files) === 1 ? 'file' : 'files';

    return '.gitignore excluded '.count($files)." {$noun} beyond the explicit rules: ".implode(', ', $parts).$anyway.'.';
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
        '.editorconfig', '.scrutinizer.yml', 'appveyor.yml',
        // Working notes some packages now ship for coding assistants (Guzzle and Livewire
        // among them). Development furniture like the rest of this list, and nothing a
        // server running the application reads.
        'AGENTS.md', 'CLAUDE.md', 'GEMINI.md', '.cursorrules'];

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

    if (file_exists($stage.'/database/database.sqlite')) {
        $problems[] = 'forbidden file present: database/database.sqlite';
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
        // Every segment, so a file inside a directory named like one is caught as well.
        if (array_filter(explode('/', $relative), isEnvironmentFile(...)) !== []) {
            $problems[] = "environment file: {$relative}";
        }
        if ($name === 'auth.json') {
            $problems[] = "Composer credentials: {$relative}";
        }
        if (isSecretArtefact($name)) {
            $problems[] = "credential or database artefact: {$relative}";
        }
        if (preg_match('#(^|/)\.(git|hg|svn)(/|$)#', $relative) === 1) {
            $problems[] = "VCS metadata: {$relative}";
        }
    }

    return $problems;
}

/**
 * Run a build step: print the command and the tail of its output, and stop the build if it
 * fails unless $allowFailure says otherwise.
 */
function run(string $command, string $cwd, bool $allowFailure = false): bool
{
    line("\$ {$command}");

    $result = capture($command, $cwd);

    if ($result === null) {
        $allowFailure ? line('  (could not start)') : fail("Could not run: {$command}");

        return false;
    }

    $code = $result['code'];
    $out = $result['stdout'].$result['stderr'];

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

/**
 * Run a child process and hand back its exit code and output, printing nothing; null when
 * the process could not be started at all.
 *
 * Output is captured through temporary FILES rather than pipes. The obvious implementation —
 * two `['pipe','w']` descriptors and stream_get_contents() on each in turn — deadlocks, and
 * it deadlocks precisely on the command this script exists to run. stream_get_contents() on
 * stdout blocks until that stream closes; meanwhile Composer, which is voluble on stderr,
 * fills the stderr pipe's OS buffer (64KB on Windows) and blocks writing. Neither side can
 * move. The build sits there with a half-populated vendor directory and no output, forever.
 *
 * Files have no buffer to fill, so the child never blocks and the parent reads once the
 * child has exited. Slower in theory; not measurable against a Composer install.
 *
 * $stdin goes in through a file as well, so there is still no pipe to manage: the child
 * reads it at its own pace, and nothing here has to write to one stream while another
 * fills. With null the child shares this script's input, as the build steps always have.
 * Probes pass a string, even an empty one, so that a candidate which turns out not to be
 * what was expected cannot sit waiting for a keyboard.
 *
 * @return array{code: int, stdout: string, stderr: string}|null
 */
function capture(string $command, string $cwd, ?string $stdin = null): ?array
{
    $files = [
        'stdout' => tempnam(sys_get_temp_dir(), 'planvio-out'),
        'stderr' => tempnam(sys_get_temp_dir(), 'planvio-err'),
    ];

    $descriptors = [
        1 => ['file', $files['stdout'], 'w'],
        2 => ['file', $files['stderr'], 'w'],
    ];

    if ($stdin !== null) {
        $files['stdin'] = tempnam(sys_get_temp_dir(), 'planvio-in');
        file_put_contents($files['stdin'], $stdin);
        $descriptors[0] = ['file', $files['stdin'], 'r'];
    }

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    $result = null;

    if (is_resource($process)) {
        $code = proc_close($process);

        $result = [
            'code' => $code,
            'stdout' => (string) @file_get_contents($files['stdout']),
            'stderr' => (string) @file_get_contents($files['stderr']),
        ];
    }

    foreach ($files as $file) {
        @unlink($file);
    }

    return $result;
}

function mkdirp(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0o755, true);
    }
}

/**
 * `.env` and anything named like a copy of it, except the commented template the installer
 * starts from.
 *
 * A name list only covers the names somebody thought of: `.env.production` or
 * `.env.staging` left in a working tree would otherwise be copied, pass the sweep and ship
 * with whatever credentials it holds. So this matches `.env` followed by nothing or by `.`,
 * `-`, `_` or `~` (`.env.production`, `.env-staging`, `.env_backup`, the editor's `.env~`),
 * plus direnv's `.envrc`, in any letter case, since Windows and macOS file systems ignore it.
 */
function isEnvironmentFile(string $name): bool
{
    if (strcasecmp($name, '.env.example') === 0) {
        return false;
    }

    return preg_match('/^\.env($|[.\-_~])/i', $name) === 1 || strcasecmp($name, '.envrc') === 0;
}

/**
 * A local database (SQLite files and their journal, WAL and shared-memory companions), key
 * material, or a backup copy.
 */
function isSecretArtefact(string $path): bool
{
    return preg_match('/\.(sqlite3?|db)(-journal|-wal|-shm)?$|\.(pem|key|p12|pfx|bak)$/i', $path) === 1;
}

function rmrf(string $path): int
{
    // The link test comes first. file_exists() follows a link, so it reports one whose target
    // is already gone as absent, and the link - and with it every directory above it - would
    // be left behind. A link can lose its target earlier in the same walk: nette/utils'
    // tests, when Composer installs from source, hold one to a sibling file. Windows removes
    // a link to a directory with rmdir(), not unlink().
    if (is_link($path) || is_file($path)) {
        if (! @unlink($path) && is_dir($path)) {
            @rmdir($path);
        }

        return 1;
    }
    if (! is_dir($path)) {
        return 0;
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
