<?php

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\CloseOrbitTaskProof;
use App\Tasks\Orbit\NativeTaskProofCloseout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    Process::preventStrayProcesses();
    $this->proofDirectory = storage_path('framework/testing/proof-closeout-'.bin2hex(random_bytes(8)));
    new TaskCloseoutFixture($this->proofDirectory);
    $root = app(CreateTask::class)->handle('orbit', 'Test proof adapter', 'Disposable adapter fixture.', TaskKind::Group);
    $path = $this->proofDirectory.'/worktrees/orb-91';
    File::makeDirectory($path, 0700, true);
    File::put($path.'/.git', 'Disposable linked-worktree marker; all processes are fake.');
    $script = $this->proofDirectory.'/repository/bin/e2e-topology';
    File::put($script, "#!/bin/sh\nexit 1\n");
    chmod($script, 0700);
    $this->proofWorkspace = TaskWorkspace::query()->create(['root_task_id' => $root->id, 'project_id' => 'orbit',
        'source_key' => 'ORB-91', 'repository' => $this->proofDirectory.'/repository', 'worktree' => $path,
        'base_sha' => str_repeat('0', 40), 'manifest_hash' => str_repeat('0', 64),
        'configuration' => ['repository' => $this->proofDirectory.'/repository', 'worktree_root' => $this->proofDirectory.'/worktrees',
            'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]],
        'final_check' => ['native_proof' => ['attempt_id' => str_repeat('f', 32), 'capture_fingerprint' => str_repeat('9', 64)]]]);
    $this->proofLanding = new TaskLanding(['task_workspace_id' => $this->proofWorkspace->id,
        'candidate_sha' => str_repeat('a', 40), 'artifact_sha' => str_repeat('b', 40), 'package' => ['schema' => 2]]);
    $this->proofMerge = ['candidate_sha' => str_repeat('a', 40), 'merge_sha' => str_repeat('c', 40)];
    $this->proofRecord = ['schema' => 1, 'state' => 'complete', 'issue' => 'ORB-91', 'attempt_id' => str_repeat('f', 32),
        'candidate_sha' => str_repeat('a', 40), 'artifact_sha' => str_repeat('b', 40), 'merge_sha' => str_repeat('c', 40),
        'main_sha' => str_repeat('d', 40), 'generation_id' => 'fixture-generation', 'error' => null, 'recorded_at' => '2026-09-14T01:00:00Z'];
    $this->proofStatus = ['state' => 'captured', 'issue' => 'ORB-91', 'worktree' => $path,
        'capture' => ['issue' => 'ORB-91', 'candidate_sha' => str_repeat('a', 40),
            'attempt_id' => str_repeat('f', 32), 'fingerprint' => str_repeat('9', 64)],
        'closeout' => $this->proofRecord, 'retained_topology' => null, 'snapshot_replacement' => null];
    $this->proofArchive = $this->proofDirectory.'/repository/.e2e/proof-closeout/ORB-91/'.str_repeat('f', 32).'.json';
    File::makeDirectory(dirname($this->proofArchive), 0700, true);
    File::put($this->proofArchive, TaskLandingData::json($this->proofRecord));
});

afterEach(fn () => File::deleteDirectory($this->proofDirectory));

it('observes exact native closeout and its archive without invoking a mutation', function () {
    Process::fake(function ($process) {
        expect($process->command)->toBe([$this->proofDirectory.'/repository/bin/e2e-topology', 'status', 'ORB-91',
            '--worktree='.$this->proofWorkspace->worktree, '--json']);

        return Process::result(output: TaskLandingData::json($this->proofStatus));
    });
    $result = app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge);
    expect($result['closeout'])->toBe($this->proofRecord)->and($result)->not->toHaveKey('reacquisition');
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('keeps absent closeout distinct from completed installation', function () {
    $this->proofStatus['closeout'] = null;
    File::delete($this->proofArchive);
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge)['closeout'])->toBeNull();
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('recovers completed archive-first progress without repeating native closeout', function (string $local) {
    if ($local === 'discovery') {
        $this->proofStatus['state'] = 'discovery';
        $this->proofStatus['attempt_id'] = str_repeat('1', 32);
        unset($this->proofStatus['capture'], $this->proofStatus['closeout'], $this->proofStatus['retained_topology']);
    } else {
        $this->proofStatus['closeout'] = match ($local) {
            'absent' => null,
            'failed' => [...$this->proofRecord, 'state' => 'replacement-failed', 'generation_id' => null, 'error' => 'Prior failure.'],
            default => [...$this->proofRecord, 'state' => 'replacement-succeeded'],
        };
    }
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge))
        ->toBe(['closeout' => $this->proofRecord, 'proof_released' => true]);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['discovery', 'absent', 'failed', 'installed']);

it('does not infer complete release from missing fields or a retained proof', function (string $case) {
    match ($case) {
        'unknown state' => $this->proofStatus['state'] = 'unknown',
        'missing state' => $this->proofStatus['state'] = null,
        'unknown missing capture' => $this->proofStatus['capture'] = null,
        'discovery missing archive' => $this->proofStatus = ['state' => 'discovery', 'issue' => 'ORB-91', 'worktree' => $this->proofWorkspace->worktree],
        'live proof state' => $this->proofStatus['state'] = 'proof',
        'live proof topology' => $this->proofStatus['proof_topology'] = ['attempt_id' => str_repeat('f', 32)],
        'retained topology' => $this->proofStatus['retained_topology'] = ['attempt_id' => str_repeat('f', 32)],
    };
    if ($case === 'discovery missing archive') {
        File::delete($this->proofArchive);
    }
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(fn () => app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge))->toThrow(LogicException::class);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['unknown state', 'missing state', 'unknown missing capture', 'discovery missing archive', 'live proof state', 'live proof topology', 'retained topology']);

it('keeps the main identity pinned across failed replacement recovery', function () {
    $this->proofStatus['closeout'] = [...$this->proofRecord, 'state' => 'replacement-failed',
        'main_sha' => str_repeat('e', 40), 'generation_id' => null, 'error' => 'Earlier failure.'];
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(fn () => app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge))->toThrow(LogicException::class);
});

it('rejects divergent or backwards archive checkpoints', function (string $case) {
    $local = [...$this->proofRecord, 'state' => 'replacement-succeeded'];
    $archive = match ($case) {
        'generation' => [...$this->proofRecord, 'generation_id' => 'another-generation'],
        'main' => [...$this->proofRecord, 'main_sha' => str_repeat('e', 40)],
        'earlier' => [...$this->proofRecord, 'recorded_at' => '2026-09-14T00:00:00Z'],
        'backwards' => [...$this->proofRecord, 'state' => 'replacement-failed', 'error' => 'Old failure.', 'generation_id' => null],
    };
    $this->proofStatus['closeout'] = $local;
    File::put($this->proofArchive, TaskLandingData::json($archive));
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(fn () => app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge))->toThrow(LogicException::class);
})->with(['generation', 'main', 'earlier', 'backwards']);

it('rejects changed native identity or archive', function (string $field) {
    match ($field) {
        'status issue' => $this->proofStatus['issue'] = 'ORB-92',
        'worktree' => $this->proofStatus['worktree'] .= '-other',
        'capture' => $this->proofStatus['capture']['fingerprint'] = str_repeat('0', 64),
        'attempt' => $this->proofStatus['closeout']['attempt_id'] = str_repeat('1', 32),
        'candidate' => $this->proofStatus['closeout']['candidate_sha'] = str_repeat('1', 40),
        'artifact' => $this->proofStatus['closeout']['artifact_sha'] = str_repeat('1', 40),
        'merge' => $this->proofStatus['closeout']['merge_sha'] = str_repeat('1', 40),
        'archive' => File::put($this->proofArchive, TaskLandingData::json([...$this->proofRecord, 'generation_id' => 'other-generation'])),
    };
    Process::fake(['*' => Process::result(output: TaskLandingData::json($this->proofStatus))]);
    expect(fn () => app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge))->toThrow(LogicException::class);
})->with(['status issue', 'worktree', 'capture', 'attempt', 'candidate', 'artifact', 'merge', 'archive']);

it('invokes native closeout once with exact identities and records a known outcome', function (bool $successful) {
    $record = $successful ? $this->proofRecord : [...$this->proofRecord, 'state' => 'replacement-failed',
        'generation_id' => null, 'error' => 'Disposable construction failure.'];
    Process::fake(function ($process) use ($record, $successful) {
        expect($process->command)->toBe([$this->proofDirectory.'/repository/bin/e2e-topology', 'closeout', 'ORB-91',
            '--worktree='.$this->proofWorkspace->worktree, '--candidate='.str_repeat('a', 40),
            '--artifact='.str_repeat('b', 40), '--merge='.str_repeat('c', 40), '--main-sha='.str_repeat('d', 40), '--json'])
            ->and($process->timeout)->toBe(3600);

        return Process::result(output: TaskLandingData::json($record), exitCode: $successful ? 0 : 1);
    });
    expect(app(NativeTaskProofCloseout::class)->execute($this->proofLanding, $this->proofMerge, str_repeat('d', 40)))->toBe($record);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with([true, false]);

it('rejects native exit or main contradictions without retrying', function (string $field) {
    $record = $field === 'main' ? [...$this->proofRecord, 'main_sha' => str_repeat('e', 40)] : $this->proofRecord;
    Process::fake(['*' => Process::result(output: TaskLandingData::json($record), exitCode: $field === 'exit' ? 1 : 0)]);
    expect(fn () => app(NativeTaskProofCloseout::class)->execute($this->proofLanding, $this->proofMerge, str_repeat('d', 40)))
        ->toThrow(LogicException::class);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['main', 'exit']);

it('refuses another merge candidate before any native process', function () {
    expect(fn () => app(NativeTaskProofCloseout::class)->execute($this->proofLanding,
        [...$this->proofMerge, 'candidate_sha' => str_repeat('e', 40)], str_repeat('d', 40)))
        ->toThrow(LogicException::class);
    Process::assertNothingRan();
});

it('retains a structured installation checkpoint without calling it complete', function () {
    $record = [...$this->proofRecord, 'state' => 'replacement-succeeded'];
    File::put($this->proofArchive, TaskLandingData::json($record));
    Process::fake(['*' => Process::result(output: TaskLandingData::json([...$this->proofStatus, 'closeout' => $record]))]);
    expect(app(NativeTaskProofCloseout::class)->inspect($this->proofLanding, $this->proofMerge)['closeout']['state'])
        ->toBe('replacement-succeeded');
});

function taskProofStageFixture(object $test): void
{
    $dispatch = $test->proofWorkspace->dispatches()->create(['step_key' => 'feature:final', 'kind' => 'final_review',
        'token_hash' => hash('sha256', 'disposable'), 'handoff_token' => 'disposable', 'prompt' => 'Disposable fixture.']);
    $test->proofLanding->fill(['final_dispatch_id' => $dispatch->id, 'issue_id' => '11111111-1111-4111-8111-111111111111',
        'input_hash' => str_repeat('0', 64), 'request' => [], 'inputs' => [], 'artifact_ref' => 'disposable', 'package_hash' => str_repeat('2', 64)])->save();
    $verification = ['main_sha' => str_repeat('d', 40), 'lineage' => ['flow' => 'proof',
        'candidate' => $test->proofLanding->candidate_sha, 'merge' => $test->proofMerge['merge_sha'], 'tree' => str_repeat('3', 40)]];
    app(TaskCloseoutLedger::class)->record($test->proofLanding, 'verify:'.TaskLandingData::hash($verification),
        ['package_hash' => $test->proofLanding->package_hash], $verification);
    $test->proofStatus['closeout'] = null;
    $test->proofStatus['state'] = 'proof';
    $test->proofStatus['retained_topology'] = ['attempt_id' => str_repeat('f', 32)];
    File::delete($test->proofArchive);
}

function taskProofStageProcesses(?Closure $closeout = null): void
{
    $test = test();
    Process::fake(function ($process) use ($test, $closeout) {
        $command = $process->command;
        if ($command[0] === 'git') {
            return Process::result(output: match ($command[2]) {
                'status' => '', 'branch' => 'main', 'rev-parse' => str_repeat('d', 40),
                default => throw new LogicException('Unexpected disposable Git command.'),
            });
        }
        if ($command[1] === 'status') {
            return Process::result(output: TaskLandingData::json($test->proofStatus));
        }
        if ($command[1] === 'closeout' && $closeout !== null) {
            return $closeout();
        }

        throw new LogicException('Unexpected native mutation in disposable proof fixture.');
    });
}

function taskProofStageComplete(object $test): void
{
    $test->proofStatus['closeout'] = $test->proofRecord;
    $test->proofStatus['state'] = 'captured';
    $test->proofStatus['retained_topology'] = null;
    File::put($test->proofArchive, TaskLandingData::json($test->proofRecord));
}

it('keeps proof stage previews read-only and requires primary reconciliation', function () {
    taskProofStageFixture($this);
    taskProofStageProcesses();
    $before = TaskCloseoutOperation::query()->count();
    $handler = app(CloseOrbitTaskProof::class);
    expect($handler->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', false)['applied'])->toBeFalse()
        ->and(TaskCloseoutOperation::query()->count())->toBe($before)
        ->and(fn () => $handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true))
        ->toThrow(LogicException::class, 'Reconcile clean primary');
    Process::assertNotRan(fn ($process) => in_array('closeout', $process->command, true));
});

it('records one native closeout and never repeats completed installation', function () {
    taskProofStageFixture($this);
    $writes = 0;
    taskProofStageProcesses(function () use (&$writes) {
        $writes++;
        expect(TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->state)->toBe('intended');
        taskProofStageComplete($this);

        return Process::result(output: TaskLandingData::json($this->proofRecord));
    });
    $handler = app(CloseOrbitTaskProof::class);
    $handler->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', true);
    $result = $handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true);
    $retained = TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->getRawOriginal();
    expect($result['closeout'])->toBe($this->proofRecord)->and($result['reacquisition'])->toBe('not_performed');
    $handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true);
    expect($writes)->toBe(1)->and(TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->getRawOriginal())->toBe($retained);
});

it('adopts read-back success after a lost closeout response', function () {
    taskProofStageFixture($this);
    $writes = 0;
    taskProofStageProcesses(function () use (&$writes) {
        $writes++;
        taskProofStageComplete($this);

        return Process::result(output: 'Lost response.', exitCode: 1);
    });
    $handler = app(CloseOrbitTaskProof::class);
    $handler->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', true);
    expect($handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true)['closeout'])->toBe($this->proofRecord)
        ->and($writes)->toBe(1);
});

it('never replays an unknown closeout and can reconcile later exact evidence', function () {
    taskProofStageFixture($this);
    $writes = 0;
    taskProofStageProcesses(function () use (&$writes) {
        $writes++;

        return Process::result(output: 'Transport lost before an observable checkpoint.', exitCode: 1);
    });
    $handler = app(CloseOrbitTaskProof::class);
    $handler->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', true);
    foreach ([1, 2] as $call) {
        expect(fn () => $handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true))
            ->toThrow(LogicException::class, 'remains uncertain');
    }
    expect($writes)->toBe(1)->and(TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->state)->toBe('unknown');
    taskProofStageComplete($this);
    expect($handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true)['closeout'])->toBe($this->proofRecord)
        ->and($writes)->toBe(1);
});

it('retains a known failed attempt and permits only one explicitly requested next attempt', function () {
    taskProofStageFixture($this);
    $writes = 0;
    taskProofStageProcesses(function () use (&$writes) {
        $writes++;
        if ($writes === 1) {
            $record = [...$this->proofRecord, 'state' => 'replacement-failed', 'generation_id' => null, 'error' => 'Known failure.'];
            $this->proofStatus['closeout'] = $record;
            File::put($this->proofArchive, TaskLandingData::json($record));

            return Process::result(output: TaskLandingData::json($record), exitCode: 1);
        }
        taskProofStageComplete($this);

        return Process::result(output: TaskLandingData::json($this->proofRecord));
    });
    $handler = app(CloseOrbitTaskProof::class);
    $handler->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', true);
    expect($handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true)['closeout']['state'])->toBe('replacement-failed')
        ->and($writes)->toBe(1);
    $original = TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->getRawOriginal();
    $handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', false);
    expect($writes)->toBe(1);
    expect($handler->handle($this->proofLanding, $this->proofMerge, 'proof-closeout', true)['closeout']['state'])->toBe('complete')
        ->and($writes)->toBe(2)->and(TaskCloseoutOperation::query()->where('operation', 'proof-closeout:1')->sole()->getRawOriginal())->toBe($original)
        ->and(TaskCloseoutOperation::query()->where('operation', 'proof-closeout:2')->sole()->state)->toBe('completed');
});

it('isolates the existing native primary reconciler on the actual fast-forward path', function () {
    taskProofStageFixture($this);
    File::makeDirectory($this->proofDirectory.'/repository/.git', 0700);
    $previous = getenv('GIT_DIR');
    putenv('GIT_DIR=/unused-foreign-disposable-git');
    $advanced = false;
    $writes = 0;
    try {
        Process::fake(function ($process) use (&$advanced, &$writes) {
            expect($process->environment['GIT_DIR'] ?? null)->toBeFalse();
            $command = $process->command;
            expect($command[0])->toBe('git');
            $arguments = array_slice($command, $command[1] === '--no-replace-objects' ? 2 : 1);
            if ($arguments[0] === 'merge') {
                expect($arguments)->toBe(['merge', '--ff-only', 'origin/main']);
                $writes++;
                $advanced = true;
            }

            return Process::result(output: match ($arguments[0]) {
                'status', 'merge-base', 'merge' => '',
                'branch' => 'main',
                'rev-parse' => $arguments[1] === 'HEAD' && ! $advanced ? str_repeat('0', 40) : str_repeat('d', 40),
                default => throw new LogicException('Unexpected primary process.'),
            });
        });
        $result = app(CloseOrbitTaskProof::class)->handle($this->proofLanding, $this->proofMerge, 'reconcile-primary', true);
        expect($result['primary']['main_sha'])->toBe(str_repeat('d', 40))->and($writes)->toBe(1)
            ->and(TaskCloseoutOperation::query()->where('operation', 'reconcile-primary:'.str_repeat('d', 40))->sole()->state)->toBe('completed');
    } finally {
        putenv($previous === false ? 'GIT_DIR' : 'GIT_DIR='.$previous);
    }
});
