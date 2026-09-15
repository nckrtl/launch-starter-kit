<?php

declare(strict_types=1);

namespace App\Tasks\Preparation;

use App\Tasks\Runtime\TaskProcessEnvironment;
use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use LogicException;
use SimpleXMLElement;
use SplFileInfo;

final class TaskPreparationConfiguration
{
    private const array PROJECTS = ['apps/cli' => 'phpunit.xml.dist', 'apps/docs' => 'phpunit.xml',
        'apps/gateway' => 'phpunit.xml', 'apps/e2e' => 'phpunit.xml', 'packages/php-sdk' => 'phpunit.xml.dist'];

    // Only the inspected, environment-only Gateway bootstrap may override these values.
    private const array GATEWAY_SAFETY_INPUTS = [
        'tests/bootstrap.php' => '386d9f568df0e48589a2bcbdf2ba1d6e098f9faab6a13b62fec459ae208d9107',
        'tests/Support/TestDatabaseEnvironment.php' => '3f22ca3d3cb6bbd9ed4ad9a26ce38f889091eb5004377709c697d1b18eca2a6c',
    ];

    private const array SAFE_FORCED_ENVIRONMENT = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    ];

    private const array GUIDANCE_COMMANDS = [
        'vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
        'ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
    ];

    public function assertGuidanceInputs(string $repository): void
    {
        foreach (self::PROJECTS as $project => $configuration) {
            $this->assertGuidanceCommand($repository.'/'.$project);
            $path = $repository.'/'.$project.'/phpunit.guidance.xml';
            $xml = $this->xml($path);
            if ($project === 'apps/gateway') {
                foreach ([$xml, $this->xml($repository.'/'.$project.'/'.$configuration)] as $policy) {
                    if (! isset($policy->php)) {
                        continue;
                    }
                    foreach ($policy->php->children() as $setting) {
                        if ((string) $setting['name'] === 'ORBIT_TEST_DATABASE' && (string) $setting['value'] !== '') {
                            throw new LogicException('Allocated test databases are unsupported by memory-only preparation.');
                        }
                    }
                }
            }
            $gatewaySafety = $project === 'apps/gateway' && (string) $xml['bootstrap'] === 'tests/bootstrap.php';
            if ($gatewaySafety) {
                $this->assertGatewaySafetyInputs($repository.'/'.$project);
            } elseif ((string) $xml['bootstrap'] !== 'vendor/autoload.php') {
                throw new LogicException('Unsupported native guidance bootstrap.');
            }
            if (! isset($xml->php)) {
                continue;
            }
            foreach ($xml->php->children() as $setting) {
                $kind = $setting->getName();
                $name = (string) $setting['name'];
                $forced = in_array((string) $setting['force'], ['true', '1'], true);
                $safeForce = $gatewaySafety && $kind === 'env'
                    && array_key_exists($name, self::SAFE_FORCED_ENVIRONMENT)
                    && (string) $setting['value'] === self::SAFE_FORCED_ENVIRONMENT[$name];
                if (($kind !== 'env' && ! ($kind === 'ini' && $name === 'zend.exception_ignore_args'))
                    || ($forced && ! $safeForce)
                    || in_array($name, ['APP_BASE_PATH', 'APP_CONFIG_CACHE', 'APP_ENV_FILE', 'COMPOSER_AUTH',
                        'PHP_INI_SCAN_DIR', 'PHP_INI_PATH', 'BASH_ENV', 'ENV', 'NODE_OPTIONS'], true)) {
                    throw new LogicException('Redirecting or forced native guidance configuration is unsupported.');
                }
            }
        }
    }

    private function xml(string $path): SimpleXMLElement
    {
        if (realpath($path) !== $path || ! is_file($path)) {
            throw new LogicException('Native guidance configuration is missing or redirected.');
        }
        $contents = file_get_contents($path);
        if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents) === 1) {
            throw new LogicException('Native guidance XML cannot declare external entities.');
        }
        $xml = simplexml_load_string($contents, options: LIBXML_NONET);
        if ($xml === false) {
            throw new LogicException('Unsupported native guidance bootstrap.');
        }

        return $xml;
    }

    private function assertGatewaySafetyInputs(string $base): void
    {
        foreach (self::GATEWAY_SAFETY_INPUTS as $relative => $hash) {
            $path = $base.'/'.$relative;
            if (realpath($path) !== $path || ! is_file($path) || hash_file('sha256', $path) !== $hash) {
                throw new LogicException('Unsupported or redirected native Gateway safety bootstrap input.');
            }
        }
    }

    private function assertGuidanceCommand(string $base): void
    {
        $path = $base.'/composer.json';
        if (realpath($path) !== $path || ! is_file($path)) {
            throw new LogicException('Native guidance command configuration is missing or redirected.');
        }
        $composer = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($composer) || ! is_array($composer['scripts'] ?? null)
            || ! in_array($composer['scripts']['guidance:check'] ?? null, self::GUIDANCE_COMMANDS, true)) {
            throw new LogicException('Unsupported native guidance command.');
        }
    }

    /** @param array<string, string|false> $preparationEnvironment
     * @return array<string, mixed>
     */
    public function verify(string $worktree, array $preparationEnvironment): array
    {
        $this->assertGuidanceInputs($worktree);
        $before = $this->snapshot($worktree);
        $probes = [];
        foreach (self::PROJECTS as $project => $configuration) {
            foreach (['runtime' => $configuration, 'guidance' => 'phpunit.guidance.xml'] as $policy => $xml) {
                $environment = $policy === 'runtime' ? TaskProcessEnvironment::isolated() : $preparationEnvironment;
                $result = Process::path($worktree.'/'.$project)->timeout(30)->env($environment)
                    ->run([PHP_BINARY, '-r', $this->probe(), $xml, $project]);
                if ($result->failed() || $result->errorOutput() !== '') {
                    throw new LogicException('Configuration-only '.$policy.' probe failed for '.$project.'. '.$result->errorOutput());
                }
                $data = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || ($data['verified'] ?? null) !== true) {
                    throw new LogicException('Invalid configuration-only probe result.');
                }
                $probes[$project][$policy] = $data;
            }
        }
        if ($this->snapshot($worktree) !== $before) {
            throw new LogicException('Project configuration or contained dependencies changed during verification.');
        }

        return ['files' => $before, 'probes' => $probes, 'providers_booted' => false, 'pdo_opened' => false];
    }

    /** @return array<string, array<string, string>> */
    public function snapshot(string $worktree): array
    {
        $snapshot = [];
        foreach (self::PROJECTS as $project => $xml) {
            $base = $worktree.'/'.$project;
            foreach ([$base, $base.'/vendor'] as $directory) {
                if (realpath($directory) !== $directory || ! is_dir($directory)) {
                    throw new LogicException('A preparation project or vendor directory is missing or redirected.');
                }
            }
            foreach (['.env.testing', 'bootstrap/cache/config.php'] as $unsupported) {
                if (file_exists($base.'/'.$unsupported) || is_link($base.'/'.$unsupported)) {
                    throw new LogicException('Testing dotenv or cached configuration is unsupported: '.$project.'/'.$unsupported);
                }
            }
            $files = array_unique([...glob($base.'/.env*') ?: [], ...glob($base.'/phpunit*.xml*') ?: [],
                ...glob($base.'/bootstrap/cache/*') ?: [], $base.'/composer.json', $base.'/composer.lock', $base.'/'.$xml,
                $base.'/phpunit.guidance.xml', $base.'/vendor/autoload.php']);
            if ($project === 'apps/gateway') {
                foreach (array_keys(self::GATEWAY_SAFETY_INPUTS) as $relative) {
                    if (file_exists($base.'/'.$relative) || is_link($base.'/'.$relative)) {
                        $files[] = $base.'/'.$relative;
                    }
                }
            }
            foreach ($files as $file) {
                if (realpath($file) !== $file || ! is_file($file)) {
                    throw new LogicException('Local setup configuration is missing or redirected: '.$file);
                }
                if (str_starts_with(basename($file), '.env') && ! in_array(basename($file), ['.env', '.env.example'], true)) {
                    throw new LogicException('Additional dotenv overrides are unsupported.');
                }
                $snapshot[$project][substr($file, strlen($base) + 1)] = hash_file('sha256', $file) ?: throw new LogicException('Cannot hash project configuration.');
            }
            if (is_file($base.'/.env') && (! is_file($base.'/.env.example')
                || hash_file('sha256', $base.'/.env') !== hash_file('sha256', $base.'/.env.example'))) {
                throw new LogicException('Prepared dotenv must equal its project template.');
            }
            $this->assertGuidanceCommand($base);
            foreach ($this->dependencyLinks($base.'/vendor', $worktree) as $link => $target) {
                $snapshot[$project][$link] = 'link:'.$target;
            }
            ksort($snapshot[$project]);
        }

        return $snapshot;
    }

    /** @return array<string, string> */
    private function dependencyLinks(string $vendor, string $worktree): array
    {
        $pending = [$vendor];
        $visited = [];
        $links = [];
        while (($directory = array_pop($pending)) !== null) {
            $real = realpath($directory);
            if ($real === false || ! str_starts_with($real, $worktree.'/')) {
                throw new LogicException('A vendor dependency link escapes the worktree.');
            }
            if (isset($visited[$real])) {
                continue;
            }
            $visited[$real] = true;
            foreach (new FilesystemIterator($real, FilesystemIterator::SKIP_DOTS) as $file) {
                if (! $file instanceof SplFileInfo) {
                    throw new LogicException('Unsupported vendor entry.');
                }
                $target = $file->getRealPath();
                if ($target === false || ! str_starts_with($target, $worktree.'/')) {
                    throw new LogicException('A vendor dependency link escapes the worktree.');
                }
                if ($file->isLink()) {
                    $links[substr($file->getPathname(), strlen($worktree) + 1)] = substr($target, strlen($worktree) + 1);
                }
                if ($file->isDir()) {
                    $pending[] = $target;
                }
            }
        }
        ksort($links);

        return $links;
    }

    private function probe(): string
    {
        return <<<'PHP'
            function base_path(string $path = ''): string { return getcwd().($path === '' ? '' : '/'.$path); }
            function preparation_check(bool $value, string $message): void { if (! $value) { throw new RuntimeException($message); } }
            $_SERVER['PAO_DISABLE'] = 'true';
            require getcwd().'/vendor/autoload.php';
            $xml = (new PHPUnit\TextUI\XmlConfiguration\Loader)->load(getcwd().'/'.$argv[1]);
            (new PHPUnit\TextUI\Configuration\PhpHandler)->handle($xml->php());
            foreach (['APP_CONFIG_CACHE', 'APP_BASE_PATH', 'APP_ENV_FILE', 'COMPOSER_AUTH'] as $key) {
                preparation_check(getenv($key) === false && ! isset($_ENV[$key]) && ! isset($_SERVER[$key]), 'Redirecting configuration: '.$key);
            }
            if (class_exists(Illuminate\Support\Env::class)) {
                $env = Illuminate\Support\Env::get('APP_ENV');
                preparation_check(! is_string($env) || ! file_exists(getcwd().'/.env.'.$env), 'Environment-specific dotenv is unsupported.');
                Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), getcwd(), '.env')->safeLoad();
            }
            if ($argv[2] === 'apps/gateway') {
                foreach ([getenv('ORBIT_TEST_DATABASE'), $_ENV['ORBIT_TEST_DATABASE'] ?? false, $_SERVER['ORBIT_TEST_DATABASE'] ?? false] as $allocation) {
                    preparation_check($allocation === false || $allocation === '', 'Allocated test databases are unsupported by memory-only preparation.');
                }
            }
            $database = null;
            if (is_file(getcwd().'/config/database.php')) {
                $config = require getcwd().'/config/database.php';
                $sqlite = $config['connections']['sqlite'] ?? [];
                preparation_check(($config['default'] ?? null) === 'sqlite' && ($sqlite['driver'] ?? null) === 'sqlite'
                    && ($sqlite['database'] ?? null) === ':memory:' && ($sqlite['url'] ?? null) === '', 'Database configuration is not disposable.');
                $database = ['driver' => 'sqlite', 'database' => ':memory:', 'url' => ''];
            }
            echo json_encode(['verified' => true, 'project' => $argv[2], 'configuration' => $argv[1],
                'app_env' => getenv('APP_ENV') === false ? null : getenv('APP_ENV'), 'database' => $database,
                'providers_booted' => false, 'pdo_opened' => false], JSON_THROW_ON_ERROR);
            PHP;
    }
}
