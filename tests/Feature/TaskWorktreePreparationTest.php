<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Repositories\ProcessOrbitRepository;
use App\Models\Delivery;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Preparation\NativeOrbitTaskWorktreePreparation;
use App\Tasks\Preparation\PrepareTaskWorktree;
use App\Tasks\Preparation\TaskPreparationConfiguration;
use App\Tasks\Preparation\TaskPreparationGuard;
use App\Tasks\Preparation\TaskWorktreePreparation;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class PreparationTestAdapter implements TaskWorktreePreparation
{
    public int $ownershipReads = 0;

    public bool $owned = false;

    public ?Closure $duringNative = null;

    public function __construct(private NativeOrbitTaskWorktreePreparation $native) {}

    public function inspect(string $repository, string $root, string $source): array
    {
        return $this->native->inspect($repository, $root, $source);
    }

    public function assertUnowned(string $socket, string $worktree): void
    {
        $this->ownershipReads++;
        if ($this->owned) {
            throw new LogicException('Herdr fixture owns worktree.');
        }
    }

    public function run(array $before, string $directory, array $environment, int $timeout, Closure $output): array
    {
        if ($this->duringNative !== null) {
            ($this->duringNative)($before, $directory, $environment);
        }

        return $this->native->run($before, $directory, $environment, $timeout, $output);
    }
}

function preparationGit(string $directory, array $arguments): string
{
    return trim(Process::path($directory)->timeout(30)->env(['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_DIR' => false, 'GIT_WORK_TREE' => false])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output());
}

function preparationRun(): array
{
    $test = test();

    return app(PrepareTaskWorktree::class)->handle($test->preparationRoot, 'ORB-900001', $test->preparationManifest, true,
        function (string $type, string $buffer) use ($test): void {
            $values = $test->preparationOutput;
            $values[$type] = ($values[$type] ?? '').$buffer;
            $test->preparationOutput = $values;
        });
}

function preparationStart(string $source = 'ORB-900001'): TaskWorkspace
{
    return app(StartTaskWorkspace::class)->handle(test()->preparationRoot, test()->preparationWorktree,
        test()->preparationManifest, $source, true);
}

function preparationMode(string $mode): void
{
    File::put(test()->preparationRepository.'/fixture-mode', $mode);
    preparationGit(test()->preparationRepository, ['add', '.']);
    preparationGit(test()->preparationRepository, ['commit', '-m', 'Fixture mode']);
    preparationGit(test()->preparationRepository, ['push', 'origin', 'main']);
}

function preparationCurrentInputs(): void
{
    $inputs = json_decode(File::get(base_path('tests/Fixtures/Preparation/current-orbit.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($inputs as $project => $input) {
        $base = test()->preparationRepository.'/'.$project;
        foreach ($input['files'] as $path => $contents) {
            File::ensureDirectoryExists(dirname($base.'/'.$path), 0700);
            File::put($base.'/'.$path, $contents);
        }
        $composer = json_decode(File::get($base.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['scripts']['guidance:check'] = $input['guidance_command'];
        File::put($base.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    }
}

beforeEach(function () {
    Queue::fake();
    $this->preparationDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/task-preparation-XXXXXXXX'])->throw()->output());
    $this->preparationRepository = $this->preparationDirectory.'/repository';
    $this->preparationWorktree = $this->preparationDirectory.'/worktrees/orb-900001';
    $this->preparationEvidence = $this->preparationRepository.'/.git/orbit-delivery/v1/orb-900001/task-preparation';
    $this->preparationOutput = [];
    foreach (['repository/bin', 'worktrees', 'remote', 'projects', 'temporary'] as $path) {
        File::makeDirectory($this->preparationDirectory.'/'.$path, 0700, true);
    }
    config(['commander.projects_path' => $this->preparationDirectory.'/projects',
        'task-runtime.enabled' => true, 'task-runtime.preparation.timeout' => 20,
        'task-runtime.preparation.temporary_root' => $this->preparationDirectory.'/temporary',
        'task-runtime.projects.orbit' => ['repository' => $this->preparationRepository,
            'worktree_root' => $this->preparationDirectory.'/worktrees', 'socket' => $this->preparationDirectory.'/unused.sock',
            'agent_kind' => 'codex', 'agent_arguments' => [], 'flow_version' => 1, 'final_command' => ['true'],
            'final_timeout' => 10, 'instructions' => 'Disposable fixture.']]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->preparationRoot = app(CreateTask::class)->handle('orbit', 'Prepare feature', 'Complete brief.', TaskKind::Group, acceptanceCriteria: 'Complete criteria.');
    app(CreateTask::class)->handle('orbit', 'Implement feature', 'Child brief.', parent: $this->preparationRoot, acceptanceCriteria: 'Child criteria.');
    $this->preparationManifest = app(TaskRuntimePlan::class)->hash($this->preparationRoot);
    $this->preparationAdapter = new PreparationTestAdapter(app(NativeOrbitTaskWorktreePreparation::class));
    app()->instance(TaskWorktreePreparation::class, $this->preparationAdapter);
    preparationGit($this->preparationRepository, ['init', '--initial-branch=main']);
    preparationGit($this->preparationRepository, ['config', 'user.name', 'Fixture']);
    preparationGit($this->preparationRepository, ['config', 'user.email', 'fixture@example.test']);
    preparationGit($this->preparationRepository, ['config', 'orbit.worktreeRoot', $this->preparationDirectory.'/worktrees']);
    File::put($this->preparationRepository.'/.gitignore', "/.loop/\n/fixture-deps/\nvendor/\n.env\nbootstrap/cache/\n");
    File::put($this->preparationRepository.'/fixture-mode', 'success');
    File::makeDirectory($this->preparationRepository.'/.agents/skills/planning-features', 0700, true);
    File::put($this->preparationRepository.'/.agents/skills/planning-features/template.md', "Issue: {{ISSUE}}\nFlow: {{FLOW}}\nPlan scaffold\n");
    foreach (['worktree-create', 'bootstrap', 'loop-flow', 'tia-cache', 'worktree-cache'] as $script) {
        File::put($this->preparationRepository.'/bin/'.$script, "#!/usr/bin/env php\n<?php ".($script === 'worktree-create'
            ? 'require '.var_export(base_path('tests/Support/PreparationNativeScript.php'), true).';' : 'exit(0);'));
        chmod($this->preparationRepository.'/bin/'.$script, 0755);
    }
    foreach (['apps/cli' => 'phpunit.xml.dist', 'apps/docs' => 'phpunit.xml', 'apps/gateway' => 'phpunit.xml',
        'apps/e2e' => 'phpunit.xml', 'packages/php-sdk' => 'phpunit.xml.dist'] as $project => $xml) {
        $base = $this->preparationRepository.'/'.$project;
        File::makeDirectory($base, 0700, true);
        $environment = in_array($project, ['apps/cli', 'packages/php-sdk'], true) ? '' : '<env name="APP_ENV" value="testing"/>';
        if ($project === 'apps/gateway') {
            $environment .= '<env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value=":memory:"/><env name="DB_URL" value=""/>';
            File::makeDirectory($base.'/config', 0700, true);
            File::put($base.'/config/database.php', '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => env("DB_DATABASE"), "url" => env("DB_URL")]]];');
        }
        if (! in_array($project, ['apps/cli', 'packages/php-sdk'], true)) {
            File::put($base.'/.env.example', "APP_ENV=local\nCACHE_STORE=array\n");
        }
        $contents = '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php"><php>'.$environment.'</php></phpunit>';
        File::put($base.'/'.$xml, $contents);
        File::put($base.'/phpunit.guidance.xml', $contents);
        File::put($base.'/composer.lock', '{}');
        File::put($base.'/composer.json', json_encode(['scripts' => ['guidance:check' => 'vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
            'fixture-check' => '@fixture-nested', 'fixture-nested' => '@php isolation.php']], JSON_THROW_ON_ERROR));
        File::put($base.'/isolation.php', <<<'PHP'
            <?php
            if (getenv('DB_DATABASE') !== ':memory:' || getenv('DB_URL') !== '') { exit(98); }
            $db = new PDO('sqlite:'.getenv('DB_DATABASE'));
            $db->exec('CREATE TABLE fixture (value TEXT)');
            file_put_contents(__DIR__.'/../../.loop/isolation.json', json_encode([
                'database' => getenv('DB_DATABASE'), 'url' => getenv('DB_URL'), 'home' => getenv('HOME'),
                'temporary' => getenv('TMPDIR'), 'orbit' => getenv('ORBIT_HOME'), 'composer' => getenv('COMPOSER_HOME'),
                'key' => getenv('APP_KEY'), 'app_env' => getenv('APP_ENV'), 'sentinel' => getenv('COMMANDER_PREPARATION_SENTINEL'),
                'cache' => getenv('CACHE_STORE'), 'session' => getenv('SESSION_DRIVER'), 'queue' => getenv('QUEUE_CONNECTION'),
            ], JSON_THROW_ON_ERROR));
            PHP);
    }
    preparationGit($this->preparationRepository, ['add', '.']);
    preparationGit($this->preparationRepository, ['commit', '-m', 'Base']);
    preparationGit($this->preparationDirectory.'/remote', ['init', '--bare', '--initial-branch=main']);
    preparationGit($this->preparationRepository, ['remote', 'add', 'origin', $this->preparationDirectory.'/remote']);
    preparationGit($this->preparationRepository, ['push', '--set-upstream', 'origin', 'main']);
});

afterEach(function () {
    $pidFile = $this->preparationWorktree.'/.loop/child-pid';
    if (is_file($pidFile)) {
        $deadline = microtime(true) + 3;
        while (! is_file($this->preparationWorktree.'/.loop/child-finished') && microtime(true) < $deadline) {
            usleep(50000);
        }
    }
    File::deleteDirectory($this->preparationDirectory);
});

it('prepares and verifies one worktree without runtime rows then admits it separately', function () {
    $tasks = Task::query()->get()->toArray();
    $result = preparationRun();
    expect($result['worktree'])->toBe($this->preparationWorktree)
        ->and(Task::query()->get()->toArray())->toBe($tasks)
        ->and(TaskWorkspace::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)->and($this->preparationAdapter->ownershipReads)->toBe(2)
        ->and(File::get($this->preparationEvidence.'/stdout.log'))->toBe($this->preparationOutput['out'])
        ->and(File::get($this->preparationEvidence.'/stderr.log'))->toBe($this->preparationOutput['err'])
        ->and(filesize($this->preparationEvidence.'/stdout.log'))->toBeGreaterThan(10000)
        ->and($result['receipt']['landing_ready'])->toBeFalse()
        ->and(is_dir($this->preparationWorktree.'/.loop/proof'))->toBeTrue()
        ->and(File::get($this->preparationWorktree.'/.loop/plan.md'))->toContain('Plan scaffold');
    $workspace = preparationStart();
    expect($workspace->worktree)->toBe($this->preparationWorktree)->and(TaskRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'already exists');
});

it('prepares current five-project Orbit inputs with pinned Gateway safety and no provider or PDO probes', function () {
    preparationCurrentInputs();
    preparationMode('success');
    $result = preparationRun();
    $observed = $result['receipt']['observed'];
    foreach (['tests/bootstrap.php', 'tests/Support/TestDatabaseEnvironment.php'] as $path) {
        expect($observed['inputs_before'])->toHaveKey('apps/gateway/'.$path)
            ->and($observed['inputs_after'])->toBe($observed['inputs_before'])
            ->and($observed['configuration']['files']['apps/gateway'][$path])
            ->toBe(hash_file('sha256', $this->preparationWorktree.'/apps/gateway/'.$path));
    }
    expect($observed['configuration']['probes'])->toHaveCount(5)
        ->and($observed['configuration']['providers_booted'])->toBeFalse()
        ->and($observed['configuration']['pdo_opened'])->toBeFalse();
    foreach ($observed['configuration']['probes'] as $project => $policies) {
        foreach ($policies as $probe) {
            expect($probe['verified'])->toBeTrue()
                ->and($probe['providers_booted'])->toBeFalse()->and($probe['pdo_opened'])->toBeFalse();
            if ($project === 'apps/gateway') {
                expect($probe['database'])->toBe(['driver' => 'sqlite', 'database' => ':memory:', 'url' => ''])
                    ->and($probe['app_env'])->toBe('testing');
            }
        }
    }
    expect(TaskWorkspace::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(0);
});

it('refuses explicit allocated databases in memory-only Gateway runtime and guidance XML before setup', function (string $configuration) {
    preparationCurrentInputs();
    $allocation = $this->preparationDirectory.'/temporary/orbit-gateway-test-unallocated.sqlite';
    $path = $this->preparationRepository.'/apps/gateway/'.$configuration;
    File::put($path, str_replace('</php>', '<env name="ORBIT_TEST_DATABASE" value="'.$allocation.'"/></php>', File::get($path)));
    preparationMode('success');

    expect(fn () => preparationRun())->toThrow(LogicException::class, 'Allocated test databases')
        ->and(File::exists($allocation))->toBeFalse()
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with(['phpunit.xml', 'phpunit.guidance.xml']);

it('preserves memory-only preparation with empty allocation selectors in both Gateway XML policies', function () {
    preparationCurrentInputs();
    foreach (['phpunit.xml', 'phpunit.guidance.xml'] as $configuration) {
        $path = $this->preparationRepository.'/apps/gateway/'.$configuration;
        File::put($path, str_replace('</php>', '<env name="ORBIT_TEST_DATABASE" value=""/></php>', File::get($path)));
    }
    preparationMode('success');
    $result = preparationRun();

    foreach ($result['receipt']['observed']['configuration']['probes']['apps/gateway'] as $probe) {
        expect($probe['verified'])->toBeTrue()
            ->and($probe['database']['database'])->toBe(':memory:')
            ->and($probe['providers_booted'])->toBeFalse()->and($probe['pdo_opened'])->toBeFalse();
    }
});

it('refuses effective allocated databases in memory-only Gateway configuration probes', function (string $policy) {
    preparationCurrentInputs();
    preparationMode('success');
    preparationRun();
    $allocation = $this->preparationDirectory.'/temporary/orbit-gateway-test-unallocated.sqlite';
    $environment = TaskProcessEnvironment::isolated();
    if ($policy === 'runtime') {
        foreach (['.env', '.env.example'] as $file) {
            File::append($this->preparationWorktree.'/apps/gateway/'.$file, 'ORBIT_TEST_DATABASE='.$allocation."\n");
        }
    } else {
        $environment['ORBIT_TEST_DATABASE'] = $allocation;
    }

    expect(fn () => app(TaskPreparationConfiguration::class)->verify($this->preparationWorktree, $environment))
        ->toThrow(LogicException::class, 'Configuration-only '.$policy.' probe failed for apps/gateway.')
        ->and(File::exists($allocation))->toBeFalse()
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with(['runtime', 'guidance']);

it('refuses unsupported current bootstrap, force and command inputs before native setup', function (string $case) {
    preparationCurrentInputs();
    $base = $this->preparationRepository.'/apps/gateway';
    $xml = File::get($base.'/phpunit.guidance.xml');
    if ($case === 'bootstrap path') {
        $xml = str_replace('tests/bootstrap.php', 'tests/arbitrary.php', $xml);
        File::put($base.'/tests/arbitrary.php', '<?php file_put_contents(__DIR__."/executed", "unsafe");');
    } elseif (in_array($case, ['bootstrap bytes', 'helper bytes'], true)) {
        File::append($base.($case === 'bootstrap bytes' ? '/tests/bootstrap.php' : '/tests/Support/TestDatabaseEnvironment.php'),
            "\nfile_put_contents(__DIR__.\"/executed\", \"unsafe\");");
    } elseif (in_array($case, ['bootstrap link', 'helper link'], true)) {
        $path = $base.($case === 'bootstrap link' ? '/tests/bootstrap.php' : '/tests/Support/TestDatabaseEnvironment.php');
        File::move($path, $path.'.target');
        symlink($path.'.target', $path);
    } elseif ($case === 'force database') {
        $xml = str_replace('value=":memory:" force="true"', 'value="/unsafe" force="true"', $xml);
    } elseif ($case === 'force app env') {
        $xml = str_replace('value="testing" force="true"', 'value="production" force="true"', $xml);
    } elseif ($case === 'force url') {
        $xml = str_replace('name="DB_URL" value=""', 'name="DB_URL" value="sqlite:///unsafe"', $xml);
    } elseif ($case === 'force cache') {
        $xml = str_replace('name="CACHE_STORE" value="array"', 'name="CACHE_STORE" value="database" force="true"', $xml);
    } elseif ($case === 'redirect') {
        $xml = str_replace('</php>', '<env name="APP_CONFIG_CACHE" value="/unsafe"/></php>', $xml);
    } elseif ($case === 'force old bootstrap') {
        $xml = str_replace('tests/bootstrap.php', 'vendor/autoload.php', $xml);
    } elseif ($case === 'force another project') {
        $path = $this->preparationRepository.'/apps/docs/phpunit.guidance.xml';
        File::put($path, str_replace('value="testing"', 'value="testing" force="true"', File::get($path)));
    } elseif ($case === 'command') {
        $path = $this->preparationRepository.'/apps/cli/composer.json';
        $composer = json_decode(File::get($path), true);
        $composer['scripts']['guidance:check'] .= ' && touch executed';
        File::put($path, json_encode($composer));
    }
    File::put($base.'/phpunit.guidance.xml', $xml);
    preparationMode('success');
    expect(fn () => preparationRun())->toThrow(LogicException::class)
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBeFalse()
        ->and(File::exists($base.'/tests/executed'))->toBeFalse()
        ->and(File::exists($base.'/tests/Support/executed'))->toBeFalse()
        ->and(File::exists($this->preparationWorktree))->toBeFalse()
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with(['bootstrap path', 'bootstrap bytes', 'helper bytes', 'bootstrap link', 'helper link',
    'force database', 'force app env', 'force url', 'force cache', 'redirect', 'force old bootstrap',
    'force another project', 'command']);

it('binds Gateway safety files against changed remote inputs and post-setup tampering', function (string $case) {
    preparationCurrentInputs();
    preparationMode('success');
    $relative = 'apps/gateway/tests/'.($case === 'bootstrap after setup' ? 'bootstrap.php' : 'Support/TestDatabaseEnvironment.php');
    if ($case === 'remote helper') {
        $clone = $this->preparationDirectory.'/upstream';
        preparationGit($this->preparationDirectory, ['clone', $this->preparationDirectory.'/remote', $clone]);
        File::append($clone.'/'.$relative, "\n// changed remote safety input\n");
        preparationGit($clone, ['add', '.']);
        preparationGit($clone, ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Safety input changes']);
        preparationGit($clone, ['push', 'origin', 'main']);
        expect(fn () => preparationRun())->toThrow(LogicException::class, 'bootstrap or configuration inputs')
            ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse();
    } else {
        preparationRun();
        $configuration = app(TaskPreparationConfiguration::class);
        $before = $configuration->snapshot($this->preparationWorktree);
        File::append($this->preparationWorktree.'/'.$relative, "\n// changed after setup\n");
        expect($configuration->snapshot($this->preparationWorktree))->not->toBe($before)
            ->and(fn () => preparationStart())->toThrow(RuntimeException::class)
            ->and(TaskWorkspace::query()->count())->toBe(0);
    }
})->with(['remote helper', 'bootstrap after setup', 'helper after setup']);

it('refuses conflicting pins and ownership before any native setup', function (string $case) {
    if ($case === 'manifest') {
        $this->preparationManifest = str_repeat('a', 64);
    } elseif ($case === 'herdr') {
        $this->preparationAdapter->owned = true;
    } elseif ($case === 'directory') {
        File::makeDirectory($this->preparationWorktree);
    } elseif ($case === 'branch') {
        preparationGit($this->preparationRepository, ['branch', 'orb-900001-other']);
    } elseif ($case === 'remote') {
        preparationGit($this->preparationDirectory.'/remote', ['update-ref', 'refs/heads/orb-900001', preparationGit($this->preparationRepository, ['rev-parse', 'HEAD'])]);
    } elseif ($case === 'dirty') {
        File::put($this->preparationRepository.'/dirty', 'untracked');
    } elseif ($case === 'non-main') {
        preparationGit($this->preparationRepository, ['checkout', '-b', 'wrong']);
    } elseif ($case === 'native-root') {
        preparationGit($this->preparationRepository, ['config', 'orbit.worktreeRoot', $this->preparationDirectory]);
    } elseif ($case === 'legacy') {
        $guard = app(TaskPreparationGuard::class);
        $guard->issue($guard->common($this->preparationRepository), 'ORB-900001', false)->release();
        File::put(dirname($this->preparationEvidence).'/state.json', '{"schema":1,"issue":"ORB-900001","status":"needs_attention"}');
    } elseif ($case === 'workspace') {
        TaskWorkspace::query()->create(['root_task_id' => $this->preparationRoot->id, 'project_id' => 'orbit', 'source_key' => 'ORB-900001',
            'repository' => $this->preparationRepository, 'worktree' => $this->preparationWorktree,
            'base_sha' => str_repeat('a', 40), 'manifest_hash' => $this->preparationManifest, 'configuration' => []]);
    }
    $before = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(fn () => preparationRun())->toThrow(LogicException::class)
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBeFalse()
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($before);
})->with(['manifest', 'herdr', 'directory', 'branch', 'remote', 'dirty', 'non-main', 'native-root', 'legacy', 'workspace']);

it('retains partial and interrupted setup and blocks admission before snapshot writes after lock release', function (string $mode) {
    preparationMode($mode);
    config(['task-runtime.preparation.timeout' => 1]);
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'unresolved');
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(File::exists($this->preparationEvidence.'/intent.json'))->toBeTrue()
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(File::get($this->preparationEvidence.'/stdout.log'))->toContain(str_repeat('captured stdout ', 1000))
        ->and(File::get($this->preparationEvidence.'/stderr.log'))->toContain(str_repeat('captured stderr ', 1000));
    foreach (['ORB-900001', 'OTHER'] as $source) {
        expect(fn () => preparationStart($source))->toThrow(LogicException::class, 'preparation');
    }
    expect(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs)
        ->and(TaskWorkspace::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(fn () => preparationRun())->toThrow(LogicException::class, 'already exists');
})->with(['nonzero', 'timeout', 'orphan']);

it('refuses hidden primary setup edits before invocation without clearing their index flags', function (string $flag, bool $afterIntent) {
    $marker = $this->preparationDirectory.'/hidden-script-executed';
    $hide = function () use ($flag, $marker): void {
        preparationGit($this->preparationRepository, ['update-index', $flag, 'bin/worktree-create']);
        File::put($this->preparationRepository.'/bin/worktree-create', "#!/usr/bin/env php\n<?php file_put_contents(".var_export($marker, true).", 'executed'); exit(17);\n");
        expect(preparationGit($this->preparationRepository, ['status', '--porcelain=v1']))->toBe('');
    };
    if ($afterIntent) {
        $this->preparationAdapter->duringNative = $hide;
    } else {
        $hide();
    }
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(fn () => preparationRun())->toThrow($afterIntent ? LogicException::class : RuntimeException::class, 'skip-worktree or assume-unchanged')
        ->and(File::exists($marker))->toBeFalse()
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBe($afterIntent)
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(File::exists($this->preparationWorktree))->toBeFalse()
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs)
        ->and(preparationGit($this->preparationRepository, ['ls-files', '-v', 'bin/worktree-create']))->toStartWith($flag === '--skip-worktree' ? 'S ' : 'h ')
        ->and(TaskWorkspace::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0);
})->with([
    'skip before intent' => ['--skip-worktree', false],
    'assume before intent' => ['--assume-unchanged', false],
    'skip after intent' => ['--skip-worktree', true],
    'assume after intent' => ['--assume-unchanged', true],
]);

it('refuses primary input drift hidden by cached stats or file-mode configuration without index writes', function (string $kind, bool $afterIntent) {
    $relative = $kind === 'script bytes' ? 'bin/worktree-create' : 'apps/cli/phpunit.xml.dist';
    $path = $this->preparationRepository.'/'.$relative;
    $contents = File::get($path).($kind === 'script bytes' ? "\n// original\n" : "\n<!-- original -->\n");
    File::put($path, $contents);
    preparationGit($this->preparationRepository, ['add', $relative]);
    preparationGit($this->preparationRepository, ['commit', '-m', 'Same-size input fixture']);
    preparationGit($this->preparationRepository, ['push', 'origin', 'main']);
    preparationGit($this->preparationRepository, ['config', 'core.trustctime', 'false']);
    preparationGit($this->preparationRepository, ['config', 'core.checkStat', 'minimal']);
    preparationGit($this->preparationRepository, ['config', 'core.fileMode', 'false']);
    $timestamp = time() - 120;
    touch($path, $timestamp);
    preparationGit($this->preparationRepository, ['update-index', '--refresh']);
    $index = null;
    $hide = function () use ($kind, $relative, $path, $contents, $timestamp, &$index): void {
        if ($kind === 'config mode') {
            chmod($path, 0755);
        } else {
            $modified = str_replace('original', 'modified', $contents);
            expect(strlen($modified))->toBe(strlen($contents));
            File::put($path, $modified);
            touch($path, $timestamp);
            expect(preparationGit($this->preparationRepository, ['hash-object', '--no-filters', '--', $relative]))
                ->not->toBe(preparationGit($this->preparationRepository, ['rev-parse', 'HEAD:'.$relative]));
        }
        expect(preparationGit($this->preparationRepository, ['status', '--porcelain=v1']))->toBe('')
            ->and(preparationGit($this->preparationRepository, ['ls-files', '-v', $relative]))->toBe('H '.$relative);
        $index = File::get($this->preparationRepository.'/.git/index');
    };
    if ($afterIntent) {
        $this->preparationAdapter->duringNative = $hide;
    } else {
        $hide();
    }
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    $fetch = File::exists($this->preparationRepository.'/.git/FETCH_HEAD')
        ? File::get($this->preparationRepository.'/.git/FETCH_HEAD') : null;
    expect(fn () => preparationRun())->toThrow(LogicException::class, $kind === 'config mode'
        ? 'input mode differs' : 'input bytes differ')
        ->and($index)->toBeString()
        ->and(File::get($this->preparationRepository.'/.git/index'))->toBe($index)
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/native-exit.json'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBe($afterIntent)
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(File::exists($this->preparationWorktree))->toBeFalse()
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs)
        ->and(File::exists($this->preparationRepository.'/.git/FETCH_HEAD')
            ? File::get($this->preparationRepository.'/.git/FETCH_HEAD') : null)->toBe($fetch)
        ->and(TaskWorkspace::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'script bytes before intent' => ['script bytes', false],
    'script bytes after intent' => ['script bytes', true],
    'config bytes before intent' => ['config bytes', false],
    'config bytes after intent' => ['config bytes', true],
    'config mode before intent' => ['config mode', false],
    'config mode after intent' => ['config mode', true],
]);

it('uses the native shared issue and checkout locks and blocks concurrent admission before Git writes', function (string $kind) {
    $guard = app(TaskPreparationGuard::class);
    $common = $guard->common($this->preparationRepository);
    $issue = $guard->issue($common, 'ORB-900001', false);
    $held = $kind === 'issue' ? $issue : $guard->checkout($common);
    if ($kind === 'checkout') {
        $issue->release();
    }
    try {
        expect(fn () => preparationRun())->toThrow(LogicException::class, 'Another controller')
            ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse();
        if ($kind === 'issue') {
            expect(fn () => preparationStart())->toThrow(LogicException::class, 'Another controller');
        }
    } finally {
        $held->release();
    }
})->with(['issue', 'checkout']);

it('permits unrelated authoritative main advancement after object-only input preflight', function () {
    $clone = $this->preparationDirectory.'/upstream';
    preparationGit($this->preparationDirectory, ['clone', $this->preparationDirectory.'/remote', $clone]);
    File::put($clone.'/new-feature.txt', 'Unrelated feature');
    preparationGit($clone, ['add', '.']);
    preparationGit($clone, ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Main advances']);
    preparationGit($clone, ['push', 'origin', 'main']);
    $before = preparationGit($this->preparationRepository, ['rev-parse', 'HEAD']);
    $result = preparationRun();
    $head = preparationGit($clone, ['rev-parse', 'HEAD']);
    expect($result['receipt']['observed']['primary_before'])->toBe($before)
        ->and($result['receipt']['observed']['primary_after'])->toBe($head)
        ->and($result['receipt']['observed']['feature_head'])->toBe($head)
        ->and(json_decode(File::get($this->preparationEvidence.'/preflight.json'), true)['object_only_fetch'])->toBeTrue();
});

it('refuses changed remote native inputs without creating a worktree or changing refs', function () {
    $clone = $this->preparationDirectory.'/upstream';
    preparationGit($this->preparationDirectory, ['clone', $this->preparationDirectory.'/remote', $clone]);
    File::append($clone.'/bin/bootstrap', "\n// changed input\n");
    preparationGit($clone, ['add', '.']);
    preparationGit($clone, ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Bootstrap changes']);
    preparationGit($clone, ['push', 'origin', 'main']);
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'bootstrap or configuration inputs')
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs)
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBeTrue();
});

it('rejects setup verification defects and retains the unresolved guard', function (string $mode) {
    preparationMode($mode);
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'unresolved')
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(fn () => preparationStart())->toThrow(LogicException::class, 'preparation');
})->with(['flow', 'wrong-head', 'wrong-path', 'vendor-escape', 'nested-vendor-escape', 'config-cache', 'dotenv-drift', 'testing-dotenv']);

it('keeps unrelated broken preparation independent from a manual admission', function (string $state) {
    $guard = app(TaskPreparationGuard::class);
    $common = $guard->common($this->preparationRepository);
    $guard->issue($common, 'ORB-900002', false)->release();
    $other = $guard->directory($common, 'ORB-900002');
    File::makeDirectory($other, 0700);
    if ($state === 'corrupt') {
        File::put($other.'/intent.json', '{broken');
    }
    preparationGit($this->preparationRepository, ['worktree', 'add', '-b', 'orb-900001', $this->preparationWorktree]);
    expect(preparationStart()->worktree)->toBe($this->preparationWorktree)
        ->and(File::exists($other))->toBeTrue();
})->with(['missing', 'corrupt']);

it('preserves admission for non-Orbit projects without consulting Orbit preparation evidence', function () {
    preparationGit($this->preparationRepository, ['worktree', 'add', '-b', 'orb-900001', $this->preparationWorktree]);
    app(SharedKnowledgeProjectRepository::class)->create('example', ['name' => 'Example', 'status' => 'active']);
    $root = app(CreateTask::class)->handle('example', 'Other project', 'Other project brief.', TaskKind::Group, acceptanceCriteria: 'Other criteria.');
    app(CreateTask::class)->handle('example', 'Other child', 'Child brief.', parent: $root, acceptanceCriteria: 'Child criteria.');
    config(['task-runtime.projects.example' => config('task-runtime.projects.orbit')]);
    $guard = app(TaskPreparationGuard::class);
    $guard->issue($guard->common($this->preparationRepository), 'ORB-900001', false)->release();
    File::makeDirectory($this->preparationEvidence, 0700);
    expect(app(StartTaskWorkspace::class)->handle($root, $this->preparationWorktree,
        app(TaskRuntimePlan::class)->hash($root), 'ANY-SOURCE', true)->project_id)->toBe('example');
});

it('preserves existing admitted workspace behavior after unrelated preparation evidence appears', function () {
    preparationGit($this->preparationRepository, ['worktree', 'add', '-b', 'orb-900001', $this->preparationWorktree]);
    $workspace = preparationStart();
    File::makeDirectory($this->preparationEvidence, 0700);
    expect(preparationStart()->id)->toBe($workspace->id);
});

it('records intent before native setup and holds the shared issue lock throughout preparation', function () {
    $this->preparationAdapter->duringNative = function ($before, $directory) {
        expect(File::exists($directory.'/intent.json'))->toBeTrue()
            ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
            ->and(fn () => preparationStart())->toThrow(LogicException::class, 'Another controller');
        $config = new OrbitProjectConfig('orbit', $this->preparationRepository, $this->preparationDirectory.'/worktrees', 'unused', 1, 'discovery');
        expect(fn () => app(ProcessOrbitRepository::class)->reserveDelivery($config, 'ORB-900001'))->toThrow(RuntimeException::class);
    };
    preparationRun();
});

it('rechecks ownership and manifest after native setup and withholds success on drift', function (string $case) {
    $this->preparationAdapter->duringNative = function () use ($case) {
        if ($case === 'manifest') {
            DB::table('tasks')->where('id', $this->preparationRoot->id)->update(['description' => 'Changed during native setup.']);
        } else {
            $this->preparationAdapter->owned = true;
        }
    };
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'unresolved')
        ->and(json_decode(File::get($this->preparationEvidence.'/native-exit.json'), true)['exit_code'])->toBe(0)
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with(['manifest', 'ownership']);

it('rejects changed preparation evidence, configuration, or HEAD before writing snapshot refs', function (string $case) {
    preparationRun();
    if ($case === 'receipt') {
        $path = $this->preparationEvidence.'/success.json';
        $receipt = json_decode(File::get($path), true);
        $receipt['pins']['root']++;
        File::put($path, json_encode($receipt));
    } elseif ($case === 'head') {
        preparationGit($this->preparationWorktree, ['commit', '--allow-empty', '-m', 'Changed after setup']);
    } elseif ($case === 'flow') {
        File::put($this->preparationWorktree.'/.loop/flow.json', '{"schema":1,"flow":"proof"}');
    } else {
        File::put($this->preparationWorktree.'/apps/gateway/.env', 'DB_DATABASE=unsafe');
    }
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(fn () => preparationStart())->toThrow(LogicException::class)
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs)
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with(['receipt', 'head', 'flow', 'dotenv']);

it('refuses forced or redirecting guidance configuration before native setup', function (string $setting) {
    $path = $this->preparationRepository.'/apps/gateway/phpunit.guidance.xml';
    File::put($path, '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php"><php>'.$setting.'</php></phpunit>');
    preparationGit($this->preparationRepository, ['add', '.']);
    preparationGit($this->preparationRepository, ['commit', '-m', 'Unsupported guidance']);
    preparationGit($this->preparationRepository, ['push', 'origin', 'main']);
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'guidance configuration')
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/intent.json'))->toBeFalse();
})->with([
    '<env name="DB_DATABASE" value="/unsafe" force="true"/>',
    '<env name="APP_CONFIG_CACHE" value="/unsafe"/>',
    '<server name="DB_DATABASE" value="/unsafe"/>',
]);

it('refuses active Delivery source or path ownership without changing that delivery', function (string $match) {
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', ['type' => 'orbit',
        'repository' => $this->preparationRepository, 'worktreeRoot' => $this->preparationDirectory.'/worktrees',
        'herdrSession' => 'unused', 'concurrency' => 1, 'defaultFlow' => 'discovery']);
    $delivery = Delivery::query()->create(['project_orchestration_id' => $project->id,
        'external_issue_provider' => 'linear', 'external_issue_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'external_issue_key' => $match === 'source' ? 'ORB-900001' : 'ORB-900002',
        'worktree_path' => $match === 'path' ? $this->preparationWorktree : null,
        'workflow_type' => 'orbit-feature', 'workflow_version' => 1, 'status' => DeliveryStatus::Preparing, 'current_phase' => 'planning']);
    $before = $delivery->fresh()->toArray();
    expect(fn () => preparationRun())->toThrow(LogicException::class, 'active Delivery')
        ->and($delivery->fresh()->toArray())->toBe($before)
        ->and(File::exists($this->preparationDirectory.'/native-called'))->toBeFalse();
})->with(['source', 'path']);

it('uses only a read-only Herdr snapshot and rejects known workspace or nested pane ownership', function (string $kind) {
    $socketPath = $this->preparationDirectory.'/snapshot.sock';
    $server = stream_socket_server('unix://'.$socketPath, $error, $message);
    expect(is_resource($server))->toBeTrue();
    $child = pcntl_fork();
    if ($child === 0) {
        $connection = stream_socket_accept($server, 5);
        $request = json_decode(fgets($connection), true);
        file_put_contents($this->preparationDirectory.'/snapshot-request', $request['method']);
        $result = ['type' => 'session_snapshot', 'snapshot' => ['version' => 'fixture', 'protocol' => 22,
            'tabs' => [], 'layouts' => [], 'agents' => [],
            'workspaces' => $kind === 'workspace' ? [['workspace_id' => 'occupied', 'worktree' => [
                'repo_root' => $this->preparationRepository, 'checkout_path' => $this->preparationWorktree, 'is_linked_worktree' => true]]] : [],
            'panes' => $kind === 'pane' ? [['workspace_id' => 'occupied', 'tab_id' => 'tab', 'pane_id' => 'pane',
                'terminal_id' => 'terminal', 'cwd' => $this->preparationWorktree.'/apps/cli']] : []]];
        fwrite($connection, json_encode(['id' => $request['id'], 'result' => $result])."\n");
        fclose($connection);
        fclose($server);
        exit(0);
    }
    try {
        if ($kind === 'empty') {
            app(NativeOrbitTaskWorktreePreparation::class)->assertUnowned($socketPath, $this->preparationWorktree);
        } else {
            expect(fn () => app(NativeOrbitTaskWorktreePreparation::class)->assertUnowned($socketPath, $this->preparationWorktree))
                ->toThrow(LogicException::class, 'Herdr');
        }
        expect(File::get($this->preparationDirectory.'/snapshot-request'))->toBe('session.snapshot');
    } finally {
        fclose($server);
        pcntl_waitpid($child, $status);
    }
})->with(['empty', 'workspace', 'pane']);

it('blocks admission after the preparation parent is killed and its native children later exit', function () {
    preparationMode('lost-parent');
    $runner = pcntl_fork();
    if ($runner === 0) {
        preparationRun();
        exit(0);
    }
    expect($runner)->toBeGreaterThan(0);
    $deadline = microtime(true) + 10;
    while (! is_file($this->preparationWorktree.'/.loop/native-pid') && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect(is_file($this->preparationWorktree.'/.loop/native-pid'))->toBeTrue();
    posix_kill($runner, SIGKILL);
    pcntl_waitpid($runner, $status);
    expect(pcntl_wifsignaled($status))->toBeTrue();
    $deadline = microtime(true) + 8;
    while (! is_file($this->preparationWorktree.'/.loop/native-finished') && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect(is_file($this->preparationWorktree.'/.loop/native-finished'))->toBeTrue()
        ->and(is_file($this->preparationWorktree.'/.loop/child-finished'))->toBeTrue();
    while (microtime(true) < $deadline) {
        try {
            $guard = app(TaskPreparationGuard::class);
            $guard->issue($guard->common($this->preparationRepository), 'ORB-900001', false)->release();
            break;
        } catch (LogicException) {
            usleep(50000);
        }
    }
    $refs = preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']);
    expect(fn () => preparationStart())->toThrow(LogicException::class, 'Unresolved preparation')
        ->and(File::exists($this->preparationEvidence.'/native-exit.json'))->toBeFalse()
        ->and(File::exists($this->preparationEvidence.'/success.json'))->toBeFalse()
        ->and(preparationGit($this->preparationRepository, ['for-each-ref', '--format=%(refname) %(objectname)']))->toBe($refs);
});

it('prints the separate admission command and does not enqueue runtime work', function () {
    $this->artisan('tasks:prepare-worktree', ['project' => 'orbit', 'task' => $this->preparationRoot->id,
        '--source' => 'ORB-900001', '--manifest' => $this->preparationManifest, '--exclusive' => true])
        ->expectsOutputToContain('task admission is a separate operation')
        ->expectsOutputToContain('php artisan tasks:start')->assertSuccessful();
    expect(TaskWorkspace::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('isolates real nested Composer PHP children from coordinator environment and database', function () {
    $database = $this->preparationDirectory.'/coordinator.sqlite';
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE sentinel (value TEXT); INSERT INTO sentinel VALUES ("preserved")');
    $pdo = null;
    $hash = hash_file('sha256', $database);
    $previous = [];
    foreach (['DB_DATABASE' => $database, 'DB_URL' => 'sqlite:///'.$database, 'COMMANDER_PREPARATION_SENTINEL' => 'private',
        'APP_ENV' => 'commander', 'APP_KEY' => 'commander-key', 'ORBIT_HOME' => '/unsafe', 'COMPOSER_HOME' => '/unsafe'] as $key => $value) {
        $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    try {
        preparationRun();
    } finally {
        foreach ($previous as $key => [$value, $env, $server]) {
            putenv($value === false ? $key : $key.'='.$value);
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }
    $observed = json_decode(File::get($this->preparationWorktree.'/.loop/isolation.json'), true);
    expect($observed['database'])->toBe(':memory:')->and($observed['url'])->toBe('')
        ->and($observed['home'])->toBe(getenv('HOME'))->and($observed['app_env'])->toBeFalse()
        ->and($observed['sentinel'])->toBeFalse()->and($observed['key'])->not->toBe('commander-key')
        ->and($observed['cache'])->toBe('array')->and($observed['session'])->toBe('array')->and($observed['queue'])->toBe('sync')
        ->and($observed['temporary'])->toStartWith($this->preparationDirectory.'/temporary/')
        ->and($observed['orbit'])->toStartWith($this->preparationDirectory.'/temporary/')
        ->and($observed['composer'])->toStartWith($this->preparationDirectory.'/temporary/')
        ->and(hash_file('sha256', $database))->toBe($hash);
});
