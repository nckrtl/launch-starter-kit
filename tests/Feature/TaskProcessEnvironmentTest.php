<?php

use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->environmentDirectory = trim(Process::timeout(10)->run([
        'mktemp', '-d', sys_get_temp_dir().'/commander-task-environment-XXXXXX',
    ])->throw()->output());
});

afterEach(fn () => File::deleteDirectory($this->environmentDirectory));

function withTaskProcessEnvironment(array $variables, Closure $callback): mixed
{
    $previous = [];
    $environment = $_ENV;
    $server = $_SERVER;

    foreach ($variables as $key => $value) {
        $previous[$key] = getenv($key);
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        $_ENV = $environment;
        $_SERVER = $server;
    }
}

it('removes application configuration from every environment source without changing the parent', function () {
    withTaskProcessEnvironment([
        'DB_DATABASE' => $this->environmentDirectory.'/commander.sqlite',
        'DB_URL' => 'sqlite:///'.$this->environmentDirectory.'/commander.sqlite',
        'APP_KEY' => 'commander-secret',
        'APP_URL' => 'https://commander.test',
        'CUSTOM_PROVIDER_TOKEN' => 'another-secret',
        'COMPOSER_AUTH' => '{"secret":"commander-only"}',
        'GIT_DIR' => '/must-not-use',
        'PHP_INI_SCAN_DIR' => '/must-not-use',
        'NODE_OPTIONS' => '--require=/must-not-use',
        'BASH_ENV' => '/must-not-use',
        'PATH' => getenv('PATH'),
        'HOME' => $this->environmentDirectory,
        'LANG' => 'C',
    ], function () {
        putenv('PROCESS_ONLY_SECRET=process-secret');
        $_ENV['ENV_ONLY_SECRET'] = 'env-secret';
        $_SERVER['SERVER_ONLY_SECRET'] = 'server-secret';

        try {
            $result = Process::path($this->environmentDirectory)->timeout(10)
                ->env(TaskProcessEnvironment::isolated())->run([PHP_BINARY, '-r', 'echo json_encode(getenv());']);
            $child = json_decode($result->throw()->output(), true, flags: JSON_THROW_ON_ERROR);

            expect($child)->not->toHaveKeys([
                'DB_DATABASE', 'DB_URL', 'APP_KEY', 'APP_URL', 'CUSTOM_PROVIDER_TOKEN', 'COMPOSER_AUTH', 'GIT_DIR',
                'PHP_INI_SCAN_DIR', 'NODE_OPTIONS', 'BASH_ENV',
                'PROCESS_ONLY_SECRET', 'ENV_ONLY_SECRET', 'SERVER_ONLY_SECRET',
            ])->and($child['PATH'])->toBe(getenv('PATH'))
                ->and($child['HOME'])->toBe($this->environmentDirectory)
                ->and($child['LANG'])->toBe('C')
                ->and(getenv('APP_KEY'))->toBe('commander-secret')
                ->and($_ENV['DB_DATABASE'])->toBe($this->environmentDirectory.'/commander.sqlite')
                ->and($_SERVER['CUSTOM_PROVIDER_TOKEN'])->toBe('another-secret');
        } finally {
            putenv('PROCESS_ONLY_SECRET');
        }
    });
});

it('isolates nested Composer checks from the coordinator database and configuration', function (bool $cached, bool $isolated, bool $urlOnly = false) {
    $project = $this->environmentDirectory.'/project';
    File::makeDirectory($project.'/config', recursive: true);
    $coordinatorDatabase = $this->environmentDirectory.'/commander.sqlite';
    $coordinator = new PDO('sqlite:'.$coordinatorDatabase);
    $coordinator->exec('CREATE TABLE sentinel (value TEXT); INSERT INTO sentinel VALUES ("keep coordinator state")');
    $coordinator = null;
    $before = hash_file('sha256', $coordinatorDatabase);
    $cache = $this->environmentDirectory.'/commander-config.php';
    File::put($cache, '<?php return '.var_export([
        'app' => ['env' => 'commander'],
        'database' => ['connections' => ['sqlite' => ['database' => $coordinatorDatabase]]],
    ], true).';');
    File::put($project.'/.env', "APP_ENV=project-check\nAPP_URL=https://project.test\nDB_DATABASE=\"{$project}/project.sqlite\"\nCACHE_STORE=array\nQUEUE_CONNECTION=sync\n");
    File::put($project.'/config/database.php', '<?php return ["connections" => ["sqlite" => ["driver" => "sqlite", "url" => env("DB_URL"), "database" => env("DB_DATABASE")]]];');
    File::put($project.'/composer.json', json_encode(['scripts' => [
        'check' => '@nested-check', 'nested-check' => '@php check.php',
    ]], JSON_THROW_ON_ERROR));
    File::put($project.'/check.php', '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'.<<<'PHP'

$app = new Illuminate\Foundation\Application($_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__);
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);
$configuration = (new Illuminate\Database\ConfigurationUrlParser)->parseConfiguration(
    $app['config']->get('database.connections.sqlite')
);
$database = $configuration['database'];
if (! in_array($database, [__DIR__.'/project.sqlite', dirname(__DIR__).'/commander.sqlite'], true)) {
    fwrite(STDERR, 'Refusing any database outside the disposable fixture.');
    exit(98);
}
$db = new PDO('sqlite:'.$database);
$db->exec('CREATE TABLE project_check (value TEXT); INSERT INTO project_check VALUES ("verified")');
echo json_encode([
    'database' => $database,
    'base_path' => $app->basePath(),
    'app_env' => $app->environment(),
    'app_url' => env('APP_URL'),
    'db_url' => getenv('DB_URL'),
    'cache_store' => env('CACHE_STORE'),
    'queue_connection' => env('QUEUE_CONNECTION'),
    'coordinator_secret' => getenv('CUSTOM_PROVIDER_TOKEN'),
]);
PHP);

    $variables = [
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $urlOnly ? $project.'/project.sqlite' : $coordinatorDatabase,
        'DB_URL' => 'sqlite:///'.$coordinatorDatabase,
        'APP_ENV' => 'commander', 'APP_KEY' => 'commander-secret',
        'APP_URL' => 'https://commander.test',
        'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
        'CUSTOM_PROVIDER_TOKEN' => 'another-secret',
    ];
    if ($cached) {
        $variables['APP_CONFIG_CACHE'] = $cache;
        $variables['APP_BASE_PATH'] = $this->environmentDirectory;
    }

    $result = withTaskProcessEnvironment($variables, fn () => Process::path($project)->timeout(30)
        ->env($isolated ? TaskProcessEnvironment::isolated() : [])
        ->run(['composer', '--no-plugins', '--no-interaction', 'run-script', 'check']));

    expect($result->exitCode())->toBe(0, $result->errorOutput());
    $observed = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    if (! $isolated) {
        expect($observed['database'])->toBe($coordinatorDatabase)
            ->and(hash_file('sha256', $coordinatorDatabase))->not->toBe($before)
            ->and(File::exists($project.'/project.sqlite'))->toBeFalse();

        return;
    }

    expect(hash_file('sha256', $coordinatorDatabase))->toBe($before);
    expect($observed)->toBe([
        'database' => $project.'/project.sqlite', 'base_path' => $project,
        'app_env' => 'project-check', 'app_url' => 'https://project.test', 'db_url' => false,
        'cache_store' => 'array', 'queue_connection' => 'sync',
        'coordinator_secret' => false,
    ]);
    $projectDatabase = new PDO('sqlite:'.$project.'/project.sqlite');
    expect($projectDatabase->query('SELECT value FROM project_check')->fetchColumn())->toBe('verified');
})->with([
    'environment overrides' => [false, true],
    'cached configuration and base path overrides' => [true, true],
    'unsafe inheritance reproduces the defect in a disposable database' => [false, false],
    'database URL override with a safe database path' => [false, true, true],
    'unsafe URL inheritance overrides a safe database path' => [false, false, true],
]);
