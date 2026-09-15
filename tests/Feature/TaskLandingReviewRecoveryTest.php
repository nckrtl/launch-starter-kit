<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskLanding;
use App\Models\TaskLandingReviewRecovery;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\RecoverTaskLandingReview;
use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Landing\TaskLandingReviewTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(DatabaseMigrations::class, UsesTaskSharedLocks::class);

final class TransportRecoveryReviewer implements TaskLandingReviewer
{
    public array $prompts = [];

    public bool $yielded = true;

    public bool $changed = false;

    public bool $lost = false;

    public ?string $native = null;

    public ?Closure $duringObserve = null;

    public ?Closure $duringPrompt = null;

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        expect(DB::transactionLevel())->toBe(0);
        if ($yielded && ! $this->yielded) {
            throw new LogicException('Reviewer has not yielded.');
        }
        if ($this->duringObserve !== null) {
            ($this->duringObserve)();
        }

        if ($this->native !== null) {
            $session['agentId'] = $this->native;
        }

        return HerdrTaskLandingReviewer::identity($this->changed ? [...$session, 'terminalId' => 'replacement'] : $session);
    }

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        expect(DB::transactionLevel())->toBe(0)
            ->and(TaskLandingReviewRecovery::query()->sole()->state)->toBe('intended');
        $this->prompts[] = $prompt;
        if ($this->duringPrompt !== null) {
            ($this->duringPrompt)();
        }
        if ($this->lost) {
            throw new RuntimeException('Lost one-shot prompt response.');
        }

        return $session;
    }
}

beforeEach(function () {
    Process::preventStrayProcesses();
    Http::preventStrayRequests();
    Queue::fake();
    $this->recoveryDirectory = storage_path('framework/testing/landing-recovery-'.bin2hex(random_bytes(8)));
    $this->transportFixture = new TaskCloseoutFixture($this->recoveryDirectory);
    $token = str_repeat('test-secret-receipt-', 4);
    $this->transportLanding = $this->transportFixture->add(990101, ['state' => 'review_unknown', 'review_result' => null,
        'review_token' => $token, 'review_token_hash' => hash('sha256', $token),
        'review_prompt' => "Original retained legacy supplemental prompt\n".str_repeat('Frozen proof entry ', 160_000),
        'error' => 'Original uncertain delivery error; retained unchanged.']);
    $this->transportReviewer = new TransportRecoveryReviewer;
    app()->instance(TaskLandingReviewer::class, $this->transportReviewer);
    $landing = $this->transportLanding;
    $timestamp = $landing->updated_at->utc()->format('Y-m-d\TH:i:s\Z');
    $line = $timestamp." WARN herdr::api::server: connection failed: api request line is too large\n";
    $path = $this->recoveryDirectory.'/retained-refusal.log';
    File::put($path, $line);
    $original = TaskLandingReviewTransport::line($landing->review_session, $landing->review_prompt);
    $this->transportRequest = ['assignment' => $landing->review_assignment, 'package_hash' => $landing->package_hash,
        'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha, 'input_hash' => $landing->input_hash,
        'original_prompt_sha256' => hash('sha256', $landing->review_prompt), 'original_wire_sha256' => hash('sha256', $original),
        'original_wire_bytes' => strlen($original), 'session' => $landing->review_session,
        'refusal' => ['kind' => 'request_line_too_large', 'path' => $path, 'sha256' => hash('sha256', $line), 'occurred_at' => $timestamp]];
});

afterEach(function () {
    // Only disposable fixture rows are removed before Laravel rolls back its test migrations.
    DB::table('task_landing_review_recoveries')->delete();
    File::deleteDirectory($this->recoveryDirectory);
});

function transportRecoveryPreview(): array
{
    return app(RecoverTaskLandingReview::class)->handle(test()->transportLanding->id, test()->transportRequest, true);
}

function transportRecoveryApply(?string $hash = null): array
{
    return app(RecoverTaskLandingReview::class)->handle(test()->transportLanding->id, test()->transportRequest, true,
        $hash ?? transportRecoveryPreview()['request_hash'], true);
}

function transportRecoveryReceipt(string $verdict = 'pass'): array
{
    $landing = test()->transportLanding;

    return ['assignment' => $landing->review_assignment, 'token' => $landing->review_token, 'package_hash' => $landing->package_hash,
        'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha, 'session' => $landing->review_session,
        'verdict' => $verdict, 'summary' => 'Genuine synthetic reviewer receipt.', 'evidence' => 'Complete frozen package and all evidence checked.'];
}

function transportRecoveryHistory(): array
{
    return [Task::query()->get()->toArray(), TaskRun::query()->get()->toArray(), TaskWorkspace::query()->get()->toArray(),
        TaskAgentDispatch::query()->get()->map(fn ($row) => $row->getRawOriginal())->all(),
        TaskLanding::query()->get()->map(fn ($row) => $row->getRawOriginal())->all()];
}

it('previews then claims a single pinned recovery without altering any original record', function () {
    $before = transportRecoveryHistory();
    $preview = transportRecoveryPreview();
    expect($preview['transport']['wire_bytes'])->toBeLessThan(65_536)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)
        ->and(TaskLandingData::json($preview))->not->toContain($this->transportLanding->review_token, 'Original retained legacy supplemental prompt')
        ->and(transportRecoveryHistory())->toBe($before);
    $result = transportRecoveryApply($preview['request_hash']);
    $audit = TaskLandingReviewRecovery::query()->sole();
    expect($result['applied'])->toBeTrue()->and($audit->state)->toBe('sent')
        ->and($this->transportReviewer->prompts)->toHaveCount(1)
        ->and($audit->reference_prompt)->toContain(TaskLandingData::json($this->transportLanding->package),
            $this->transportLanding->review_assignment, $this->transportLanding->review_token, '.loop/commander-tasks.json')
        ->and($audit->wire_sha256)->toBe(hash('sha256', TaskLandingReviewTransport::line($audit->review_session, $audit->reference_prompt)))
        ->and($audit->getRawOriginal('reference_prompt'))->not->toContain('Perform an independent supplemental review')
        ->and($audit->toJson())->not->toContain($this->transportLanding->review_token, 'reference_prompt')
        ->and(transportRecoveryHistory())->toBe($before)
        ->and(transportRecoveryApply($preview['request_hash'])['applied'])->toBeFalse()
        ->and($this->transportReviewer->prompts)->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('requires ownership runtime enablement and a matching preview before claiming', function (string $failure) {
    $preview = transportRecoveryPreview();
    if ($failure === 'runtime') {
        config(['task-runtime.enabled' => false]);
    }
    expect(fn () => app(RecoverTaskLandingReview::class)->handle($this->transportLanding->id, $this->transportRequest,
        $failure !== 'ownership', $failure === 'pin' ? str_repeat('0', 64) : $preview['request_hash'], true))
        ->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
})->with(['ownership', 'runtime', 'pin']);

it('rejects changed request pins and arbitrary prompts before claiming', function (string $field) {
    $this->transportRequest[$field] = $field === 'session' ? [...$this->transportRequest['session'], 'paneId' => 'replacement'] : 'conflicting-input';
    expect(fn () => transportRecoveryPreview())->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
})->with(['assignment', 'package_hash', 'candidate_sha', 'artifact_sha', 'input_hash', 'original_prompt_sha256', 'original_wire_sha256', 'session', 'prompt']);

it('requires retained contemporaneous size-refusal bytes rather than general uncertainty', function (string $defect) {
    if ($defect === 'hash') {
        File::append($this->transportRequest['refusal']['path'], 'Changed evidence.');
    } elseif ($defect === 'kind') {
        $this->transportRequest['refusal']['kind'] = 'timeout';
    } elseif ($defect === 'small') {
        $this->transportRequest['original_wire_bytes'] = 200;
    } else {
        $timestamp = $defect === 'old' ? '2000-01-01T00:00:00Z' : $this->transportRequest['refusal']['occurred_at'];
        $line = $timestamp.' WARN '.($defect === 'old' ? 'request line is too large' : 'connection closed')."\n";
        $this->transportRequest['refusal']['occurred_at'] = $timestamp;
        $this->transportRequest['refusal']['sha256'] = hash('sha256', $line);
        File::put($this->transportRequest['refusal']['path'], $line);
    }
    expect(fn () => transportRecoveryPreview())->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
})->with(['hash', 'kind', 'small', 'old', 'generic']);

it('refuses evidence ownership or session drift between preview and apply', function (string $change) {
    $preview = transportRecoveryPreview();
    if ($change === 'reviewer') {
        $this->transportReviewer->changed = true;
    } elseif ($change === 'yielded') {
        $this->transportReviewer->yielded = false;
    } elseif ($change === 'evidence') {
        $this->transportFixture->issues[$this->transportLanding->issue_id]['description'] = 'Changed requirement.';
    } else {
        $this->transportLanding->workspace()->firstOrFail()->update(['attention' => 'New owner hold.']);
    }
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
})->with(['reviewer', 'yielded', 'evidence', 'ownership']);

it('rejects a changed native artifact and a verdict recorded after preview', function (bool $verdict) {
    $preview = transportRecoveryPreview();
    if ($verdict) {
        app(ReviewTaskLanding::class)->submit($this->transportLanding->id, transportRecoveryReceipt());
    } else {
        $repository = Mockery::mock(TaskLandingRepository::class);
        $repository->shouldReceive('inspect')->andReturnUsing($this->transportFixture->inspect(...));
        $repository->shouldReceive('artifact')->andReturn(str_repeat('a', 40));
        app()->instance(TaskLandingRepository::class, $repository);
    }
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
})->with([false, true]);

it('pins an optional observed native conversation without changing the old receipt session', function () {
    $landing = $this->transportLanding;
    $session = [...$landing->review_session, 'agentId' => null];
    TaskLanding::withoutEvents(fn () => $landing->update(['review_session' => $session]));
    $workspace = $landing->workspace()->firstOrFail();
    $workspace->update(['reviewer_session' => $session]);
    $final = $workspace->dispatches()->sole();
    TaskAgentDispatch::withoutEvents(fn () => $final->update(['session' => $session]));
    $inputs = app(TaskLandingEvidence::class)->capture($workspace, $landing->request);
    $package = [...$landing->package, 'input_hash' => TaskLandingData::hash($inputs)];
    TaskLanding::withoutEvents(fn () => $landing->update(['inputs' => $inputs, 'input_hash' => $package['input_hash'],
        'package' => $package, 'package_hash' => TaskLandingData::hash($package)]));
    $this->transportRequest['session'] = $session;
    $this->transportRequest['input_hash'] = $landing->input_hash;
    $this->transportRequest['package_hash'] = $landing->package_hash;
    $before = $landing->getRawOriginal();
    $this->transportReviewer->native = '77777777-7777-4777-8777-777777777777';
    transportRecoveryApply();
    expect($landing->refresh()->getRawOriginal())->toBe($before)
        ->and(TaskLandingReviewRecovery::query()->sole()->review_session['agentId'])->toBe($this->transportReviewer->native);
    app(ReviewTaskLanding::class)->submit($landing->id, transportRecoveryReceipt());
    expect($landing->refresh()->state)->toBe('approved');
});

it('holds a claimed attempt without sending when the reviewer changes after claim', function () {
    $preview = transportRecoveryPreview();
    $this->transportReviewer->duringObserve = function (): void {
        if (TaskLandingReviewRecovery::query()->exists()) {
            $this->transportReviewer->changed = true;
        }
    };
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(LogicException::class)
        ->and(TaskLandingReviewRecovery::query()->sole()->state)->toBe('unknown')
        ->and(transportRecoveryApply($preview['request_hash'])['applied'])->toBeFalse()
        ->and($this->transportReviewer->prompts)->toBe([]);
});

it('never retries a lost recovery response and permits only the genuine original receipt', function (string $verdict) {
    $before = transportRecoveryHistory();
    $preview = transportRecoveryPreview();
    $this->transportReviewer->lost = true;
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(RuntimeException::class, 'Lost one-shot')
        ->and(TaskLandingReviewRecovery::query()->sole()->state)->toBe('unknown')
        ->and(transportRecoveryHistory())->toBe($before)
        ->and(transportRecoveryApply($preview['request_hash'])['applied'])->toBeFalse()
        ->and($this->transportReviewer->prompts)->toHaveCount(1);
    $receipt = transportRecoveryReceipt($verdict);
    expect(fn () => app(ReviewTaskLanding::class)->submit($this->transportLanding->id, [...$receipt, 'token' => 'forged']))->toThrow(LogicException::class);
    app(ReviewTaskLanding::class)->submit($this->transportLanding->id, $receipt);
    expect($this->transportLanding->refresh()->state)->toBe($verdict === 'pass' ? 'approved' : 'rejected')
        ->and(TaskLandingReviewRecovery::query()->sole()->state)->toBe('unknown')
        ->and(transportRecoveryApply($preview['request_hash'])['applied'])->toBeFalse()
        ->and($this->transportReviewer->prompts)->toHaveCount(1);
})->with(['pass', 'revise', 'blocked']);

it('returns the committed intent to a concurrent identical recovery without another send', function () {
    $preview = transportRecoveryPreview();
    $this->transportReviewer->duringPrompt = function () use ($preview): void {
        expect(transportRecoveryApply($preview['request_hash'])['recovery']['state'])->toBe('intended');
    };
    transportRecoveryApply($preview['request_hash']);
    expect($this->transportReviewer->prompts)->toHaveCount(1)->and(TaskLandingReviewRecovery::query()->count())->toBe(1);
    $this->transportRequest['assignment'] = 'conflict';
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(LogicException::class, 'conflicting');
});

it('keeps genuine immediate verdicts when the later recovery response is lost', function () {
    $preview = transportRecoveryPreview();
    $this->transportReviewer->duringPrompt = fn () => app(ReviewTaskLanding::class)->submit($this->transportLanding->id, transportRecoveryReceipt());
    $this->transportReviewer->lost = true;
    expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(RuntimeException::class)
        ->and($this->transportLanding->refresh()->state)->toBe('approved')
        ->and(TaskLandingReviewRecovery::query()->sole()->state)->toBe('unknown');
});

it('refuses an outer transaction before recording a recoverable intent', function () {
    $preview = transportRecoveryPreview();
    DB::transaction(function () use ($preview): void {
        expect(fn () => transportRecoveryApply($preview['request_hash']))->toThrow(LogicException::class, 'outer transaction');
    });
    expect(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([]);
});

it('rejects an oversized reference request before claiming recovery and preserves the full body', function () {
    $landing = $this->transportLanding;
    $package = [...$landing->package, 'body' => str_repeat("\tfull body tail", 10_000)];
    // Create the synthetic oversize case; production mutation guards remain enabled.
    TaskLanding::withoutEvents(fn () => $landing->update(['package' => $package, 'package_hash' => TaskLandingData::hash($package)]));
    $this->transportRequest['package_hash'] = $landing->package_hash;
    $before = transportRecoveryHistory();
    expect(fn () => transportRecoveryApply(str_repeat('a', 64)))->toThrow(LogicException::class, 'serialized supplemental review request')
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(0)->and($this->transportReviewer->prompts)->toBe([])
        ->and(transportRecoveryHistory())->toBe($before);
});

it('retains immutable audit fields and refuses populated schema rollback', function () {
    $migration = require database_path('migrations/2026_09_13_023057_create_task_landing_review_recoveries_table.php');
    $before = transportRecoveryHistory();
    $migration->down();
    $migration->up();
    expect(transportRecoveryHistory())->toBe($before);
    transportRecoveryApply();
    $audit = TaskLandingReviewRecovery::query()->sole();
    foreach (['reference_prompt' => 'replace', 'inputs' => [], 'evidence' => [], 'request_hash' => str_repeat('a', 64), 'state' => 'intended'] as $key => $value) {
        expect(fn () => $audit->refresh()->update([$key => $value]))->toThrow(LogicException::class);
    }
    expect(fn () => $audit->refresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $migration->down())->toThrow(LogicException::class, 'Retain recovery audit rows')
        ->and(TaskLandingReviewRecovery::query()->count())->toBe(1)->and(transportRecoveryHistory())->toBe($before);
});

it('previews and applies the exact CLI request without exposing the private prompt', function () {
    $path = $this->recoveryDirectory.'/recovery.json';
    File::put($path, TaskLandingData::json($this->transportRequest));
    $arguments = ['landing' => $this->transportLanding->id, '--file' => $path, '--exclusive' => true];
    expect(Artisan::call('tasks:landing-review-recover', $arguments))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(TaskLandingReviewRecovery::query()->count())->toBe(0)
        ->and(Artisan::call('tasks:landing-review-recover', [...$arguments, '--request' => $preview['request_hash'], '--apply' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain($this->transportLanding->review_token, 'Perform an independent supplemental review')
        ->and($this->transportReviewer->prompts)->toHaveCount(1);
});
