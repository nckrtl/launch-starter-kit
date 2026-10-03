<?php

declare(strict_types=1);

/**
 * Project Setup Script
 *
 * Guides you through setting up a new project:
 * - Copies .env.example to .env
 * - Configures APP_NAME, APP_URL, VITE_APP_URL, MAIL_FROM_ADDRESS
 * - Installs Composer and Node dependencies
 * - Links the sibling Launch Laravel package when available
 * - Generates app key, runs migrations
 * - Reports the git hooks installed by `vp config`
 * - Links and secures site with Orbit/Herd
 * - Optionally deletes itself
 */
$setupSteps = [
    'copyEnvFile',
    'updateAppName',
    'updateAppUrl',
    'installComposerDependencies',
    'linkLaunchLaravel',
    'installNodeDependencies',
    'generateAppKey',
    'createDatabase',
    'runMigrations',
    'setupGitHooks',
    'linkSite',
    'buildAssets',
    'askDeleteScript',
];

// Detect local dev CLI (Orbit or Herd). Prefer Orbit when both are available.
function getLocalDevCli(): ?string
{
    static $cli = null;
    if ($cli !== null) {
        return $cli;
    }
    foreach (['orbit', 'herd'] as $cmd) {
        $out = [];
        exec('which '.$cmd.' 2>/dev/null', $out, $code);
        if ($code === 0 && $out !== []) {
            $cli = $cmd;

            return $cli;
        }
    }

    return null;
}

function getRootFolderName(): string
{
    return basename(getcwd());
}

function formatAppName(string $folderName): string
{
    return ucwords(str_replace(['-', '_'], ' ', $folderName));
}

function runSetup(array $steps): void
{
    echo "Starting project setup...\n\n";

    $envContent = '';
    $updated = false;

    foreach ($steps as $step) {
        [$envContent, $updated] = $step($envContent, $updated);
    }

    echo "\nSetup completed successfully! Your project is ready.\n";
    echo "Run `composer dev` to start developing.\n";
}

function copyEnvFile($envContent, $updated)
{
    if (! file_exists('.env.example')) {
        echo "Error: .env.example not found. Run this from the project root.\n";
        exit(1);
    }

    if (! file_exists('.env')) {
        echo "Creating .env from .env.example...\n";
        if (! copy('.env.example', '.env')) {
            echo "Error: Failed to create .env file.\n";
            exit(1);
        }
        echo ".env created.\n\n";
    } else {
        echo ".env already exists, using existing file.\n\n";
    }

    $envContent = file_get_contents('.env');

    return [$envContent, $updated];
}

function updateAppName($envContent, $updated)
{
    $defaultAppName = formatAppName(getRootFolderName());

    echo "Enter project name [{$defaultAppName}]: ";
    $projectName = trim(fgets(STDIN));

    if (empty($projectName)) {
        $projectName = $defaultAppName;
    }

    $quoted = '"'.str_replace('"', '\"', $projectName).'"';

    $envContent = preg_replace('/APP_NAME=.*/', "APP_NAME={$quoted}", $envContent, -1, $count);
    if ($count > 0) {
        echo "APP_NAME set to {$quoted}.\n\n";
        $updated = true;
    }

    return [$envContent, $updated];
}

function updateAppUrl($envContent, $updated)
{
    $folderName = getRootFolderName();
    $defaultUrl = "https://{$folderName}.test";

    echo "Enter application URL [{$defaultUrl}]: ";
    $appUrl = trim(fgets(STDIN));

    if (empty($appUrl)) {
        $appUrl = $defaultUrl;
    }

    // Update APP_URL
    $envContent = preg_replace('/APP_URL=.*/', "APP_URL={$appUrl}", $envContent, -1, $count);
    if ($count > 0) {
        echo "APP_URL set to {$appUrl}.\n";
        $updated = true;
    }

    // Update VITE_APP_URL
    if (preg_match('/VITE_APP_URL=.*/', $envContent)) {
        $envContent = preg_replace('/VITE_APP_URL=.*/', "VITE_APP_URL={$appUrl}", $envContent);
    } else {
        $envContent .= "\nVITE_APP_URL={$appUrl}\n";
    }
    echo "VITE_APP_URL set to {$appUrl}.\n";

    // Update MAIL_FROM_ADDRESS
    $domain = parse_url($appUrl, PHP_URL_HOST);
    if ($domain) {
        $envContent = preg_replace('/MAIL_FROM_ADDRESS=.*/', "MAIL_FROM_ADDRESS=\"app@{$domain}\"", $envContent, -1, $count);
        if ($count > 0) {
            echo "MAIL_FROM_ADDRESS set to app@{$domain}.\n";
        }
    }

    // Flush all env changes to disk
    if ($updated && file_put_contents('.env', $envContent)) {
        echo "Changes saved to .env.\n\n";
    }

    return [$envContent, $updated];
}

function installComposerDependencies($envContent, $updated)
{
    echo "Installing PHP dependencies...\n";
    passthru('composer install', $returnVar);

    if ($returnVar === 0) {
        echo "Composer dependencies installed.\n\n";
    } else {
        echo "Failed to install Composer dependencies.\n\n";
    }

    return [$envContent, $updated];
}

function linkLaunchLaravel($envContent, $updated)
{
    $path = dirname(getcwd()).'/launch-laravel';

    if (! file_exists($path.'/composer.json')) {
        echo "Skipping Launch Laravel link (not found at {$path}).\n\n";

        return [$envContent, $updated];
    }

    echo "Linking Launch Laravel from {$path}...\n";
    passthru('composer link '.escapeshellarg($path), $returnVar);

    if ($returnVar === 0) {
        echo "Launch Laravel linked.\n\n";
    } else {
        echo "Failed to link Launch Laravel.\n\n";
    }

    return [$envContent, $updated];
}

function installNodeDependencies($envContent, $updated)
{
    echo "Installing Node dependencies with vp...\n";
    passthru('vp install', $returnVar);

    if ($returnVar === 0) {
        echo "Node dependencies installed.\n\n";
    } else {
        echo "Failed to install Node dependencies. Is VitePlus (vp) installed?\n\n";
    }

    return [$envContent, $updated];
}

function generateAppKey($envContent, $updated)
{
    echo "Generating application key...\n";
    passthru('php artisan key:generate', $returnVar);

    if ($returnVar === 0) {
        echo "Application key generated.\n\n";
    } else {
        echo "Failed to generate application key.\n\n";
    }

    return [$envContent, $updated];
}

function createDatabase($envContent, $updated)
{
    // SQLite: create the file if it doesn't exist
    if (preg_match('/DB_CONNECTION=sqlite/', $envContent)) {
        $dbPath = 'database/database.sqlite';
        if (! file_exists($dbPath)) {
            echo "Creating SQLite database...\n";
            touch($dbPath);
            echo "Database created at {$dbPath}.\n\n";
        }
    }

    return [$envContent, $updated];
}

function runMigrations($envContent, $updated)
{
    echo "Running database migrations...\n";
    passthru('php artisan migrate --force', $returnVar);

    if ($returnVar === 0) {
        echo "Migrations completed.\n\n";
    } else {
        echo "Failed to run migrations.\n\n";
    }

    return [$envContent, $updated];
}

function setupGitHooks($envContent, $updated)
{
    // Hooks are installed by `vp config`, which `bun install` runs via the
    // package.json `prepare` script. It sets core.hooksPath to VitePlus's
    // dispatcher, which runs the committed .vite-hooks/pre-commit
    // script. Nothing to configure here -- this step only
    // reports what happened.
    //
    // Do not unset core.hooksPath, and do not set hook.* git config keys: those
    // need Git 2.54+, cannot be committed because git config is per-clone, and
    // would disable the dispatcher that already works everywhere.
    exec('git config --local --get core.hooksPath 2>/dev/null', $output, $code);
    $hooksPath = $output[0] ?? '';

    if ($code === 0 && $hooksPath !== '') {
        echo "Git hooks installed (core.hooksPath = {$hooksPath}).\n";
        echo "  pre-commit: staged tasks from vite.config.ts\n\n";

        return [$envContent, $updated];
    }

    echo "Git hooks not installed yet. Run `bun install` (or `vp config`) to install them.\n\n";

    return [$envContent, $updated];
}

function linkSite($envContent, $updated)
{
    $cli = getLocalDevCli();
    if ($cli === null) {
        echo "Skipping site link (no Orbit or Herd found).\n\n";

        return [$envContent, $updated];
    }

    $name = ucfirst($cli);
    $project = getRootFolderName();

    // Herd links a directory; Orbit adopts it as an instance. Orbit has no `link`
    // command at all -- calling it prints the help listing and exits 0, which would
    // read as success here.
    $command = $cli === 'herd'
        ? 'herd link '.escapeshellarg($project)
        : 'orbit instance:register '.escapeshellarg($project)
            .' --path='.escapeshellarg(getcwd())
            .' --root=public';

    echo "Registering site with {$name}...\n";
    echo "  {$command}\n";
    passthru($command, $returnVar);

    if ($returnVar === 0) {
        echo "Site registered with {$name}.\n";
        echo "Set APP_URL and VITE_APP_URL to the domain it reported.\n\n";
    } else {
        echo "Failed to register site with {$name}.\n\n";
    }

    return [$envContent, $updated];
}

function buildAssets($envContent, $updated)
{
    echo "Building frontend assets...\n";
    passthru('vp build', $returnVar);

    if ($returnVar === 0) {
        echo "Assets built.\n\n";
    } else {
        echo "Failed to build assets.\n\n";
    }

    return [$envContent, $updated];
}

function askDeleteScript($envContent, $updated)
{
    echo 'Delete this setup script? (y/n) [y]: ';
    $answer = strtolower(trim(fgets(STDIN)));

    if (empty($answer) || $answer === 'y' || $answer === 'yes') {
        if (unlink(__FILE__)) {
            echo "Setup script deleted.\n";
        } else {
            echo "Failed to delete setup script.\n";
        }
    }

    return [$envContent, $updated];
}

runSetup($setupSteps);
