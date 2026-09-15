<?php

use App\Models\TaskCloseoutOperation;
use App\Models\TaskWorkspace;
use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Orbit\PrepareTaskSnapshotReacquisition;
use App\Tasks\Orbit\ReacquireTaskSnapshot;
use App\Tasks\Orbit\ReviewTaskSnapshotReacquisition;
use App\Tasks\Orbit\SnapshotReacquisitionBridge;
use App\Tasks\Orbit\TaskSnapshotReacquisitionEvidence;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\TaskCloseoutFakeRemote;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

function snapshotFlowPreparationGit(string $repository, array $arguments): string
{
    return trim(Process::path($repository)->timeout(30)->env(array_merge(TaskProcessEnvironment::isolated(), [
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
    ]))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output());
}

function snapshotFlowPreparationFile(string $path, string $contents, int $mode = 0644): void
{
    File::ensureDirectoryExists(dirname($path), 0700);
    File::put($path, $contents);
    chmod($path, $mode);
}

function snapshotFlowPreparationFreeze(): array
{
    return app(PrepareTaskSnapshotReacquisition::class)->freeze(test()->snapshotFlowPreparationIdentity);
}

function snapshotFlowPreparationArtifact(?Closure $amend = null, ?string $parent = null): string
{
    $test = test();
    $repository = $test->snapshotFlowPreparationRepository;
    $fixture = "<?php\n// A descriptor-approved test fixture, not an actual Orbit verification.\n";
    $descriptor = ['schema' => 1, 'files' => ['.loop/proof/snapshot-reacquire.php' => ['sha256' => hash('sha256', $fixture), 'mode' => '644']],
        'discovery_action' => ['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway',
            'argv' => ['php', '/home/orbit/orbit/.loop/proof/snapshot-reacquire.php'], 'timeout_seconds' => 60],
        'proof_action' => ['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway',
            'argv' => ['php', '/var/lib/orbit-e2e/proof/snapshot-reacquire.php'], 'timeout_seconds' => 60],
        'inputs' => ['product.txt']];
    if ($amend !== null) {
        $descriptor = $amend($descriptor);
    }
    snapshotFlowPreparationFile($repository.'/.loop/proof/snapshot-reacquire.php', $fixture);
    snapshotFlowPreparationFile($repository.'/.loop/proof/snapshot-reacquire.json', Data::json($descriptor));
    snapshotFlowPreparationFile($repository.'/.loop/proof/unapproved.php', "<?php // never restored\n");
    snapshotFlowPreparationFile($repository.'/.loop/proof/ORB-91.json', Data::json(['setup' => [], 'acceptance' => [$descriptor['proof_action']], 'snapshot_replacement' => true]));
    $head = snapshotFlowPreparationGit($repository, ['rev-parse', 'HEAD']);
    snapshotFlowPreparationGit($repository, ['read-tree', $test->snapshotFlowPreparationIdentity['accepted_candidate']]);
    snapshotFlowPreparationGit($repository, ['add', '-f', '.loop']);
    $tree = snapshotFlowPreparationGit($repository, ['write-tree']);
    $artifact = snapshotFlowPreparationGit($repository, ['commit-tree', $tree, '-p', $parent ?? $test->snapshotFlowPreparationIdentity['accepted_candidate'], '-m', 'Fixture native artifact']);
    snapshotFlowPreparationGit($repository, ['read-tree', $head]);
    snapshotFlowPreparationGit($repository, ['update-ref', 'refs/tags/loop/orb-91/'.$test->snapshotFlowPreparationIdentity['accepted_candidate'], $artifact]);
    $identity = $test->snapshotFlowPreparationIdentity;
    $identity['accepted_artifact'] = $artifact;
    $test->snapshotFlowPreparationIdentity = $identity;

    return $artifact;
}

beforeEach(function () {
    $this->snapshotFlowPreparationDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/snapshot-preparation-test-XXXXXXXX'])->throw()->output());
    $repository = $this->snapshotFlowPreparationDirectory.'/repository';
    $root = $this->snapshotFlowPreparationDirectory.'/worktrees';
    $this->snapshotFlowPreparationRepository = $repository;
    File::makeDirectory($repository, 0700);
    File::makeDirectory($root, 0700);
    snapshotFlowPreparationGit($repository, ['init', '--initial-branch=main']);
    snapshotFlowPreparationGit($repository, ['config', 'user.name', 'Fixture']);
    snapshotFlowPreparationGit($repository, ['config', 'user.email', 'fixture@example.test']);
    snapshotFlowPreparationGit($repository, ['config', 'orbit.worktreeRoot', $root]);
    snapshotFlowPreparationFile($repository.'/.gitignore', "/.loop/\n/.e2e/\nvendor\n.env\n**/bootstrap/cache/\n");
    snapshotFlowPreparationFile($repository.'/product.txt', "C product\n");
    foreach (['bin/worktree-cache', 'bin/tia-cache', 'bin/pest-setup', 'bin/e2e-topology', 'bin/loop-artifacts'] as $path) {
        snapshotFlowPreparationFile($repository.'/'.$path, "#!/usr/bin/env php\n<?php exit(0);\n", 0755);
    }
    snapshotFlowPreparationFile($repository.'/apps/e2e/bootstrap/app.php', '<?php // Native bridge is faked.');
    snapshotFlowPreparationFile($repository.'/bin/pest-support/manifest.json', '{}');
    snapshotFlowPreparationFile($repository.'/bin/pest-support/monorepo.patch', 'fixture patch');
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        snapshotFlowPreparationFile($repository.'/'.$project.'/composer.json', Data::json(['scripts' => ['guidance:check' => 'ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact']]));
        snapshotFlowPreparationFile($repository.'/'.$project.'/composer.lock', '{}');
        snapshotFlowPreparationFile($repository.'/'.$project.'/.env.example', "APP_ENV=local\n");
        $xml = '<phpunit bootstrap="vendor/autoload.php"><php><env name="APP_ENV" value="testing"/></php></phpunit>';
        snapshotFlowPreparationFile($repository.'/'.$project.'/phpunit.guidance.xml', $xml);
        snapshotFlowPreparationFile($repository.'/'.$project.'/'.(in_array($project, ['apps/cli', 'packages/php-sdk'], true) ? 'phpunit.xml.dist' : 'phpunit.xml'), $xml);
    }
    snapshotFlowPreparationFile($repository.'/bin/bootstrap', <<<'PHP'
        #!/usr/bin/env php
        <?php
        $root = dirname(__DIR__);
        $runtime = $root.'/.e2e/commander-snapshot-bootstrap';
        echo json_encode(array_map(fn ($key) => getenv($key), array_combine(
            ['HOME', 'ORBIT_HOME', 'COMPOSER_HOME', 'TMPDIR', 'XDG_CONFIG_HOME', 'APP_KEY', 'DB_DATABASE', 'DB_URL', 'APP_BASE_PATH', 'COMPOSER_VENDOR_DIR', 'COMMANDER_PREPARATION_SENTINEL', 'BASH_ENV'],
            ['HOME', 'ORBIT_HOME', 'COMPOSER_HOME', 'TMPDIR', 'XDG_CONFIG_HOME', 'APP_KEY', 'DB_DATABASE', 'DB_URL', 'APP_BASE_PATH', 'COMPOSER_VENDOR_DIR', 'COMMANDER_PREPARATION_SENTINEL', 'BASH_ENV']
        )), JSON_THROW_ON_ERROR)."\n";
        fwrite(STDERR, "fixture bootstrap stderr\n");
        foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
            mkdir($root.'/'.$project.'/vendor/composer', 0700, true);
            file_put_contents($root.'/'.$project.'/vendor/autoload.php', '<?php // independently installed fixture');
            file_put_contents($root.'/'.$project.'/vendor/composer/installed.json', '{"packages":[]}');
            copy($root.'/'.$project.'/.env.example', $root.'/'.$project.'/.env');
        }
        PHP, 0755);
    snapshotFlowPreparationGit($repository, ['add', '.']);
    snapshotFlowPreparationGit($repository, ['commit', '-m', 'C fixture']);
    $candidate = snapshotFlowPreparationGit($repository, ['rev-parse', 'HEAD']);
    $accepted = $root.'/orb-91';
    snapshotFlowPreparationGit($repository, ['worktree', 'add', '-b', 'orb-91', $accepted, $candidate]);
    $this->snapshotFlowPreparationIdentity = ['schema' => 1, 'issue' => 'ORB-91', 'repository' => $repository, 'worktree_root' => $root,
        'accepted_worktree' => $accepted, 'accepted_candidate' => $candidate, 'accepted_artifact' => '', 'merged_main' => ''];
    snapshotFlowPreparationArtifact();
    snapshotFlowPreparationFile($repository.'/product.txt', "M product\n");
    snapshotFlowPreparationGit($repository, ['add', 'product.txt']);
    snapshotFlowPreparationGit($repository, ['commit', '-m', 'M fixture']);
    $identity = $this->snapshotFlowPreparationIdentity;
    $identity['merged_main'] = snapshotFlowPreparationGit($repository, ['rev-parse', 'HEAD']);
    $this->snapshotFlowPreparationIdentity = $identity;
    snapshotFlowFixture();
});

afterEach(function () {
    $directory = $this->snapshotFlowPreparationDirectory;
    if (is_string($directory) && str_starts_with($directory, sys_get_temp_dir().'/snapshot-preparation-test-') && realpath($directory) === $directory) {
        File::deleteDirectory($directory);
    }
});

final class SnapshotFlowReviewer implements TaskLandingReviewer
{
    public int $prompts = 0;

    public string $verdict = 'pass';

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        return $session;
    }

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts++;
        // Fast submission takes the coordinator lock while transport is in progress.
        $operation = TaskCloseoutOperation::query()->where('operation', 'like', 'snapshot-review:%')->latest('id')->firstOrFail();
        preg_match('/"token": "([A-Za-z0-9]+)"/', Crypt::decryptString($operation->preflight['prompt']), $matches);
        app(ReviewTaskSnapshotReacquisition::class)->submit($operation->id, ['token' => $matches[1],
            'assignment' => $operation->assignment, 'bundle_hash' => $operation->input['bundle_hash'], 'verdict' => $this->verdict,
            'summary' => 'Independent fixture assessment.', 'evidence' => 'Exact G, both attempts and concrete sample checks inspected.']);

        return $session;
    }
}

function snapshotFlowFixture(): void
{
    $test = test();
    $identity = $test->snapshotFlowPreparationIdentity;
    $test->fixture = new TaskCloseoutFixture($test->snapshotFlowPreparationDirectory.'/commander');
    $landing = $test->fixture->add(91);
    $workspace = $landing->workspace()->firstOrFail();
    app()->useStoragePath($test->snapshotFlowPreparationDirectory.'/storage');
    $session = [...$workspace->reviewer_session, 'workingDirectory' => $identity['accepted_worktree']];
    $configuration = [...$workspace->configuration, 'repository' => $identity['repository'],
        'worktree_root' => $identity['worktree_root'], 'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
    $proof = ['attempt_id' => str_repeat('e', 32), 'capture_fingerprint' => str_repeat('9', 64)];
    DB::table('task_workspaces')->where('id', $workspace->id)->update(['configuration' => Data::json($configuration),
        'repository' => $identity['repository'], 'worktree' => $identity['accepted_worktree'], 'reviewer_session' => Data::json($session),
        'final_check' => Data::json(['sha' => $identity['accepted_candidate'], 'exit_code' => 0, 'native_proof' => $proof])]);
    DB::table('task_agent_dispatches')->where('id', $landing->final_dispatch_id)->update(['session' => Data::json($session)]);
    DB::table('task_runs')->where('root_task_id', $workspace->root_task_id)->update(['commit_sha' => $identity['accepted_candidate']]);
    $request = [...$landing->request, 'candidate' => $identity['accepted_candidate']];
    $inputs = [...$landing->inputs, 'schema' => 2, 'database' => app(TaskLandingEvidence::class)->database($workspace->fresh(), $request)];
    $package = [...$landing->package, 'schema' => 2, 'candidate_sha' => $identity['accepted_candidate'],
        'artifact_sha' => $identity['accepted_artifact'], 'artifact_ref' => 'refs/tags/loop/orb-91/'.$identity['accepted_candidate'],
        'input_hash' => Data::hash($inputs)];
    $review = [...$landing->review_result, 'package_hash' => Data::hash($package), 'session' => $session,
        'candidate_sha' => $identity['accepted_candidate'], 'artifact_sha' => $identity['accepted_artifact']];
    DB::table('task_landings')->where('id', $landing->id)->update(['request' => Data::json($request), 'inputs' => Data::json($inputs),
        'input_hash' => Data::hash($inputs), 'package' => Data::json($package), 'package_hash' => Data::hash($package),
        'candidate_sha' => $identity['accepted_candidate'], 'artifact_sha' => $identity['accepted_artifact'], 'artifact_ref' => $package['artifact_ref'],
        'review_result' => Data::json($review), 'review_session' => Data::json($session)]);
    $test->landing = $landing->refresh();
    $test->flowReviewer = new SnapshotFlowReviewer;
    app()->instance(TaskLandingReviewer::class, $test->flowReviewer);
    $test->flowRemote = new TaskCloseoutFakeRemote;
    $pr = ['url' => 'https://github.com/fixture/orbit/pull/91', 'number' => 91, 'head_sha' => $landing->candidate_sha];
    $test->flowRemote->publications[$landing->id] = $pr;
    $merge = ['candidate_sha' => $landing->candidate_sha, 'merge_sha' => $identity['merged_main']];
    $test->flowRemote->merges[$landing->id] = $merge;
    $test->flowRemote->reserved = ['landing_id' => $landing->id, 'url' => $pr['url'], 'candidate_sha' => $landing->candidate_sha];
    app()->instance(TaskCloseoutGitHub::class, $test->flowRemote);
    app()->instance(TaskCloseoutRepository::class, $test->flowRemote);
    $ledger = app(TaskCloseoutLedger::class);
    $ledger->record($landing, 'publication', ['package_hash' => $landing->package_hash], $pr);
    $ledger->record($landing, 'merge', ['package_hash' => $landing->package_hash], $merge);
    $verification = ['main_sha' => $identity['merged_main'], 'lineage' => ['flow' => 'proof',
        'candidate' => $landing->candidate_sha, 'merge' => $identity['merged_main']]];
    $ledger->record($landing, 'verify:'.Data::hash($verification), ['package_hash' => $landing->package_hash], $verification);
    $test->flowCloseout = ['schema' => 1, 'state' => 'complete', 'issue' => 'ORB-91', 'attempt_id' => $proof['attempt_id'],
        'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha, 'merge_sha' => $identity['merged_main'],
        'main_sha' => $identity['merged_main'], 'generation_id' => 'installed-generation-g', 'error' => null, 'recorded_at' => '2026-09-14T01:00:00Z'];
    $ledger->record($landing, 'proof-closeout:1', ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
        'artifact_sha' => $landing->artifact_sha, 'merge_sha' => $identity['merged_main'], 'main_sha' => $identity['merged_main']], $test->flowCloseout);
    snapshotFlowPreparationFile($identity['repository'].'/.e2e/proof-closeout/ORB-91/'.$proof['attempt_id'].'.json', Data::json($test->flowCloseout));
    $test->flowGeneration = ['id' => 'installed-generation-g', 'main_sha' => $identity['merged_main']];
    snapshotFlowPreparationFile($identity['repository'].'/.e2e/topology-snapshot/promoted.json', Data::json($test->flowGeneration));
    $test->flowState = ['discovery_attempt' => null, 'discovery' => null, 'proof_attempt' => null, 'proof' => null,
        'proof_result' => null, 'candidate_attempt' => null, 'capture' => null, 'review_record' => null];
    $test->flowMutations = [];
    $test->flowArtifact = null;
    $test->flowLose = null;
    $test->flowObservation = '{"samples":["native-dev","native-second","deployed-clone"],"verified":true}';
    Http::preventStrayRequests();
    Process::fake(fn ($process) => snapshotFlowProcess($process))->preventStrayProcesses();
}

/** All commands except disposable local Git/bootstrap are faked; no Incus/Herdr/remote command escapes. */
function snapshotFlowLocal(string $path, array $command, array $environment = []): LocalProcess
{
    expect(str_starts_with($path, test()->snapshotFlowPreparationDirectory.'/'))->toBeTrue();
    $process = new LocalProcess($command, $path, [...TaskProcessEnvironment::isolated(), ...$environment,
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'], timeout: 30);
    $process->run();

    return $process;
}

function snapshotFlowProcess($process)
{
    $test = test();
    $identity = $test->snapshotFlowPreparationIdentity;
    $command = $process->command;
    $state = $test->flowState;
    if ($command[0] === 'git') {
        if (in_array('ls-remote', $command, true)) {
            return Process::result(output: $test->flowArtifact === null ? '' : $test->flowArtifact."\trefs/tags/loop/orb-91/".$identity['merged_main']);
        }
        if (in_array('worktree', $command, true) && (in_array('add', $command, true) || in_array('remove', $command, true))) {
            $test->flowMutations = [...$test->flowMutations, in_array('add', $command, true) ? 'prepare' : 'remove-worktree'];
        }
        if (in_array('update-ref', $command, true) && in_array('-d', $command, true)) {
            $test->flowMutations = [...$test->flowMutations, 'remove-branch'];
        }

        return new ProcessResult(snapshotFlowLocal($process->path, $command, $process->environment));
    }
    if (str_ends_with($command[0], '/bin/bootstrap')) {
        $test->flowMutations = [...$test->flowMutations, 'bootstrap'];
        $local = snapshotFlowLocal($process->path, $command, $process->environment);

        return Process::describe()->output($local->getOutput())->errorOutput($local->getErrorOutput())->exitCode($local->getExitCode());
    }
    if ($command === [$identity['repository'].'/bin/e2e-topology', 'status', 'ORB-91', '--worktree='.$identity['accepted_worktree'], '--json']) {
        return Process::result(output: Data::json(['state' => 'captured', 'issue' => 'ORB-91', 'worktree' => $identity['accepted_worktree'],
            'capture' => ['issue' => 'ORB-91', 'candidate_sha' => $identity['accepted_candidate'],
                'attempt_id' => str_repeat('e', 32), 'fingerprint' => str_repeat('9', 64)], 'closeout' => $test->flowCloseout]));
    }
    $prepared = TaskCloseoutOperation::query()->where('operation', 'snapshot-prepare')->firstOrFail()->input['request'];
    $nativeRequest = app(TaskSnapshotReacquisitionEvidence::class)->nativeRequest($prepared, $test->flowGeneration);
    if ($command[0] === PHP_BINARY) {
        expect($command)->toBe([PHP_BINARY, '-r', SnapshotReacquisitionBridge::script()]);
        $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
        expect($input['request'])->toBe($nativeRequest);
        $operation = $input['operation'];
        if ($operation === 'inspect') {
            return Process::result(output: Data::json(['plan_sha256' => str_repeat('d', 64), 'status' => $test->flowState,
                'capabilities' => ['acquire', 'prove', 'capture', 'review', 'releaseCapturedProof', 'releaseExact']]));
        }
        expect($operation)->toBeIn(['release-proof', 'release-discovery']);
        $purpose = $operation === 'release-proof' ? 'proof' : 'discovery';
        $attempt = $state[$purpose.'_attempt']['attempt_id'];
        expect($input['evidence'][$purpose.'_attempt'])->toBe($attempt);
        $state[$purpose] = null;
        $state[$purpose.'_attempt'] = null;
        $receipt = ['state' => 'released', 'issue' => 'ORB-91', 'purpose' => $purpose, 'attempt_id' => $attempt,
            'released' => ['exact-vm'], 'already_absent' => [], 'networks_reaped' => ['exact-network']];
        $result = $purpose === 'proof' ? $receipt : ['state' => 'released', 'issue' => 'ORB-91', 'attempts' => [$receipt]];
    } elseif (str_ends_with($command[0], '/bin/loop-artifacts')) {
        $operation = 'publish';
        $worktree = $prepared['validation_worktree'];
        $environment = ['GIT_INDEX_FILE' => $test->snapshotFlowPreparationDirectory.'/m-artifact-index'];
        foreach ([['read-tree', $identity['merged_main']], ['add', '-f', '.loop']] as $arguments) {
            expect(snapshotFlowLocal($worktree, ['git', ...$arguments], $environment)->getExitCode())->toBe(0);
        }
        $tree = trim(snapshotFlowLocal($worktree, ['git', 'write-tree'], $environment)->getOutput());
        $artifact = trim(snapshotFlowLocal($worktree, ['git', 'commit-tree', $tree, '-p', $identity['merged_main'], '-m', 'M artifact'])->getOutput());
        $ref = 'refs/tags/loop/orb-91/'.$identity['merged_main'];
        expect(snapshotFlowLocal($worktree, ['git', 'update-ref', $ref, $artifact])->getExitCode())->toBe(0);
        $test->flowArtifact = $artifact;
        $result = ['candidate' => $identity['merged_main'], 'ref' => $ref, 'artifacts' => $artifact];
    } else {
        expect($command[0])->toBe($prepared['validation_worktree'].'/bin/e2e-topology');
        $operation = $command[1];
        if (in_array($operation, ['acquire', 'prove'], true)) {
            $purpose = $operation === 'acquire' ? 'discovery' : 'proof';
            $attempt = ['issue' => 'ORB-91', 'purpose' => $purpose, 'attempt_id' => str_repeat($purpose === 'proof' ? 'b' : 'a', 32)];
            $state[$purpose.'_attempt'] = $attempt;
            $state[$purpose] = [...$attempt, 'generation' => $test->flowGeneration,
                'source' => ['host_sha' => $identity['merged_main'], 'guest_sha' => $identity['merged_main'], 'dirty' => false],
                'construction' => ['source_generation' => $test->flowGeneration['id'], 'snapshot_replacement' => false, 'extension' => null],
                'verification' => ['passed' => true]];
            $result = $purpose === 'discovery' ? ['state' => 'discovery', 'issue' => 'ORB-91', 'attempt_id' => $attempt['attempt_id'],
                'worktree' => $prepared['validation_worktree'], 'topology' => $state['discovery']]
                : ['status' => 'proved', 'issue' => 'ORB-91', 'attempt_id' => $attempt['attempt_id'],
                    'candidate_sha' => $identity['merged_main'], 'plan_sha256' => str_repeat('d', 64), 'manifest_sha256' => str_repeat('c', 64),
                    'actions' => [['id' => 'snapshot-candidate-reacquire', 'node' => 'gateway', 'exit_code' => 0]]];
            if ($purpose === 'proof') {
                $state['proof_result'] = $result;
            }
        } elseif ($operation === 'capture') {
            $result = ['issue' => 'ORB-91', 'attempt_id' => str_repeat('b', 32), 'candidate_sha' => $identity['merged_main'],
                'plan_sha256' => str_repeat('d', 64), 'fingerprint' => str_repeat('f', 64), 'topology' => $state['proof']];
            $state['capture'] = $result;
        } elseif ($operation === 'exec') {
            $operation = in_array('--proof', $command, true) ? 'observe-proof' : 'observe-discovery';
            $result = ['state' => 'executed', 'exit_code' => 0, 'stdout' => $test->flowObservation, 'stderr' => ''];
            if ($operation === 'observe-proof') {
                $state['review_record'] = ['issue' => 'ORB-91', 'candidate_sha' => $identity['merged_main'],
                    'attempt_id' => str_repeat('b', 32), 'actions' => [['id' => 'snapshot-candidate-reacquire', 'type' => 'exec', 'node' => 'gateway',
                        'required' => true, 'status' => 'passed', 'exit_code' => 0, 'argv' => $prepared['proof_action']['argv'],
                        'stdout' => $test->flowObservation, 'stderr' => '']]];
            }
        } else {
            throw new LogicException('Unexpected native command in isolated flow fixture.');
        }
    }
    $test->flowState = $state;
    $test->flowMutations = [...$test->flowMutations, $operation];

    return Process::result(output: Data::json($result), exitCode: $test->flowLose === $operation ? 1 : 0);
}

function snapshotFlowStage(string $stage, bool $apply = true): array
{
    return app(CloseTaskLanding::class)->handle(test()->landing->id, test()->landing->package_hash, $stage, true, $apply);
}

function snapshotFlowThrough(string $last = 'snapshot-remove-branch'): void
{
    foreach (TaskSnapshotReacquisitionEvidence::STAGES as $stage) {
        snapshotFlowStage($stage);
        if ($stage === $last) {
            break;
        }
    }
}

it('runs the caller-owned installed generation path with independent fast review and exact cleanup', function () {
    snapshotFlowThrough();
    $result = app(ReacquireTaskSnapshot::class)->completionEvidence($this->landing);
    $request = $result['reacquisition']['request'];
    expect($result['native_proof_closeout'])->toBe($this->flowCloseout)
        ->and($result['reacquisition']['records']['snapshot-observe-discovery']['result']['stdout'])->toBe($this->flowObservation)
        ->and($result['reacquisition']['records']['snapshot-observe-proof']['result']['stdout'])->toBe($this->flowObservation)
        ->and($this->flowReviewer->prompts)->toBe(1)->and(file_exists($request['validation_worktree']))->toBeFalse()
        ->and(snapshotFlowPreparationGit($request['accepted_worktree'], ['rev-parse', 'HEAD']))->toBe($request['accepted_candidate']);
    $effects = $this->flowMutations;
    expect(snapshotFlowStage('release')['release']['released'])->toBeTrue();
    expect(snapshotFlowLocal($request['repository'], ['git', 'worktree', 'remove', $request['accepted_worktree']])->getExitCode())->toBe(0);
    snapshotFlowPreparationFile($request['repository'].'/.e2e/topology-snapshot/promoted.json', Data::json(['id' => 'next-generation', 'main_sha' => sha1('later M')]));
    expect(snapshotFlowLocal($request['repository'], ['git', 'commit', '--allow-empty', '-m', 'Later main'])->getExitCode())->toBe(0);
    expect(app(ReacquireTaskSnapshot::class)->completionEvidence($this->landing))->toBe($result)
        ->and(snapshotFlowStage('snapshot-remove-branch')['applied'])->toBeFalse()
        ->and($this->flowMutations)->toBe($effects);
});

it('previews without local or native writes and refuses skipping prerequisites', function () {
    $before = TaskCloseoutOperation::query()->count();
    expect(snapshotFlowStage('snapshot-prepare', false)['applied'])->toBeFalse()
        ->and(TaskCloseoutOperation::query()->count())->toBe($before)->and($this->flowMutations)->toBe([]);
    expect(fn () => snapshotFlowStage('snapshot-prove'))->toThrow(LogicException::class, 'Missing');
    expect(fn () => snapshotFlowStage('release'))->toThrow(LogicException::class, 'Missing');
});

it('blocks cleanup and release when independent review requests changes', function (string $verdict) {
    $this->flowReviewer->verdict = $verdict;
    snapshotFlowThrough('snapshot-review');
    $effects = $this->flowMutations;
    expect(fn () => snapshotFlowStage('snapshot-release-proof'))->toThrow(LogicException::class, 'Independent')
        ->and($this->flowState['proof_attempt'])->not->toBeNull();
    expect(fn () => snapshotFlowStage('release'))->toThrow(LogicException::class);
    snapshotFlowStage('snapshot-review');
    expect($this->flowReviewer->prompts)->toBe(1)->and($this->flowMutations)->toBe($effects);
})->with(['revise', 'blocked']);

it('never repeats an uncertain native mutation or reconstructs concrete observations', function (string $operation, string $stage, string $prior) {
    snapshotFlowThrough($prior);
    $this->flowLose = $operation;
    expect(fn () => snapshotFlowStage($stage))->toThrow(LogicException::class, 'uncertain');
    $effects = $this->flowMutations;
    $this->flowLose = null;
    expect(fn () => snapshotFlowStage($stage))->toThrow(LogicException::class, 'uncertain')
        ->and($this->flowMutations)->toBe($effects)
        ->and(TaskCloseoutOperation::query()->where('operation', $stage)->sole()->state)->toBe('unknown');
})->with([
    ['acquire', 'snapshot-discover', 'snapshot-bootstrap'],
    ['observe-discovery', 'snapshot-observe-discovery', 'snapshot-discover'],
    ['publish', 'snapshot-publish', 'snapshot-observe-discovery'],
    ['prove', 'snapshot-prove', 'snapshot-publish'],
    ['capture', 'snapshot-capture', 'snapshot-prove'],
    ['observe-proof', 'snapshot-observe-proof', 'snapshot-capture'],
    ['release-proof', 'snapshot-release-proof', 'snapshot-review'],
    ['release-discovery', 'snapshot-release-discovery', 'snapshot-release-proof'],
]);

it('reconciles exact durable local effects after lost responses without another mutation', function (string $stage) {
    snapshotFlowThrough($stage);
    $operation = TaskCloseoutOperation::query()->where('operation', $stage)->sole();
    $result = $operation->result;
    // Simulate a crash after the native effect/read-back but before durable ledger result storage.
    DB::table('task_closeout_operations')->where('id', $operation->id)->update(['state' => 'unknown', 'result' => null]);
    $effects = $this->flowMutations;
    expect(snapshotFlowStage($stage)['result'])->toBe($result)->and($this->flowMutations)->toBe($effects);
})->with(['snapshot-prepare', 'snapshot-bootstrap', 'snapshot-remove-worktree', 'snapshot-remove-branch']);

it('rejects stale retained evidence even when the resources have already gone', function (string $fault) {
    snapshotFlowThrough();
    $operation = TaskCloseoutOperation::query()->where('operation', 'snapshot-observe-discovery')->sole();
    if ($fault === 'missing stdout') {
        $result = $operation->result;
        unset($result['result']['stdout']);
        DB::table('task_closeout_operations')->where('id', $operation->id)->update(['result' => Data::json($result)]);
    } elseif ($fault === 'changed intent') {
        DB::table('task_closeout_operations')->where('id', $operation->id)->update(['input_hash' => str_repeat('0', 64)]);
    } elseif ($fault === 'missing cleanup') {
        DB::table('task_closeout_operations')->where('operation', 'snapshot-remove-branch')->delete();
    } else {
        $review = TaskCloseoutOperation::query()->where('operation', 'like', 'snapshot-review:%')->sole();
        File::put($review->preflight['bundle_path'], '{}');
    }
    $effects = $this->flowMutations;
    expect(fn () => app(ReacquireTaskSnapshot::class)->completionEvidence($this->landing))->toThrow(Exception::class)
        ->and($this->flowMutations)->toBe($effects);
})->with(['missing stdout', 'changed intent', 'missing cleanup', 'changed review bundle']);

it('does not adopt a preexisting prepared worktree without its own intended operation', function () {
    $helper = app(PrepareTaskSnapshotReacquisition::class);
    $helper->executeOnce($helper->freeze($this->snapshotFlowPreparationIdentity));
    $effects = $this->flowMutations;
    expect(fn () => snapshotFlowStage('snapshot-prepare'))->toThrow(LogicException::class, 'create-only')
        ->and($this->flowMutations)->toBe($effects)
        ->and(TaskCloseoutOperation::query()->where('operation', 'snapshot-prepare')->sole()->state)->toBe('prepared');
});

it('blocks further execution if installed generation or exact auxiliary attempt changes', function (string $fault) {
    snapshotFlowThrough('snapshot-discover');
    if ($fault === 'generation') {
        snapshotFlowPreparationFile($this->snapshotFlowPreparationIdentity['repository'].'/.e2e/topology-snapshot/promoted.json',
            Data::json([...$this->flowGeneration, 'id' => 'another-generation']));
    } else {
        $this->flowState['discovery_attempt']['attempt_id'] = str_repeat('c', 32);
        $this->flowState['discovery']['attempt_id'] = str_repeat('c', 32);
    }
    $effects = $this->flowMutations;
    expect(fn () => snapshotFlowStage('snapshot-observe-discovery'))->toThrow(LogicException::class)
        ->and($this->flowMutations)->toBe($effects);
})->with(['generation', 'attempt']);
