<?php

use App\Herdr\RequestFailed;
use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\NativeTaskLandingRepository;
use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingReviewTransport;
use App\Tasks\Orbit\Proof\NativeOrbitTaskProof;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\FakeHerdrServer;

/** These processes are restricted to each test's disposable repository, with no real remote access. */
function packageLocalGit(string $path, array $arguments, array $environment = [], ?string $input = null, bool $allowFailure = false): LocalProcess
{
    expect(str_starts_with($path, test()->nativePackageDirectory.'/'))->toBeTrue();
    $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments], $path,
        [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_AUTHOR_NAME' => 'Package Test', 'GIT_AUTHOR_EMAIL' => 'package@example.test',
            'GIT_COMMITTER_NAME' => 'Package Test', 'GIT_COMMITTER_EMAIL' => 'package@example.test', ...$environment], $input, 10);
    $process->run();
    if (! $allowFailure && ! $process->isSuccessful()) {
        throw new RuntimeException('Disposable Git fixture failed: '.$process->getErrorOutput());
    }

    return $process;
}

function packageLocalArtifact(array $files, ?string $parent = null): string
{
    $test = test();
    $workspace = $test->nativePackageWorkspace;
    $index = $test->nativePackageDirectory.'/artifact-index-'.bin2hex(random_bytes(4));
    $environment = ['GIT_INDEX_FILE' => $index];
    $parent ??= $test->nativePackageCandidate;
    packageLocalGit($workspace->worktree, ['read-tree', $parent], $environment);
    foreach ($files as $name => $contents) {
        File::ensureDirectoryExists(dirname($workspace->worktree.'/.loop/'.$name));
        File::put($workspace->worktree.'/.loop/'.$name, $contents);
    }
    packageLocalGit($workspace->worktree, ['add', '-f', '--', '.loop', ':(exclude).loop/runtime'], $environment);
    $tree = trim(packageLocalGit($workspace->worktree, ['write-tree'], $environment)->getOutput());
    $artifact = trim(packageLocalGit($workspace->worktree, ['commit-tree', $tree, '-p', $parent], [], "Exact test artifact\n")->getOutput());
    packageLocalGit($workspace->worktree, ['update-ref', $test->nativePackageRef, $artifact]);

    return $artifact;
}

/** @return array{inputs:array<string,mixed>,check:array<string,mixed>,prove:array<string,mixed>,capture:array<string,mixed>,status:array<string,mixed>} */
function nativeProofFixture(): array
{
    $test = test();
    $workspace = $test->nativePackageWorkspace;
    $workspace->configuration = ['orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
    File::ensureDirectoryExists($workspace->worktree.'/.loop/proof');
    File::put($workspace->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'proof']));
    $plan = TaskLandingData::json(['setup' => [], 'acceptance' => [['id' => 'proof', 'node' => 'app-prod-1',
        'argv' => ['true'], 'timeout_seconds' => 30]], 'snapshot_replacement' => true]);
    File::put($workspace->worktree.'/.loop/proof/ORB-248.json', $plan);
    $inputs = ['schema' => 2, 'repository' => $test->nativePackageAdapter->inspectProofCandidate($workspace, $test->nativePackageCandidate),
        'proof_contract' => [['path' => '.loop/proof/ORB-248.json', 'mode' => '100644',
            'sha256' => hash('sha256', $plan), 'contents' => $plan]]];
    $attempt = str_repeat('1', 32);
    $manifest = str_repeat('2', 64);
    $fingerprint = str_repeat('3', 64);
    $prove = ['status' => 'proved', 'issue' => 'ORB-248', 'attempt_id' => $attempt,
        'candidate_sha' => $test->nativePackageCandidate, 'plan_sha256' => hash('sha256', $plan),
        'manifest_sha256' => $manifest, 'actions' => [['id' => 'proof', 'node' => 'app-prod-1', 'exit_code' => 0]],
        'recorded_at' => '2026-09-14T00:00:00Z'];
    $topology = ['issue' => 'ORB-248', 'attempt_id' => $attempt, 'purpose' => 'proof',
        'construction' => ['snapshot_replacement' => true],
        'source' => ['host_sha' => $test->nativePackageCandidate, 'guest_sha' => $test->nativePackageCandidate],
        'verification' => ['passed' => true]];
    $capture = ['schema' => 1, 'issue' => 'ORB-248', 'attempt_id' => $attempt,
        'candidate_sha' => $test->nativePackageCandidate, 'plan_sha256' => hash('sha256', $plan),
        'manifest_sha256' => $manifest, 'proof' => $prove, 'topology' => $topology, 'manifest' => [],
        'captured_at' => '2026-09-14T00:01:00Z', 'fingerprint' => $fingerprint];
    $status = ['state' => 'proof', 'issue' => 'ORB-248', 'worktree' => $workspace->worktree, 'proof' => $prove,
        'capture' => ['issue' => 'ORB-248', 'attempt_id' => $attempt, 'candidate_sha' => $test->nativePackageCandidate,
            'plan_sha256' => hash('sha256', $plan), 'manifest_sha256' => $manifest,
            'captured_at' => '2026-09-14T00:01:00Z', 'fingerprint' => $fingerprint],
        'retained_topology' => $topology, 'review_record' => null, 'review_evaluation' => null,
        'closeout' => null, 'snapshot_replacement' => null];

    return ['inputs' => $inputs, 'check' => ['sha' => $test->nativePackageCandidate],
        'prove' => $prove, 'capture' => $capture, 'status' => $status];
}

describe('native Tasks package adapter', function () {
    beforeEach(function () {
        $this->nativePackageDirectory = storage_path('framework/testing/native-package-'.bin2hex(random_bytes(8)));
        $repository = $this->nativePackageDirectory.'/repository';
        $worktree = $this->nativePackageDirectory.'/worktree';
        File::makeDirectory($repository.'/bin', 0700, true);
        packageLocalGit($repository, ['init', '--initial-branch=main']);
        File::put($repository.'/.gitignore', ".loop/\n.e2e/\n");
        File::put($repository.'/feature.txt', "Accepted product\n");
        File::put($repository.'/bin/loop-artifacts', "#!/bin/sh\nexit 99\n");
        File::put($repository.'/bin/e2e-topology', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
action=$1
shift
exec php "$root/apps/e2e/artisan" "topology:$action" "$@"
BASH);
        foreach (['apps/e2e/artisan', 'apps/e2e/bootstrap/app.php', 'apps/e2e/vendor/autoload.php', 'apps/e2e/app/FixtureHarness.php'] as $relative) {
            File::ensureDirectoryExists(dirname($repository.'/'.$relative));
            File::put($repository.'/'.$relative, "<?php\n");
        }
        File::put($repository.'/apps/e2e/app/FixtureHarness.php', "<?php\nclass FixtureHarness { public const SOURCE = 'candidate'; }\n");
        File::put($repository.'/apps/e2e/vendor/autoload.php', "<?php\nrequire __DIR__.'/../app/FixtureHarness.php';\n");
        File::put($repository.'/apps/e2e/bootstrap/app.php', "<?php\nreturn dirname(__DIR__, 3);\n");
        File::put($repository.'/apps/e2e/artisan', <<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$root = require __DIR__.'/bootstrap/app.php';
$action = substr($argv[1], strlen('topology:'));
$responses = json_decode(file_get_contents($root.'/.e2e/native-responses.json'), true, flags: JSON_THROW_ON_ERROR);
$response = $responses[$action];
file_put_contents($root.'/.e2e/bootstrap-trace.jsonl', json_encode([
    'action' => $action, 'artisan' => __FILE__, 'root' => $root,
    'source' => (new ReflectionClass(FixtureHarness::class))->getFileName(), 'marker' => FixtureHarness::SOURCE,
], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
if ($action === 'capture') {
    preg_match('/\Aworktree (.+)$/m', shell_exec('git worktree list --porcelain'), $match);
    $directory = $match[1].'/.e2e/proof-evidence/'.$response['issue'];
    if (! is_dir($directory)) { mkdir($directory, 0700, true); }
    file_put_contents($directory.'/'.$response['attempt_id'].'.json', json_encode($response, JSON_THROW_ON_ERROR));
}
echo json_encode($response, JSON_THROW_ON_ERROR);
PHP);
        chmod($repository.'/bin/loop-artifacts', 0755);
        chmod($repository.'/bin/e2e-topology', 0755);
        packageLocalGit($repository, ['add', '--all']);
        packageLocalGit($repository, ['commit', '--message=Accepted']);
        packageLocalGit($repository, ['remote', 'add', 'origin', 'git@github.com:nckrtl/orbit.git']);
        packageLocalGit($repository, ['worktree', 'add', '-b', 'orb-248', $worktree]);
        $this->nativePackageCandidate = trim(packageLocalGit($worktree, ['rev-parse', 'HEAD'])->getOutput());
        $this->nativePackageTree = trim(packageLocalGit($worktree, ['rev-parse', 'HEAD^{tree}'])->getOutput());
        $this->nativePackageRef = 'refs/tags/loop/orb-248/'.$this->nativePackageCandidate;
        $this->nativePackageGate = $repository.'/.git/orbit-checks/'.$this->nativePackageCandidate.'/builder/result.json';
        $checks = [];
        foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
            foreach ([['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']] as $command) {
                $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
            }
        }
        File::makeDirectory(dirname($this->nativePackageGate), 0700, true);
        File::put($this->nativePackageGate, TaskLandingData::json(['schema' => 1, 'role' => 'builder',
            'candidate' => $this->nativePackageCandidate, 'tree' => $this->nativePackageTree, 'worktree' => $worktree,
            'passed' => true, 'unchanged' => true, 'checks' => $checks]));
        $this->nativePackageWorkspace = new TaskWorkspace(['project_id' => 'orbit', 'source_key' => 'ORB-248',
            'repository' => $repository, 'worktree' => $worktree]);
        $this->nativePackagePublished = null;
        $this->nativePackagePublishes = 0;
        $this->nativePackageLoseResponse = false;
        $this->nativeProofResponses = [];
        $this->nativeProofCommands = [];
        $this->nativeProofExecuteLocal = false;
        Process::fake(function ($process) use ($repository, $worktree) {
            expect($process->path)->toBeIn([$worktree, $repository]);
            $command = $process->command;
            if ($command === ['git', '--no-replace-objects', 'ls-remote', '--refs', 'origin', $this->nativePackageRef]) {
                return Process::result(output: $this->nativePackagePublished === null ? '' : $this->nativePackagePublished."\t".$this->nativePackageRef."\n");
            }
            if ($command === [$repository.'/bin/loop-artifacts', 'publish', 'ORB-248']) {
                $this->nativePackagePublishes++;
                $this->nativePackagePublished = packageLocalArtifact([]);

                return Process::result(output: 'The response is not authoritative.', exitCode: $this->nativePackageLoseResponse ? 1 : 0);
            }
            if (($command[0] ?? null) === $worktree.'/bin/e2e-topology') {
                $action = $command[1] ?? '';
                $this->nativeProofCommands[] = $action;
                if ($this->nativeProofExecuteLocal) {
                    $result = new LocalProcess($command, $worktree, $process->environment, timeout: 10);
                    $result->run();

                    return new ProcessResult($result);
                }
                $response = array_shift($this->nativeProofResponses[$action]);
                expect($response)->toBeArray();
                if (isset($response['exception'])) {
                    throw new RuntimeException($response['exception']);
                }

                return Process::result(output: $response['output'], errorOutput: $response['error'] ?? '', exitCode: $response['exit_code'] ?? 0);
            }
            expect($command[0])->toBe('git');
            $arguments = array_slice($command, 1);
            while (in_array($arguments[0] ?? '', ['-c', '--no-replace-objects'], true)) {
                array_splice($arguments, 0, $arguments[0] === '-c' ? 2 : 1);
            }
            expect($arguments[0])->toBeIn(['rev-parse', 'branch', 'remote', 'ls-tree', 'status', 'config', 'ls-files', 'worktree', 'rev-list', 'show']);
            if ($arguments[0] === 'remote') {
                expect($arguments)->toBe(['remote', 'get-url', 'origin']);
            } elseif ($arguments[0] === 'worktree') {
                expect($arguments)->toBe(['worktree', 'list', '--porcelain', '-z']);
            } elseif ($arguments[0] === 'config') {
                expect($arguments[1])->toBeIn(['--bool', '--get-regexp']);
            }
            $result = packageLocalGit($process->path, array_slice($command, 1), $process->environment, allowFailure: true);

            return new ProcessResult($result);
        })->preventStrayProcesses();
        $this->nativePackageAdapter = app(NativeTaskLandingRepository::class);
    });

    afterEach(fn () => File::deleteDirectory($this->nativePackageDirectory));

    it('refuses partial repository previews before missing objects can be fetched', function (string $operation, string $configuration) {
        $workspace = $this->nativePackageWorkspace;
        $inputs = ['repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)];
        $missing = $this->nativePackageCandidate;
        if ($operation === 'artifact') {
            $missing = packageLocalArtifact(['flow.json' => $inputs['repository']['flow_contents'], 'commander-tasks.json' => TaskLandingData::json($inputs)]);
            $this->nativePackagePublished = $missing;
        }
        $remote = $this->nativePackageDirectory.'/promisor.git';
        packageLocalGit($workspace->repository, ['clone', '--bare', '--no-hardlinks', $workspace->repository, $remote]);
        packageLocalGit($remote, ['config', 'uploadpack.allowFilter', 'true']);
        packageLocalGit($workspace->repository, ['config', 'remote.origin.url', $remote]);
        packageLocalGit($workspace->repository, ['config', $configuration, match ($configuration) {
            'remote.origin.promisor' => 'true', 'remote.origin.partialclonefilter' => 'blob:none', default => 'origin',
        }]);
        $object = $workspace->repository.'/.git/objects/'.substr($missing, 0, 2).'/'.substr($missing, 2);
        expect(is_file($object))->toBeTrue();
        File::delete($object);
        $packs = File::files($workspace->repository.'/.git/objects/pack');
        $before = trim(packageLocalGit($workspace->repository, ['count-objects', '-v'])->getOutput());
        $preview = fn () => $operation === 'inspect'
            ? $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)
            : $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs);
        $failure = null;
        try {
            $preview();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        expect(File::files($workspace->repository.'/.git/objects/pack'))->toEqual($packs)
            ->and(trim(packageLocalGit($workspace->repository, ['count-objects', '-v'])->getOutput()))->toBe($before)
            ->and(is_file($object))->toBeFalse()->and($this->nativePackagePublishes)->toBe(0)
            ->and($failure)->toBeInstanceOf(LogicException::class)
            ->and($failure?->getMessage())->toContain('Partial or promisor');
        Process::assertRan(fn ($process): bool => $process->command === ['git', '--no-replace-objects', 'config', '--get-regexp',
            '^(extensions\\.partialclone|remote\\..*\\.(promisor|partialclonefilter))$']
            && ($process->environment['GIT_NO_LAZY_FETCH'] ?? null) === '1');
    })->with(['inspect', 'artifact'])->with(['remote.origin.promisor', 'extensions.partialclone', 'remote.origin.partialclonefilter']);

    it('publishes exact native artifact inputs while leaving candidate and index unchanged', function (bool $lost) {
        $workspace = $this->nativePackageWorkspace;
        File::makeDirectory($workspace->worktree.'/.loop');
        $flow = '{"flow":"discovery", "schema":1}';
        File::put($workspace->worktree.'/.loop/flow.json', $flow);
        $index = trim(packageLocalGit($workspace->worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'index'])->getOutput());
        $before = File::get($index);
        $inputs = ['schema' => 1, 'repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate), 'proof' => 'Retained task evidence.'];
        expect($this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))->toBeNull();
        $this->nativePackageLoseResponse = $lost;
        if ($lost) {
            expect(fn () => $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $inputs))->toThrow(LogicException::class, 'unresolved');
        } else {
            $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $inputs);
        }
        expect($this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))->toBe($this->nativePackagePublished)
            ->and($this->nativePackagePublishes)->toBe(1)->and(File::get($index))->toBe($before)
            ->and(trim(packageLocalGit($workspace->worktree, ['rev-parse', 'HEAD'])->getOutput()))->toBe($this->nativePackageCandidate)
            ->and(File::get($workspace->worktree.'/.loop/flow.json'))->toBe($flow)
            ->and(File::get($workspace->worktree.'/.loop/commander-tasks.json'))->toBe(TaskLandingData::json($inputs));
    })->with([false, true]);

    it('publishes schema-2 proof inputs once and later adopts the exact unchanged artifact', function (bool $lostResponse) {
        $workspace = $this->nativePackageWorkspace;
        $workspace->configuration = ['orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
        File::ensureDirectoryExists($workspace->worktree.'/.loop/proof');
        File::put($workspace->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'proof']));
        $plan = TaskLandingData::json(['setup' => [], 'acceptance' => [['id' => 'proof', 'node' => 'app-prod-1',
            'argv' => ['true'], 'timeout_seconds' => 30]], 'inputs' => ['.loop/proof/request.json', 'feature.txt', 'bin/e2e-topology', '.loop/proof/snapshot-reacquire.json'],
            'snapshot_replacement' => true]);
        $fixture = "{\"request\":true}\n";
        File::put($workspace->worktree.'/.loop/proof/ORB-248.json', $plan);
        File::put($workspace->worktree.'/.loop/proof/request.json', $fixture);
        $reacquire = "{\"schema\":1,\"purpose\":\"postinstall\"}\n";
        File::put($workspace->worktree.'/.loop/proof/snapshot-reacquire.json', $reacquire);
        $script = File::get($workspace->worktree.'/bin/e2e-topology');
        $inputs = ['schema' => 2, 'repository' => $this->nativePackageAdapter->inspectProofCandidate($workspace, $this->nativePackageCandidate),
            'proof_contract' => [
                ['path' => '.loop/proof/ORB-248.json', 'mode' => '100644', 'sha256' => hash('sha256', $plan), 'contents' => $plan],
                ['path' => '.loop/proof/request.json', 'mode' => '100644', 'sha256' => hash('sha256', $fixture), 'contents' => $fixture],
                ['path' => 'feature.txt', 'mode' => '100644', 'sha256' => hash('sha256', "Accepted product\n"), 'contents' => "Accepted product\n"],
                ['path' => 'bin/e2e-topology', 'mode' => '100755', 'sha256' => hash('sha256', $script), 'contents' => $script],
                ['path' => '.loop/proof/snapshot-reacquire.json', 'mode' => '100644', 'sha256' => hash('sha256', $reacquire), 'contents' => $reacquire],
            ]];
        $this->nativePackageLoseResponse = $lostResponse;
        try {
            $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $inputs);
        } catch (LogicException $exception) {
            expect($lostResponse)->toBeTrue()->and($exception->getMessage())->toContain('unresolved');
        }
        $artifact = $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs);
        $landingInputs = ['schema' => 2, 'artifact_inputs' => $inputs, 'native_proof' => ['retained' => true]];

        expect($artifact)->toBe($this->nativePackagePublished)
            ->and(packageLocalGit($workspace->worktree, ['ls-tree', $artifact, '--', 'bin/e2e-topology'])->getOutput())
            ->toStartWith('100755 blob ')
            ->and($this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $landingInputs))->toBe($artifact)
            ->and($this->nativePackagePublishes)->toBe(1)
            ->and(fn () => $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $landingInputs))
            ->toThrow(LogicException::class, 'adopt');
    })->with([false, true]);

    it('rejects proof repository inputs whose declared mode differs from the exact candidate', function (string $path, string $mode) {
        $workspace = $this->nativePackageWorkspace;
        $contents = File::get($workspace->worktree.'/'.$path);
        $inputs = ['schema' => 2, 'repository' => $this->nativePackageAdapter->inspectProofCandidate($workspace, $this->nativePackageCandidate),
            'proof_contract' => [['path' => $path, 'mode' => $mode, 'sha256' => hash('sha256', $contents), 'contents' => $contents]]];

        expect(fn () => $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))
            ->toThrow(LogicException::class, 'exact candidate blob')
            ->and($this->nativePackagePublishes)->toBe(0);
    })->with([['bin/e2e-topology', '100644'], ['feature.txt', '100755']]);

    it('preserves accepted executable artifact modes for historical schema-1 discovery exports', function () {
        $workspace = $this->nativePackageWorkspace;
        File::ensureDirectoryExists($workspace->worktree.'/.loop');
        File::put($workspace->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'discovery']));
        chmod($workspace->worktree.'/.loop/flow.json', 0755);
        $inputs = ['schema' => 1, 'repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)];
        $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $inputs);

        expect($this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))->toBe($this->nativePackagePublished)
            ->and(packageLocalGit($workspace->worktree, ['ls-tree', '-r', $this->nativePackagePublished, '--', '.loop/flow.json'])->getOutput())
            ->toStartWith('100755 blob ');
    });

    it('rejects changed, redirected, executable, or unexpected schema-2 proof inputs', function (string $defect) {
        $workspace = $this->nativePackageWorkspace;
        $workspace->configuration = ['orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
        File::ensureDirectoryExists($workspace->worktree.'/.loop/proof');
        File::put($workspace->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'proof']));
        $plan = "{\"snapshot_replacement\":true}\n";
        $path = $workspace->worktree.'/.loop/proof/ORB-248.json';
        File::put($path, $plan);
        $contract = ['path' => '.loop/proof/ORB-248.json', 'mode' => '100644', 'sha256' => hash('sha256', $plan), 'contents' => $plan];
        if ($defect === 'changed') {
            File::put($path, $plan.' ');
        } elseif ($defect === 'redirected') {
            File::move($path, $path.'.real');
            symlink($path.'.real', $path);
        } elseif ($defect === 'executable') {
            $contract['mode'] = '100755';
        } elseif ($defect === 'executable-file') {
            chmod($path, 0755);
        } else {
            File::put($workspace->worktree.'/.loop/proof/unexpected.json', '{}');
        }
        $inputs = ['schema' => 2, 'repository' => $this->nativePackageAdapter->inspectProofCandidate($workspace, $this->nativePackageCandidate),
            'proof_contract' => [$contract]];

        expect(fn () => $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))
            ->toThrow(LogicException::class)->and($this->nativePackagePublishes)->toBe(0);
    })->with(['changed', 'redirected', 'executable', 'executable-file', 'unexpected']);

    it('boots premerge prove capture and final status from candidate PHP while retaining primary archives', function () {
        $fixture = nativeProofFixture();
        $workspace = $this->nativePackageWorkspace;
        $attempt = $fixture['prove']['attempt_id'];
        $review = ['schema' => 1, 'issue' => 'ORB-248', 'candidate_sha' => $this->nativePackageCandidate,
            'attempt_id' => $attempt, 'actions' => [['id' => 'browser', 'required' => true, 'status' => 'passed']]];
        $evaluation = ['schema' => 1, 'issue' => 'ORB-248', 'candidate_sha' => $this->nativePackageCandidate,
            'attempt_id' => $attempt, 'status' => 'ready', 'required_incomplete' => [], 'required_failed' => [], 'exploratory_failed' => []];
        $status = [...$fixture['status'], 'review_record' => $review, 'review_evaluation' => $evaluation];
        File::ensureDirectoryExists($workspace->worktree.'/.e2e');
        File::put($workspace->worktree.'/.e2e/native-responses.json', TaskLandingData::json([
            'prove' => $fixture['prove'], 'capture' => $fixture['capture'], 'status' => $status,
        ]));
        File::put($workspace->repository.'/apps/e2e/app/FixtureHarness.php', "<?php\nclass FixtureHarness { public const SOURCE = 'old-primary'; }\n");
        foreach (['proof-review' => $review, 'proof-review-evaluation' => $evaluation] as $directory => $contents) {
            $path = $workspace->repository.'/.e2e/'.$directory.'/ORB-248/'.$attempt.'.json';
            File::ensureDirectoryExists(dirname($path));
            File::put($path, TaskLandingData::json($contents));
        }
        $this->nativeProofExecuteLocal = true;
        $proof = app(NativeOrbitTaskProof::class);
        $prepared = $proof->execute($workspace, $fixture['check'], $fixture['inputs']);
        $final = $proof->finalize($workspace, $prepared);
        $trace = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", trim(File::get($workspace->worktree.'/.e2e/bootstrap-trace.jsonl'))));

        expect(array_column($trace, 'action'))->toBe(['prove', 'capture', 'status', 'status'])
            ->and(array_unique(array_column($trace, 'marker')))->toBe(['candidate'])
            ->and(array_unique(array_column($trace, 'artisan')))->toBe([$workspace->worktree.'/apps/e2e/artisan'])
            ->and(array_unique(array_column($trace, 'source')))->toBe([$workspace->worktree.'/apps/e2e/app/FixtureHarness.php'])
            ->and(array_unique(array_column($trace, 'root')))->toBe([$workspace->worktree])
            ->and($final['archives']['capture']['path'])->toBe($workspace->repository.'/.e2e/proof-evidence/ORB-248/'.$attempt.'.json')
            ->and(file_exists($workspace->repository.'/.e2e/bootstrap-trace.jsonl'))->toBeFalse();
    });

    it('refuses unavailable or redirected candidate PHP bootstrap before artifact publication', function (string $relative) {
        $fixture = nativeProofFixture();
        $workspace = $this->nativePackageWorkspace;
        File::move($workspace->worktree.'/'.$relative, $workspace->worktree.'/'.$relative.'.original');
        symlink($workspace->repository.'/'.$relative, $workspace->worktree.'/'.$relative);

        expect(fn () => app(NativeOrbitTaskProof::class)->execute($workspace, $fixture['check'], $fixture['inputs']))
            ->toThrow(LogicException::class, 'candidate Orbit helper and local PHP bootstrap')
            ->and($this->nativePackagePublishes)->toBe(0)->and($this->nativeProofCommands)->toBe([]);
    })->with(['bin/e2e-topology', 'apps/e2e/artisan', 'apps/e2e/bootstrap/app.php', 'apps/e2e/vendor/autoload.php']);

    it('reconciles unknown publication, prove, and capture responses by inspection without retrying them', function (string $failure) {
        $fixture = nativeProofFixture();
        $attempt = $fixture['prove']['attempt_id'];
        $archive = $this->nativePackageWorkspace->repository.'/.e2e/proof-evidence/ORB-248/'.$attempt.'.json';
        File::ensureDirectoryExists(dirname($archive));
        File::put($archive, TaskLandingData::json($fixture['capture']));
        $this->nativePackageLoseResponse = true;
        $unknown = match ($failure) {
            'nonzero' => ['output' => '', 'exit_code' => 1],
            'malformed' => ['output' => '{'],
            'exception' => ['exception' => 'Timed out after native command submission.'],
        };
        $this->nativeProofResponses = [
            'prove' => [$unknown],
            'capture' => [$unknown],
            'status' => [
                ['output' => TaskLandingData::json(['issue' => 'ORB-248', 'worktree' => $this->nativePackageWorkspace->worktree, 'proof' => $fixture['prove']])],
                ['output' => TaskLandingData::json($fixture['status'])],
            ],
        ];

        $prepared = app(NativeOrbitTaskProof::class)->execute($this->nativePackageWorkspace, $fixture['check'], $fixture['inputs']);

        expect($prepared['artifact']['sha'])->toBe($this->nativePackagePublished)
            ->and($prepared['attempt_id'])->toBe($attempt)
            ->and($prepared['capture_fingerprint'])->toBe($fixture['capture']['fingerprint'])
            ->and($this->nativePackagePublishes)->toBe(1)
            ->and($this->nativeProofCommands)->toBe(['prove', 'status', 'capture', 'status']);
    })->with(['nonzero', 'malformed', 'exception']);

    it('freezes only the exact live reviewed native proof and primary archive bytes', function (string $defect) {
        $fixture = nativeProofFixture();
        $this->nativeProofResponses = [
            'prove' => [['output' => TaskLandingData::json($fixture['prove'])]],
            'capture' => [['output' => TaskLandingData::json($fixture['capture'])]],
            'status' => [['output' => TaskLandingData::json($fixture['status'])]],
        ];
        $proof = app(NativeOrbitTaskProof::class);
        $prepared = $proof->execute($this->nativePackageWorkspace, $fixture['check'], $fixture['inputs']);
        $attempt = $prepared['attempt_id'];
        $review = ['schema' => 1, 'issue' => 'ORB-248', 'candidate_sha' => $this->nativePackageCandidate,
            'attempt_id' => $attempt, 'actions' => [['id' => 'browser', 'required' => true, 'status' => 'passed']],
            'updated_at' => '2026-09-14T00:02:00Z', 'fingerprint' => str_repeat('4', 64)];
        $evaluation = ['schema' => 1, 'issue' => 'ORB-248', 'candidate_sha' => $this->nativePackageCandidate,
            'attempt_id' => $attempt, 'status' => 'ready', 'required_incomplete' => [], 'required_failed' => [],
            'exploratory_failed' => [], 'evaluated_at' => '2026-09-14T00:03:00Z'];
        $status = [...$fixture['status'], 'review_record' => $review, 'review_evaluation' => $evaluation];
        foreach (['proof-evidence' => $fixture['capture'], 'proof-review' => $review, 'proof-review-evaluation' => $evaluation] as $directory => $value) {
            $path = $this->nativePackageWorkspace->repository.'/.e2e/'.$directory.'/ORB-248/'.$attempt.'.json';
            File::ensureDirectoryExists(dirname($path));
            File::put($path, TaskLandingData::json($value));
        }
        match ($defect) {
            'proof-result' => $status['proof']['status'] = 'failed',
            'topology' => $status['retained_topology'] = null,
            'candidate' => $status['review_record']['candidate_sha'] = str_repeat('5', 40),
            'attempt' => $status['review_evaluation']['attempt_id'] = str_repeat('5', 32),
            'capture' => $status['capture']['fingerprint'] = str_repeat('5', 64),
            'failed-review' => $status['review_evaluation']['required_failed'] = ['browser'],
            'stale-evaluation' => $status['review_record']['actions'][0]['status'] = 'failed',
            'missing-actions' => $status['review_record']['actions'] = [],
            'closed-out' => $status['closeout'] = ['status' => 'retained'],
            'replacement' => $status['snapshot_replacement'] = ['status' => 'installed'],
            'wrong-worktree' => $status['worktree'] = '/another/worktree',
            'none' => null,
        };
        $this->nativeProofResponses['status'][] = ['output' => TaskLandingData::json($status)];
        if ($defect !== 'none') {
            expect(fn () => $proof->finalize($this->nativePackageWorkspace, $prepared))->toThrow(LogicException::class)
                ->and($this->nativePackagePublishes)->toBe(1)
                ->and($this->nativeProofCommands)->toBe(['prove', 'capture', 'status', 'status']);

            return;
        }
        $final = $proof->finalize($this->nativePackageWorkspace, $prepared);

        expect($final['review_evaluation']['status'])->toBe('ready')
            ->and($final['review_record']['actions'])->toHaveCount(1)
            ->and($final['archives']['capture']['sha256'])->toBe(hash('sha256', TaskLandingData::json($fixture['capture'])));

        File::put($final['archives']['evaluation']['path'], TaskLandingData::json([...$evaluation, 'status' => 'blocked']));
        $this->nativeProofResponses['status'][] = ['output' => TaskLandingData::json($status)];
        expect(fn () => $proof->finalize($this->nativePackageWorkspace, $prepared))
            ->toThrow(LogicException::class, 'archive differs');
    })->with(['none', 'proof-result', 'topology', 'candidate', 'attempt', 'capture', 'failed-review', 'stale-evaluation', 'missing-actions', 'closed-out', 'replacement', 'wrong-worktree']);

    it('refuses legacy planning files, unknown inputs and symlinks without deleting them', function (string $name) {
        $workspace = $this->nativePackageWorkspace;
        File::makeDirectory($workspace->worktree.'/.loop');
        if ($name === 'linked') {
            symlink($this->nativePackageGate, $workspace->worktree.'/.loop/linked');
        } elseif ($name === 'proof') {
            File::makeDirectory($workspace->worktree.'/.loop/proof');
        } else {
            File::put($workspace->worktree.'/.loop/'.$name, 'Unconsumed evidence must not be silently removed.');
        }
        $inputs = ['repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)];
        expect(fn () => $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))
            ->toThrow(LogicException::class, 'Unexpected or changed')
            ->and(file_exists($workspace->worktree.'/.loop/'.$name))->toBeTrue()->and($this->nativePackagePublishes)->toBe(0);
    })->with(['plan.md', 'issue.json', 'secret.txt', 'linked', 'proof']);

    it('rejects proof flow and dirty, unregistered, wrong-branch or wrong-repository candidates', function (string $defect) {
        $workspace = $this->nativePackageWorkspace;
        if ($defect === 'proof') {
            File::makeDirectory($workspace->worktree.'/.loop');
            File::put($workspace->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'proof']));
        } elseif ($defect === 'dirty') {
            File::put($workspace->worktree.'/feature.txt', 'Unreviewed change');
        } elseif ($defect === 'wrong-branch') {
            packageLocalGit($workspace->worktree, ['branch', '-m', 'other']);
        } elseif ($defect === 'wrong-repository') {
            packageLocalGit($workspace->worktree, ['remote', 'set-url', 'origin', 'git@github.com:other/orbit.git']);
        } else {
            $workspace->worktree = $workspace->repository;
        }
        expect(fn () => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate))
            ->toThrow(in_array($defect, ['proof', 'wrong-branch', 'wrong-repository'], true) ? LogicException::class : RuntimeException::class)
            ->and($this->nativePackagePublishes)->toBe(0);
    })->with(['proof', 'dirty', 'wrong-branch', 'wrong-repository', 'primary']);

    it('refuses an existing artifact with different export inputs and preserves its ref', function () {
        $workspace = $this->nativePackageWorkspace;
        $inputs = ['repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)];
        $artifact = packageLocalArtifact(['flow.json' => $inputs['repository']['flow_contents'], 'commander-tasks.json' => TaskLandingData::json(['wrong' => true])]);
        $this->nativePackagePublished = $artifact;
        File::put($workspace->worktree.'/.loop/commander-tasks.json', TaskLandingData::json($inputs));
        expect(fn () => $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))->toThrow(LogicException::class, 'no overwrite')
            ->and(trim(packageLocalGit($workspace->worktree, ['rev-parse', $this->nativePackageRef])->getOutput()))->toBe($artifact)
            ->and($this->nativePackagePublishes)->toBe(0);
    });

    it('allows excluded runtime state without publishing any of its private contents', function () {
        $workspace = $this->nativePackageWorkspace;
        File::makeDirectory($workspace->worktree.'/.loop/runtime', 0700, true);
        File::put($workspace->worktree.'/.loop/runtime/secret.json', 'private-runtime-token');
        symlink($workspace->worktree.'/.loop/runtime/secret.json', $workspace->worktree.'/.loop/runtime/linked-secret');
        $inputs = ['repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate)];
        expect(array_keys($inputs['repository']))->toBe(['candidate', 'tree', 'gate_path', 'gate_sha256', 'gate_contents', 'flow_contents']);
        $this->nativePackageAdapter->publish($workspace, $this->nativePackageCandidate, $inputs);
        $artifact = $this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs);
        expect(packageLocalGit($workspace->worktree, ['ls-tree', '-r', '--name-only', $artifact])->getOutput())->not->toContain('.loop/runtime')
            ->and(File::get($workspace->worktree.'/.loop/runtime/secret.json'))->toBe('private-runtime-token');
    });

    it('reads a multi-megabyte immutable blob without republishing or creating a feature commit for its reference prompt', function () {
        $workspace = $this->nativePackageWorkspace;
        $contents = str_repeat("Initial failed check; retained detail.\n", 75_000)."Final successful check.\n";
        $inputs = ['repository' => $this->nativePackageAdapter->inspect($workspace, $this->nativePackageCandidate, $this->nativePackageGate),
            'evidence_files' => [['name' => 'all-history.log', 'contents' => $contents, 'sha256' => hash('sha256', $contents)]]];
        $blob = TaskLandingData::json($inputs);
        $artifact = packageLocalArtifact(['flow.json' => $inputs['repository']['flow_contents'], 'commander-tasks.json' => $blob]);
        $this->nativePackagePublished = $artifact;
        $before = packageLocalGit($workspace->worktree, ['rev-parse', 'HEAD', $this->nativePackageRef])->getOutput();
        expect($this->nativePackageAdapter->artifact($workspace, $this->nativePackageCandidate, $inputs))->toBe($artifact);
        $package = ['body' => "Full PR body\nAll retained failed and successful checks."];
        $landing = new TaskLanding(['inputs' => $inputs, 'input_hash' => hash('sha256', $blob),
            'candidate_sha' => $this->nativePackageCandidate, 'artifact_ref' => $this->nativePackageRef, 'artifact_sha' => $artifact,
            'package' => $package, 'package_hash' => TaskLandingData::hash($package)]);
        $session = ['agentName' => 'disposable-reviewer'];
        $prompt = app(ReviewTaskLanding::class)->referencePrompt($landing, $session, 'test-assignment', 'test-secret');
        $after = packageLocalGit($workspace->worktree, ['show', $artifact.':.loop/commander-tasks.json'])->getOutput();
        expect($after)->toBe($blob)->and(hash('sha256', $after))->toBe($landing->input_hash)
            ->and($prompt)->toContain(TaskLandingData::json($package), (string) strlen($blob), $landing->input_hash, $artifact,
                'git --no-replace-objects show '.$artifact.':.loop/commander-tasks.json')
            ->and(TaskLandingReviewTransport::inspect($session, $prompt)['wire_bytes'])->toBeLessThan(65_536)
            ->and(packageLocalGit($workspace->worktree, ['rev-parse', 'HEAD', $this->nativePackageRef])->getOutput())->toBe($before)
            ->and($this->nativePackagePublishes)->toBe(0);
    });
});

describe('retained supplemental reviewer adapter', function () {
    beforeEach(function () {
        $this->packageHerdrServer = null;
        $this->packageHerdrWorkspace = new TaskWorkspace(['repository' => '/disposable/orbit', 'worktree' => '/disposable/worktree', 'configuration' => ['agent_kind' => 'codex']]);
        $this->packageHerdrIdentity = ['workspaceId' => 'w1', 'tabId' => 't1', 'paneId' => 'p1', 'terminalId' => 'term1',
            'agentName' => 'reviewer', 'agentId' => '77777777-7777-4777-8777-777777777777', 'workingDirectory' => '/disposable/worktree'];
    });

    afterEach(fn () => $this->packageHerdrServer?->stop());

    it('requires the same yielded reviewer and honors optional native conversation identity', function (string $status, ?string $native, bool $valid) {
        $identity = $this->packageHerdrIdentity;
        $identity['agentId'] = $native;
        $agent = ['workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'term1',
            'agent' => 'codex', 'name' => 'reviewer', 'cwd' => '/disposable/worktree', 'agent_status' => $status,
            'agent_session' => $native === null ? null : ['value' => $native]];
        $snapshot = ['version' => 'test', 'protocol' => 22, 'tabs' => [], 'layouts' => [],
            'workspaces' => [['workspace_id' => 'w1', 'worktree' => ['repo_root' => '/disposable/orbit', 'checkout_path' => '/disposable/worktree', 'is_linked_worktree' => true]]],
            'panes' => [$agent], 'agents' => [$agent]];
        $this->packageHerdrServer = FakeHerdrServer::start(['agents' => [], 'workspaces' => [], 'events' => [], 'rpc' => [
            'session.snapshot' => ['type' => 'session_snapshot', 'snapshot' => $snapshot],
            'agent.get' => ['type' => 'agent_info', 'agent' => $agent],
        ]]);
        $workspace = $this->packageHerdrWorkspace;
        $workspace->configuration = [...$workspace->configuration, 'socket' => $this->packageHerdrServer->socketPath];
        $observe = fn () => app(HerdrTaskLandingReviewer::class)->observe($workspace, $identity, true);
        if ($valid) {
            expect($observe())->toBe(HerdrTaskLandingReviewer::identity($identity));
        } else {
            expect($observe)->toThrow(LogicException::class, 'yield before');
        }
        expect(array_column($this->packageHerdrServer->requests(), 'method'))->toBe(['session.snapshot', 'agent.get']);
    })->with([
        ['idle', '77777777-7777-4777-8777-777777777777', true],
        ['done', '77777777-7777-4777-8777-777777777777', true],
        ['idle', null, true],
        ['working', null, false],
        ['blocked', null, false],
        ['unknown', null, false],
    ]);

    it('rejects replacement native or pane identities and preserves optional identity without inventing one', function () {
        $identity = $this->packageHerdrIdentity;
        expect(fn () => HerdrTaskLandingReviewer::assertIdentity($identity, [...$identity, 'agentId' => 'new-native-id']))->toThrow(LogicException::class)
            ->and(fn () => HerdrTaskLandingReviewer::assertIdentity($identity, [...$identity, 'paneId' => 'replacement-pane']))->toThrow(LogicException::class)
            ->and(HerdrTaskLandingReviewer::identity([...$identity, 'agentId' => null])['agentId'])->toBeNull();
    });

    it('sends the exact preflighted recovery envelope once even when the agent reports not ready', function (bool $notReady) {
        $agent = ['workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'term1',
            'name' => 'reviewer', 'cwd' => '/disposable/worktree', 'agent_status' => 'done',
            'agent_session' => ['value' => $this->packageHerdrIdentity['agentId']]];
        $this->packageHerdrServer = FakeHerdrServer::start(['agents' => [], 'workspaces' => [], 'events' => [],
            'rpc' => ['agent.get' => ['type' => 'agent_info', 'agent' => $agent]],
            'rpc_sequences' => ['agent.prompt' => [
                $notReady ? ['error' => ['code' => 'agent_not_ready', 'message' => 'Not ready.']]
                    : ['result' => ['type' => 'agent_prompted', 'agent' => $agent]],
                ['result' => ['type' => 'agent_prompted', 'agent' => $agent]],
            ]]]);
        $workspace = $this->packageHerdrWorkspace;
        $workspace->configuration = [...$workspace->configuration, 'socket' => $this->packageHerdrServer->socketPath];
        $prompt = "Reference review: quotes \"test\", backslash \\ and Unicode ✓\n";
        $send = fn () => app(HerdrTaskLandingReviewer::class)->promptOnce($workspace, $this->packageHerdrIdentity, $prompt);
        if ($notReady) {
            expect($send)->toThrow(RequestFailed::class, 'agent_not_ready');
        } else {
            expect($send())->toBe(HerdrTaskLandingReviewer::identity($this->packageHerdrIdentity));
        }
        $requests = $this->packageHerdrServer->requests();
        expect(array_column($requests, 'method'))->toBe(['agent.get', 'agent.prompt'])
            ->and(json_encode($requests[1], JSON_THROW_ON_ERROR)."\n")
            ->toBe(TaskLandingReviewTransport::line($this->packageHerdrIdentity, $prompt));
    })->with([false, true]);

    it('rejects a serialized recovery prompt over budget before opening a socket', function () {
        $workspace = $this->packageHerdrWorkspace;
        $workspace->configuration = [...$workspace->configuration, 'socket' => '/must-never-open.sock'];
        expect(fn () => app(HerdrTaskLandingReviewer::class)->promptOnce($workspace, $this->packageHerdrIdentity, str_repeat("\t", 40_000)))
            ->toThrow(LogicException::class, 'serialized supplemental review request');
    });
});
