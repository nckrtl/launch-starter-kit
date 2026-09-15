<?php

use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Orbit\NativeTaskSnapshotReacquisition;
use App\Tasks\Orbit\SnapshotReacquisitionBridge;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as LocalProcess;

/** Only disposable local Git is real; no native, remote or resource operation reaches a real service. */
function reacquisitionGit(string $directory, array $arguments, array $environment = [], ?string $input = null): LocalProcess
{
    expect(str_starts_with($directory, test()->reacquisitionDirectory.'/'))->toBeTrue();
    $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments], $directory,
        [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_AUTHOR_NAME' => 'Reacquisition Test', 'GIT_AUTHOR_EMAIL' => 'test@example.test',
            'GIT_COMMITTER_NAME' => 'Reacquisition Test', 'GIT_COMMITTER_EMAIL' => 'test@example.test', ...$environment], $input, 10);
    $process->mustRun();

    return $process;
}

function reacquisitionStart(string $purpose): void
{
    $request = test()->reacquisitionRequest;
    $lease = ['issue' => $request['issue'], 'purpose' => $purpose, 'attempt_id' => str_repeat($purpose === 'proof' ? 'b' : 'a', 32)];
    $state = test()->reacquisitionState;
    $state[$purpose.'_attempt'] = $lease;
    $state[$purpose] = [...$lease, 'generation' => $request['generation'],
        'construction' => ['source_generation' => $request['generation']['id'], 'snapshot_replacement' => false, 'extension' => null],
        'source' => ['host_sha' => $request['merged_main'], 'guest_sha' => $request['merged_main'], 'dirty' => false],
        'verification' => ['passed' => true]];
    test()->reacquisitionState = $state;
}

function reacquisitionPublishFixture(): array
{
    $test = test();
    $request = $test->reacquisitionRequest;
    $worktree = $request['validation_worktree'];
    $environment = ['GIT_INDEX_FILE' => $test->reacquisitionDirectory.'/artifact-index'];
    reacquisitionGit($worktree, ['read-tree', $request['merged_main']], $environment);
    reacquisitionGit($worktree, ['add', '-f', '--', '.loop'], $environment);
    $tree = trim(reacquisitionGit($worktree, ['write-tree'], $environment)->getOutput());
    $artifact = trim(reacquisitionGit($worktree, ['commit-tree', $tree, '-p', $request['merged_main']], input: "Frozen postinstall inputs\n")->getOutput());
    reacquisitionGit($worktree, ['update-ref', $test->reacquisitionRef, $artifact]);
    $test->reacquisitionPublished = $artifact;

    return ['candidate' => $request['merged_main'], 'ref' => $test->reacquisitionRef, 'artifacts' => $artifact];
}

beforeEach(function () {
    $this->reacquisitionDirectory = storage_path('framework/testing/reacquisition-'.bin2hex(random_bytes(8)));
    $repository = $this->reacquisitionDirectory.'/primary';
    $accepted = $this->reacquisitionDirectory.'/accepted';
    $validation = $this->reacquisitionDirectory.'/validation';
    foreach (['bin', 'apps/e2e/vendor', 'apps/e2e/bootstrap'] as $path) {
        File::makeDirectory($repository.'/'.$path, 0700, true);
    }
    File::put($repository.'/.gitignore', ".loop/\n.e2e/\n");
    File::put($repository.'/product', "Original accepted candidate\n");
    foreach (['bin/e2e-topology', 'bin/loop-artifacts'] as $path) {
        File::put($repository.'/'.$path, "#!/bin/sh\nexit 99\n");
        chmod($repository.'/'.$path, 0755);
    }
    foreach (['apps/e2e/vendor/autoload.php', 'apps/e2e/bootstrap/app.php'] as $path) {
        File::put($repository.'/'.$path, "<?php // Fixture only; native processes must be faked.\n");
    }
    reacquisitionGit($repository, ['init', '--initial-branch=main']);
    reacquisitionGit($repository, ['add', '--all']);
    reacquisitionGit($repository, ['commit', '--message=Accepted']);
    $candidate = trim(reacquisitionGit($repository, ['rev-parse', 'HEAD'])->getOutput());
    reacquisitionGit($repository, ['worktree', 'add', '-b', 'orb-91', $accepted]);
    File::put($repository.'/product', "Verified merged source\n");
    reacquisitionGit($repository, ['commit', '-am', 'Merged']);
    $merged = trim(reacquisitionGit($repository, ['rev-parse', 'HEAD'])->getOutput());
    reacquisitionGit($repository, ['worktree', 'add', '-b', 'orb-91-snapshot-reacquire', $validation, $merged]);
    $action = ['id' => 'snapshot-candidate-reacquire', 'node' => 'app-dev',
        'argv' => ['php', '/var/lib/orbit-e2e/proof/reacquire.php'], 'timeout_seconds' => 900];
    $this->reacquisitionPlan = ['setup' => [], 'acceptance' => [$action], 'snapshot_replacement' => false];
    File::makeDirectory($validation.'/.loop/proof', 0700, true);
    $files = [];
    foreach (['.loop/flow.json' => Data::json(['schema' => 1, 'flow' => 'proof']),
        '.loop/proof/ORB-91.json' => Data::json($this->reacquisitionPlan),
        '.loop/proof/reacquire.php' => '<?php echo "fixture observations";'] as $path => $contents) {
        File::put($validation.'/'.$path, $contents);
        chmod($validation.'/'.$path, 0644);
        $files[$path] = ['sha256' => hash('sha256', $contents), 'mode' => '644'];
    }
    $this->reacquisitionRequest = ['issue' => 'ORB-91', 'repository' => $repository, 'accepted_worktree' => $accepted,
        'accepted_candidate' => $candidate, 'validation_worktree' => $validation, 'merged_main' => $merged,
        'generation' => ['id' => 'installed-generation-g', 'main_sha' => $merged], 'files' => $files,
        'discovery_action' => [...$action, 'argv' => ['php', '/home/orbit/orbit/.loop/proof/reacquire.php']], 'proof_action' => $action];
    $this->reacquisitionRef = 'refs/tags/loop/orb-91/'.$merged;
    $this->reacquisitionPublished = null;
    $this->reacquisitionState = ['discovery_attempt' => null, 'discovery' => null, 'proof_attempt' => null,
        'proof' => null, 'proof_result' => null, 'candidate_attempt' => null, 'capture' => null, 'review_record' => null];
    $this->reacquisitionMutations = [];
    $this->reacquisitionLoseResponse = false;
    $this->reacquisitionObservation = '{"samples":["native-dev","native-second","deployed-clone"],"verified":true}';
    $this->reacquisitionBridgeFailure = false;
    $this->reacquisitionResultOverride = null;
    $this->reacquisitionEvidence = ['discovery_attempt' => str_repeat('a', 32), 'proof_attempt' => str_repeat('b', 32),
        'capture_fingerprint' => str_repeat('f', 64), 'acceptance_hash' => str_repeat('e', 64)];
    Process::fake(function ($process) {
        $command = $process->command;
        expect($process->path)->toBeIn(array_values(array_intersect_key($this->reacquisitionRequest,
            array_flip(['repository', 'accepted_worktree', 'validation_worktree']))));
        if ($command[0] === 'git') {
            $arguments = array_slice($command, 2);
            if ($arguments[0] === 'ls-remote') {
                expect($arguments)->toBe(['ls-remote', '--refs', 'origin', $this->reacquisitionRef]);

                return Process::result(output: $this->reacquisitionPublished === null ? '' : $this->reacquisitionPublished."\t".$this->reacquisitionRef."\n");
            }
            expect($arguments[0])->toBeIn(['rev-parse', 'status', 'rev-list', 'ls-tree', 'show']);

            return new ProcessResult(reacquisitionGit($process->path, $arguments, $process->environment));
        }
        if ($command[0] === PHP_BINARY) {
            expect($command)->toBe([PHP_BINARY, '-r', SnapshotReacquisitionBridge::script()]);
            $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            expect($input['request'])->toBe($this->reacquisitionRequest);
            if ($input['operation'] === 'inspect') {
                return Process::result(output: Data::json(['plan_sha256' => str_repeat('d', 64), 'status' => $this->reacquisitionState,
                    'capabilities' => ['acquire', 'prove', 'capture', 'review', 'releaseCapturedProof', 'releaseExact']]), exitCode: $this->reacquisitionBridgeFailure ? 1 : 0);
            }
            $operation = $input['operation'];
            expect($operation)->toBeIn(['release-proof', 'release-discovery']);
            $purpose = $operation === 'release-proof' ? 'proof' : 'discovery';
            expect($input['evidence'][$purpose.'_attempt'])->toBe($this->reacquisitionEvidence[$purpose.'_attempt']);
            $this->reacquisitionState[$purpose] = null;
            $this->reacquisitionState[$purpose.'_attempt'] = null;
            $receipt = ['state' => 'released', 'issue' => 'ORB-91', 'purpose' => $purpose,
                'attempt_id' => $this->reacquisitionEvidence[$purpose.'_attempt'], 'released' => ['exact-owned-vm'],
                'already_absent' => [], 'networks_reaped' => ['exact-owned-network']];
            $result = $purpose === 'proof' ? $receipt : ['state' => 'released', 'issue' => 'ORB-91', 'attempts' => [$receipt]];
        } elseif (str_ends_with($command[0], '/bin/loop-artifacts')) {
            expect($command)->toBe([$this->reacquisitionRequest['validation_worktree'].'/bin/loop-artifacts', 'publish', 'ORB-91']);
            $operation = 'publish';
            $result = reacquisitionPublishFixture();
        } else {
            expect($command[0])->toBe($this->reacquisitionRequest['validation_worktree'].'/bin/e2e-topology');
            $operation = $command[1];
            if ($operation === 'acquire') {
                reacquisitionStart('discovery');
                $result = ['state' => 'discovery', 'issue' => 'ORB-91', 'attempt_id' => str_repeat('a', 32),
                    'worktree' => $this->reacquisitionRequest['validation_worktree'], 'topology' => $this->reacquisitionState['discovery']];
            } elseif ($operation === 'prove') {
                reacquisitionStart('proof');
                $result = ['status' => 'proved', 'issue' => 'ORB-91', 'attempt_id' => str_repeat('b', 32),
                    'candidate_sha' => $this->reacquisitionRequest['merged_main'], 'plan_sha256' => str_repeat('d', 64),
                    'manifest_sha256' => str_repeat('c', 64), 'actions' => [['id' => 'snapshot-candidate-reacquire', 'node' => 'app-dev', 'exit_code' => 0]]];
                $this->reacquisitionState['proof_result'] = $result;
            } elseif ($operation === 'capture') {
                $result = ['issue' => 'ORB-91', 'attempt_id' => str_repeat('b', 32), 'candidate_sha' => $this->reacquisitionRequest['merged_main'],
                    'plan_sha256' => str_repeat('d', 64), 'fingerprint' => str_repeat('f', 64), 'topology' => $this->reacquisitionState['proof']];
                $this->reacquisitionState['capture'] = $result;
            } elseif ($operation === 'exec') {
                $operation = in_array('--proof', $command, true) ? 'observe-proof' : 'observe-discovery';
                $result = ['state' => 'executed', 'exit_code' => 0, 'stdout' => $this->reacquisitionObservation, 'stderr' => ''];
                if ($operation === 'observe-proof') {
                    $this->reacquisitionState['review_record'] = ['issue' => 'ORB-91', 'candidate_sha' => $this->reacquisitionRequest['merged_main'],
                        'attempt_id' => str_repeat('b', 32), 'actions' => [['id' => 'snapshot-candidate-reacquire', 'type' => 'exec', 'node' => 'app-dev',
                            'required' => true, 'status' => 'passed', 'exit_code' => 0, 'argv' => $this->reacquisitionRequest['proof_action']['argv'],
                            'stdout' => $this->reacquisitionObservation, 'stderr' => '']]];
                }
            } else {
                throw new RuntimeException('Unexpected native command.');
            }
        }
        $this->reacquisitionMutations[] = $operation;

        return Process::result(output: Data::json($this->reacquisitionResultOverride ?? $result), exitCode: $this->reacquisitionLoseResponse ? 1 : 0);
    })->preventStrayProcesses();
    $this->reacquisitionAdapter = app(NativeTaskSnapshotReacquisition::class);
});

afterEach(fn () => File::deleteDirectory($this->reacquisitionDirectory));

it('inspects without writes using only the isolated merged native bootstrap and scoped environment', function () {
    $result = $this->reacquisitionAdapter->inspect($this->reacquisitionRequest);
    expect($result['request_hash'])->toBe(Data::hash($this->reacquisitionRequest))->and($result['artifact'])->toBeNull()
        ->and($this->reacquisitionMutations)->toBe([]);
    Process::assertRan(fn ($process) => $process->command[0] === PHP_BINARY && $process->path === $this->reacquisitionRequest['validation_worktree']
        && $process->environment['GIT_NO_LAZY_FETCH'] === '1' && $process->environment['DB_DATABASE'] === false);
});

it('composes the one-shot native path while preserving C and immutable M inputs', function () {
    foreach (['discover', 'observe-discovery', 'publish', 'prove', 'capture', 'observe-proof'] as $operation) {
        $result = $this->reacquisitionAdapter->executeOnce($operation, $this->reacquisitionRequest, $this->reacquisitionEvidence);
        expect($result['operation'])->toBe($operation);
        if (str_starts_with($operation, 'observe-')) {
            expect($result['result']['stdout'])->toBe($this->reacquisitionObservation);
        }
    }
    $artifact = $this->reacquisitionPublished;
    $capture = $this->reacquisitionState['capture'];
    foreach (['release-proof', 'release-discovery'] as $operation) {
        $this->reacquisitionAdapter->executeOnce($operation, $this->reacquisitionRequest, $this->reacquisitionEvidence);
    }
    expect($this->reacquisitionState['proof_attempt'])->toBeNull()->and($this->reacquisitionState['discovery_attempt'])->toBeNull()
        ->and($this->reacquisitionState['capture'])->toBe($capture)->and($this->reacquisitionPublished)->toBe($artifact)
        ->and(trim(reacquisitionGit($this->reacquisitionRequest['accepted_worktree'], ['rev-parse', 'HEAD'])->getOutput()))->toBe($this->reacquisitionRequest['accepted_candidate']);
    Process::assertRan(fn ($process) => $process->command === [$this->reacquisitionRequest['validation_worktree'].'/bin/e2e-topology',
        'exec', 'ORB-91', 'app-dev', '--worktree='.$this->reacquisitionRequest['validation_worktree'],
        '--argv='.json_encode($this->reacquisitionRequest['proof_action']['argv'], JSON_UNESCAPED_SLASHES),
        '--proof', '--review-action=snapshot-candidate-reacquire', '--required', '--json']);
    expect(implode(' ', $this->reacquisitionMutations))->not->toContain('abandon', 'replace', 'closeout');
});

it('rejects unsafe or unfrozen requests before native writes', function (string $fault) {
    $request = $this->reacquisitionRequest;
    match ($fault) {
        'original-reuse' => $request['validation_worktree'] = $request['accepted_worktree'],
        'wrong-main' => $request['merged_main'] = $request['accepted_candidate'],
        'wrong-generation' => $request['generation']['main_sha'] = $request['accepted_candidate'],
        'wrong-issue' => $request['issue'] = '../ORB-91',
        'unknown-key' => $request['abandon'] = true,
        'unbounded-action' => $request['proof_action']['timeout_seconds'] = 901,
        'different-action' => $request['proof_action']['argv'] = ['true'],
        'changed-fixture' => File::put($request['validation_worktree'].'/.loop/proof/reacquire.php', '<?php exit(0);'),
        'extra-file' => File::put($request['validation_worktree'].'/.loop/unapproved.json', '{}'),
        'symlink-fixture' => symlink($request['validation_worktree'].'/.loop/flow.json', $request['validation_worktree'].'/.loop/link.json'),
        'dirty-original' => File::put($request['accepted_worktree'].'/product', 'Unexpected change'),
        'dirty-validation' => File::put($request['validation_worktree'].'/product', 'Unexpected change'),
    };
    expect(fn () => $this->reacquisitionAdapter->executeOnce('discover', $request))->toThrow(Exception::class);
    expect($this->reacquisitionMutations)->toBe([]);
})->with(['original-reuse', 'wrong-main', 'wrong-generation', 'wrong-issue', 'unknown-key', 'unbounded-action',
    'different-action', 'changed-fixture', 'extra-file', 'symlink-fixture', 'dirty-original', 'dirty-validation']);

it('rejects a replacement plan even if its changed bytes were pinned', function () {
    $path = '.loop/proof/ORB-91.json';
    File::put($this->reacquisitionRequest['validation_worktree'].'/'.$path, Data::json([...$this->reacquisitionPlan, 'snapshot_replacement' => true]));
    $this->reacquisitionRequest['files'][$path]['sha256'] = hash_file('sha256', $this->reacquisitionRequest['validation_worktree'].'/'.$path);
    expect(fn () => $this->reacquisitionAdapter->executeOnce('discover', $this->reacquisitionRequest))->toThrow(LogicException::class, 'snapshot_replacement false');
    expect($this->reacquisitionMutations)->toBe([]);
});

it('requires immutable M inputs before proof', function () {
    expect(fn () => $this->reacquisitionAdapter->executeOnce('prove', $this->reacquisitionRequest))->toThrow(LogicException::class, 'published immutable inputs');
    expect($this->reacquisitionMutations)->toBe([]);
});

it('never retries an ambiguous acquisition and exposes state for reconciliation', function () {
    $this->reacquisitionLoseResponse = true;
    expect(fn () => $this->reacquisitionAdapter->executeOnce('discover', $this->reacquisitionRequest))->toThrow(LogicException::class, 'uncertain');
    $this->reacquisitionLoseResponse = false;
    expect($this->reacquisitionAdapter->inspect($this->reacquisitionRequest)['status']['discovery_attempt']['attempt_id'])->toBe(str_repeat('a', 32));
    expect(fn () => $this->reacquisitionAdapter->executeOnce('discover', $this->reacquisitionRequest))->toThrow(LogicException::class, 'fresh attempt');
    expect($this->reacquisitionMutations)->toBe(['acquire']);
});

it('reconciles an ambiguous publication without republishing the artifact', function () {
    $this->reacquisitionLoseResponse = true;
    expect(fn () => $this->reacquisitionAdapter->executeOnce('publish', $this->reacquisitionRequest))->toThrow(LogicException::class, 'uncertain');
    $this->reacquisitionLoseResponse = false;
    expect($this->reacquisitionAdapter->inspect($this->reacquisitionRequest)['artifact']['artifacts'])->toBe($this->reacquisitionPublished);
    expect(fn () => $this->reacquisitionAdapter->executeOnce('publish', $this->reacquisitionRequest))->toThrow(LogicException::class, 'already exists');
    expect($this->reacquisitionMutations)->toBe(['publish']);
});

it('refuses mismatched attempt or construction before cleanup', function (string $fault) {
    reacquisitionStart('discovery');
    match ($fault) {
        'attempt' => $this->reacquisitionEvidence['discovery_attempt'] = str_repeat('c', 32),
        'generation' => $this->reacquisitionState['discovery']['generation']['id'] = 'old-generation',
        'source' => $this->reacquisitionState['discovery']['source']['host_sha'] = $this->reacquisitionRequest['accepted_candidate'],
        'replacement' => $this->reacquisitionState['discovery']['construction']['snapshot_replacement'] = true,
        'issue' => $this->reacquisitionState['discovery_attempt']['issue'] = 'ORB-254',
    };
    expect(fn () => $this->reacquisitionAdapter->executeOnce('release-discovery', $this->reacquisitionRequest, $this->reacquisitionEvidence))->toThrow(LogicException::class);
    expect($this->reacquisitionMutations)->toBe([]);
})->with(['attempt', 'generation', 'source', 'replacement', 'issue']);

it('requires accepted evidence before auxiliary cleanup', function (string $purpose) {
    reacquisitionStart($purpose);
    $this->reacquisitionState['capture'] = ['fingerprint' => str_repeat('f', 64), 'candidate_sha' => $this->reacquisitionRequest['merged_main'], 'attempt_id' => str_repeat('b', 32)];
    unset($this->reacquisitionEvidence['acceptance_hash']);
    expect(fn () => $this->reacquisitionAdapter->executeOnce('release-'.$purpose, $this->reacquisitionRequest, $this->reacquisitionEvidence))->toThrow(Exception::class);
    expect($this->reacquisitionMutations)->toBe([]);
})->with(['discovery', 'proof']);

it('requires concrete output instead of only a zero exit', function () {
    reacquisitionStart('discovery');
    $this->reacquisitionObservation = '';
    expect(fn () => $this->reacquisitionAdapter->executeOnce('observe-discovery', $this->reacquisitionRequest, $this->reacquisitionEvidence))->toThrow(LogicException::class, 'concrete');
    expect($this->reacquisitionMutations)->toBe(['observe-discovery']);
});

it('refuses missing native capabilities without allocating resources', function () {
    $this->reacquisitionBridgeFailure = true;
    expect(fn () => $this->reacquisitionAdapter->executeOnce('discover', $this->reacquisitionRequest))->toThrow(LogicException::class, 'uncertain');
    expect($this->reacquisitionMutations)->toBe([]);
});

it('does not rerun an existing proof review action', function () {
    reacquisitionStart('proof');
    $this->reacquisitionState['capture'] = ['fingerprint' => str_repeat('f', 64), 'candidate_sha' => $this->reacquisitionRequest['merged_main'], 'attempt_id' => str_repeat('b', 32)];
    $this->reacquisitionState['review_record'] = ['actions' => [['id' => 'snapshot-candidate-reacquire', 'status' => 'incomplete']]];
    expect(fn () => $this->reacquisitionAdapter->executeOnce('observe-proof', $this->reacquisitionRequest, $this->reacquisitionEvidence))->toThrow(LogicException::class, 'already exists');
    expect($this->reacquisitionMutations)->toBe([]);
});

it('rejects unsupported operations before inspecting', function () {
    expect(fn () => $this->reacquisitionAdapter->executeOnce('abandon', $this->reacquisitionRequest))->toThrow(LogicException::class, 'Unsupported');
    Process::assertNothingRan();
});

it('rejects incomplete or mismatched proof response evidence without another proof', function (string $fault) {
    reacquisitionPublishFixture();
    $this->reacquisitionResultOverride = ['status' => 'proved', 'issue' => 'ORB-91', 'attempt_id' => str_repeat('b', 32),
        'candidate_sha' => $fault === 'candidate' ? $this->reacquisitionRequest['accepted_candidate'] : $this->reacquisitionRequest['merged_main'],
        'plan_sha256' => str_repeat('d', 64), 'manifest_sha256' => str_repeat('c', 64), 'actions' => []];
    expect(fn () => $this->reacquisitionAdapter->executeOnce('prove', $this->reacquisitionRequest))->toThrow(LogicException::class);
    expect($this->reacquisitionMutations)->toBe(['prove']);
})->with(['candidate', 'missing-actions']);

it('retains capture and native state after ambiguous cleanup without automatic retry', function () {
    foreach (['publish', 'prove', 'capture'] as $operation) {
        $this->reacquisitionAdapter->executeOnce($operation, $this->reacquisitionRequest, $this->reacquisitionEvidence);
    }
    $capture = $this->reacquisitionState['capture'];
    $this->reacquisitionLoseResponse = true;
    expect(fn () => $this->reacquisitionAdapter->executeOnce('release-proof', $this->reacquisitionRequest, $this->reacquisitionEvidence))
        ->toThrow(LogicException::class, 'uncertain');
    $this->reacquisitionLoseResponse = false;
    $status = $this->reacquisitionAdapter->inspect($this->reacquisitionRequest)['status'];
    expect($status['proof_attempt'])->toBeNull()->and($status['capture'])->toBe($capture)
        ->and(array_count_values($this->reacquisitionMutations)['release-proof'])->toBe(1);
    // The caller can explicitly authorize exact reconciliation; the adapter never repeats it by itself.
    $this->reacquisitionAdapter->executeOnce('release-proof', $this->reacquisitionRequest, $this->reacquisitionEvidence);
    expect(array_count_values($this->reacquisitionMutations)['release-proof'])->toBe(2);
});

it('refuses cleanup when the immutable capture fingerprint differs', function () {
    reacquisitionStart('proof');
    $this->reacquisitionState['capture'] = ['fingerprint' => str_repeat('c', 64), 'candidate_sha' => $this->reacquisitionRequest['merged_main']];
    expect(fn () => $this->reacquisitionAdapter->executeOnce('release-proof', $this->reacquisitionRequest, $this->reacquisitionEvidence))
        ->toThrow(LogicException::class, 'capture must be pinned');
    expect($this->reacquisitionMutations)->toBe([]);
});

it('refuses an immutable remote artifact with changed inputs even when its candidate is correct', function () {
    $this->reacquisitionAdapter->executeOnce('publish', $this->reacquisitionRequest);
    $path = '.loop/proof/reacquire.php';
    File::put($this->reacquisitionRequest['validation_worktree'].'/'.$path, '<?php echo "different approved bytes";');
    $this->reacquisitionRequest['files'][$path]['sha256'] = hash_file('sha256', $this->reacquisitionRequest['validation_worktree'].'/'.$path);
    expect(fn () => $this->reacquisitionAdapter->executeOnce('prove', $this->reacquisitionRequest))
        ->toThrow(LogicException::class, 'differs from the frozen approved postinstall inputs');
    expect($this->reacquisitionMutations)->toBe(['publish']);
});

it('executes the private native bridge against disposable service fixtures', function (string $operation, bool $changedGeneration) {
    $request = $this->reacquisitionRequest;
    $worktree = $request['validation_worktree'];
    File::copy(base_path('tests/Fixtures/snapshot-reacquisition-native.php'), $worktree.'/apps/e2e/vendor/autoload.php');
    File::put($worktree.'/apps/e2e/bootstrap/app.php', '<?php return new Tests\\Fixtures\\NativeFixtureApplication;');
    File::put($worktree.'/fixture-head', $request['merged_main']);
    foreach (['apps/gateway/vendor', 'apps/cli/vendor', 'packages/php-sdk/vendor'] as $directory) {
        File::makeDirectory($worktree.'/'.$directory, 0700, true);
        File::put($worktree.'/'.$directory.'/autoload.php', '<?php // disposable fixture');
    }
    File::makeDirectory($request['repository'].'/.e2e/topology-snapshot', 0700, true);
    File::put($request['repository'].'/.e2e/topology-snapshot/promoted.json', Data::json(
        $changedGeneration ? [...$request['generation'], 'id' => 'other-generation'] : $request['generation']));
    reacquisitionStart('proof');
    $capture = ['candidate_sha' => $request['merged_main'], 'attempt_id' => str_repeat('b', 32),
        'fingerprint' => str_repeat('f', 64), 'topology' => $this->reacquisitionState['proof']];
    File::makeDirectory($worktree.'/.e2e/captured-proof', 0700, true);
    File::put($worktree.'/.e2e/proof-attempt.json', Data::json($this->reacquisitionState['proof_attempt']));
    File::put($worktree.'/.e2e/proof-topology.json', Data::json($this->reacquisitionState['proof']));
    File::put($worktree.'/.e2e/captured-proof/'.str_repeat('b', 32).'.json', Data::json($capture));
    $process = new LocalProcess([PHP_BINARY, '-r', SnapshotReacquisitionBridge::script()], $worktree,
        [...TaskProcessEnvironment::isolated(), 'GIT_NO_LAZY_FETCH' => '1'],
        Data::json(['operation' => $operation, 'request' => $request, 'evidence' => $this->reacquisitionEvidence]), 10);
    $process->run();
    if ($changedGeneration) {
        expect($process->isSuccessful())->toBeFalse()->and($process->getErrorOutput())->toContain('installed generation changed')
            ->and(is_file($worktree.'/fixture-cleanup.json'))->toBeFalse();
    } else {
        expect($process->getErrorOutput())->toBe('')->and($process->isSuccessful())->toBeTrue();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($operation === 'inspect') {
            expect($result['status']['capture'])->toBe($capture)->and(is_file($worktree.'/fixture-cleanup.json'))->toBeFalse();
        } else {
            expect($result['method'])->toBe($operation === 'release-proof' ? 'releaseCapturedProof' : 'releaseExact')
                ->and($result['attempt_id'])->toBe($this->reacquisitionEvidence[$operation === 'release-proof' ? 'proof_attempt' : 'discovery_attempt']);
        }
    }
    expect(json_decode(File::get($worktree.'/.e2e/captured-proof/'.str_repeat('b', 32).'.json'), true))->toBe($capture);
})->with(['inspect', 'release-proof', 'release-discovery'])->with([false, true]);
