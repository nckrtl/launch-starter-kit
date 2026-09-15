<?php

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskLandingAmendment;
use App\Models\TaskWorkspace;
use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Completion\CompleteTaskLanding;
use App\Tasks\Completion\TaskCompletionEvidence;
use App\Tasks\Landing\AmendTaskLanding;
use App\Tasks\Landing\PrepareTaskLanding;
use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingPackage;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Runtime\TaskAgents;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TaskCompletionFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class AmendmentTestReviewer implements TaskAgents, TaskLandingReviewer
{
    public array $prompts = [];

    public bool $yielded = true;

    public bool $changed = false;

    public bool $losePrompt = false;

    public ?Closure $duringObserve = null;

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        if ($yielded && ! $this->yielded) {
            throw new LogicException('Reviewer has not yielded.');
        }
        if ($this->duringObserve !== null) {
            ($this->duringObserve)($workspace);
        }

        return $this->changed ? [...$session, 'paneId' => 'replacement'] : $session;
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = $prompt;
        if ($this->losePrompt) {
            throw new RuntimeException('Lost prompt response.');
        }

        return $session;
    }

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        return $this->prompt($workspace, $session, $prompt);
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        throw new LogicException('No new agent is permitted.');
    }

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        throw new LogicException('Use the exact supplemental identity.');
    }
}

function amendmentReceipt(TaskLanding $landing, string $verdict = 'blocked'): array
{
    return ['token' => $landing->review_token, 'assignment' => $landing->review_assignment,
        'package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
        'artifact_sha' => $landing->artifact_sha, 'session' => $landing->review_session,
        'verdict' => $verdict, 'summary' => 'Exact independent '.$verdict,
        'evidence' => 'Full candidate, immutable artifact, Builder gate and complete PR body inspected.'];
}

function amendmentApply(?array $request = null, ?string $hash = null): array
{
    $test = test();
    $request ??= $test->amendRequest;
    $action = app(AmendTaskLanding::class);
    $hash ??= $action->handle($test->amendOriginal->id, $request, true)['proposal_hash'];

    return $action->handle($test->amendOriginal->id, $request, true, $hash, true);
}

beforeEach(function () {
    $this->amendDirectory = storage_path('framework/testing/task-amendment-'.bin2hex(random_bytes(8)));
    $this->amendFixture = new TaskCompletionFixture($this->amendDirectory);
    $this->amendReviewer = new AmendmentTestReviewer;
    app()->instance(TaskAgents::class, $this->amendReviewer);
    app()->instance(TaskLandingReviewer::class, $this->amendReviewer);
    $landing = $this->amendFixture->base->add(990253, ['state' => 'packaged',
        'review_assignment' => null, 'review_session' => null, 'review_result' => null]);
    $workspace = $landing->workspace()->firstOrFail();
    $package = app(TaskLandingPackage::class)->render($workspace, $landing, $landing->artifact_sha);
    DB::table('task_landings')->where('id', $landing->id)->update([
        'package' => json_encode($package, JSON_THROW_ON_ERROR), 'package_hash' => TaskLandingData::hash($package)]);
    $landing->refresh();
    $this->amendFixture->base->issues[$landing->issue_id]['team']['states']['nodes'] = [['id' => $this->amendFixture->done, 'name' => 'Done']];
    $this->amendFixture->landings[] = $landing;
    $reviews = app(ReviewTaskLanding::class);
    $reviews->dispatch($landing->id, $landing->package_hash, true, true);
    $reviews->submit($landing->id, amendmentReceipt($landing->refresh()));
    $this->amendOriginal = $landing->refresh();
    $this->amendBefore = $landing->getRawOriginal();
    $this->amendPublicBefore = TaskLandingData::json($landing->toArray());
    $this->amendReviewer->prompts = [];
    $this->amendEvidence = $this->amendDirectory.'/correction.json';
    $contents = "{\n  \"operation\": \"Preserved an unused empty scaffold without deletion.\",\n  \"complete\": true\n}\n";
    File::put($this->amendEvidence, $contents);
    $this->amendRequest = ['package_hash' => $landing->package_hash,
        'review_hash' => TaskLandingData::hash($landing->review_result),
        'reason' => 'Correct stale preparation-only scaffold wording.',
        'pull_request_body' => $landing->request['pull_request_body']."\n\nThe unused scaffold was preserved before publication; no cleanup is authorized.\n".$contents,
        'evidence' => ['path' => $this->amendEvidence, 'sha256' => hash('sha256', $contents)]];
});

it('refuses an interleaved conflicting amendment and stale files at the locked boundary', function (bool $conflict) {
    $preview = app(AmendTaskLanding::class)->handle($this->amendOriginal->id, $this->amendRequest, true);
    if ($conflict) {
        $this->amendReviewer->duringObserve = function () {
            $this->amendReviewer->duringObserve = null;
            amendmentApply([...$this->amendRequest, 'reason' => 'Other exact correction intent.']);
        };
        expect(fn () => amendmentApply(hash: $preview['proposal_hash']))->toThrow(LogicException::class, 'conflicting');
        expect(TaskLanding::query()->count())->toBe(2)->and(TaskLandingAmendment::query()->count())->toBe(1);
    } else {
        $path = $this->amendEvidence;
        $level = DB::transactionLevel();
        Event::listen('eloquent.retrieved: '.TaskLanding::class, function () use ($path, $level): void {
            if (DB::transactionLevel() > $level) {
                File::put($path, 'Changed after the full read-only preflight.');
            }
        });
        expect(fn () => amendmentApply(hash: $preview['proposal_hash']))->toThrow(LogicException::class, 'changed');
        expect(TaskLanding::query()->count())->toBe(1)->and(TaskLandingAmendment::query()->count())->toBe(0);
    }
    expect($this->amendReviewer->prompts)->toBe([]);
})->with([true, false]);

it('honors workspace lock contention without writing a second landing', function () {
    $lock = Cache::lock('tasks:runtime:'.$this->amendOriginal->task_workspace_id, 60);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => amendmentApply())->toThrow(LockTimeoutException::class)
            ->and(TaskLanding::query()->count())->toBe(1)
            ->and(TaskLandingAmendment::query()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('retains full correction evidence after its file disappears and refuses an oversized review before dispatch', function () {
    File::put($this->amendEvidence, str_repeat("\t", 60_000));
    $this->amendRequest['evidence']['sha256'] = hash_file('sha256', $this->amendEvidence);
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    File::delete($this->amendEvidence);
    $replay = amendmentApply(hash: $result['amendment']['request_hash']);
    expect($replay['applied'])->toBeFalse()
        ->and(TaskLandingAmendment::query()->sole()->audit['evidence']['contents'])->toBe(str_repeat("\t", 60_000));
    expect(fn () => app(ReviewTaskLanding::class)->dispatch($successor->id, $successor->package_hash, true, true))
        ->toThrow(LogicException::class)
        ->and($successor->refresh()->review_assignment)->toBeNull()
        ->and($this->amendReviewer->prompts)->toBe([]);
});

it('keeps main correctness holds in force for an approved successor', function () {
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    $reviews = app(ReviewTaskLanding::class);
    $reviews->dispatch($successor->id, $successor->package_hash, true, true);
    $reviews->submit($successor->id, amendmentReceipt($successor->refresh(), 'pass'));
    $successor->refresh();
    app(CloseTaskLanding::class)->handle($successor->id, $successor->package_hash, 'publish', true, true);
    $this->amendFixture->remote->failures = ['unrelated-main-failure'];
    expect(fn () => app(CloseTaskLanding::class)->handle($successor->id, $successor->package_hash, 'merge', true, true))
        ->toThrow(LogicException::class, 'authorization')
        ->and($this->amendFixture->remote->merges)->toBe([])
        ->and($this->amendOriginal->refresh()->state)->toBe('rejected');
});

afterEach(function () {
    File::deleteDirectory($this->amendDirectory);
});

it('previews the full canonical correction without changing any retained state or performing side effects', function () {
    $preview = app(AmendTaskLanding::class)->handle($this->amendOriginal->id, $this->amendRequest, true);
    expect($preview['applied'])->toBeFalse()
        ->and($preview['package']['body'])->toContain($this->amendRequest['pull_request_body'], '## Frozen inputs', $this->amendOriginal->artifact_sha)
        ->and(array_diff_key($preview['package'], ['body' => true]))->toBe(array_diff_key($this->amendOriginal->package, ['body' => true]))
        ->and($preview['audit']['evidence']['contents'])->toBe(File::get($this->amendEvidence))
        ->and(TaskLanding::query()->count())->toBe(1)
        ->and(TaskLandingAmendment::query()->count())->toBe(0)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore)
        ->and($this->amendReviewer->prompts)->toBe([])
        ->and($this->amendFixture->remote->effects)->toBe([])
        ->and($this->amendFixture->writes)->toBe([]);
    Queue::assertNothingPushed();
});

it('atomically appends one successor and retains byte-identical v1 history with inert exact replay', function () {
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    $again = amendmentApply(hash: $result['amendment']['request_hash']);
    expect($result['applied'])->toBeTrue()->and($again['applied'])->toBeFalse()
        ->and($again['landing']['id'])->toBe($successor->id)
        ->and($successor->state)->toBe('packaged')->and($successor->review_assignment)->toBeNull()
        ->and($successor->inputs)->toBe($this->amendOriginal->inputs)
        ->and($successor->candidate_sha)->toBe($this->amendOriginal->candidate_sha)
        ->and($successor->artifact_sha)->toBe($this->amendOriginal->artifact_sha)
        ->and($successor->request)->toBe([...$this->amendOriginal->request, 'pull_request_body' => $this->amendRequest['pull_request_body']])
        ->and(TaskLanding::query()->count())->toBe(2)->and(TaskLandingAmendment::query()->count())->toBe(1)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore)
        ->and(TaskLandingData::json($this->amendOriginal->toArray()))->toBe($this->amendPublicBefore)
        ->and($this->amendReviewer->prompts)->toBe([]);
    $prepare = app(PrepareTaskLanding::class)->handle($successor->task_workspace_id, $this->amendOriginal->request, true);
    expect($prepare['landing']['id'])->toBe($this->amendOriginal->id);
    expect(fn () => app(PrepareTaskLanding::class)->handle($successor->task_workspace_id, $successor->request, true))->toThrow(LogicException::class, 'immutable');
});

it('refuses missing authority and stale exact intent pins', function (string $change) {
    $request = $this->amendRequest;
    $exclusive = true;
    $hash = null;
    $apply = false;
    if ($change === 'exclusive') {
        $exclusive = false;
    } elseif ($change === 'runtime') {
        config(['task-runtime.enabled' => false]);
        $apply = true;
    } elseif ($change === 'proposal') {
        $apply = true;
        $hash = str_repeat('a', 64);
    } else {
        $request[$change] = str_repeat('f', 64);
    }
    expect(fn () => app(AmendTaskLanding::class)->handle($this->amendOriginal->id, $request, $exclusive, $hash, $apply))
        ->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(1)
        ->and(TaskLandingAmendment::query()->count())->toBe(0);
})->with(['exclusive', 'runtime', 'proposal', 'package_hash', 'review_hash']);

it('rejects non-rejected or unbound predecessors', function (string $change) {
    if ($change === 'verdict') {
        $review = [...$this->amendOriginal->review_result, 'verdict' => 'pass'];
        DB::table('task_landings')->where('id', $this->amendOriginal->id)->update(['review_result' => json_encode($review)]);
        $this->amendRequest['review_hash'] = TaskLandingData::hash($review);
    } elseif ($change === 'assignment') {
        DB::table('task_landings')->where('id', $this->amendOriginal->id)->update(['review_assignment' => '77777777-7777-4777-8777-777777777777']);
    } else {
        DB::table('task_landings')->where('id', $this->amendOriginal->id)->update(['state' => $change]);
    }
    expect(fn () => amendmentApply())->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(1);
})->with(['approved', 'packaged', 'prompting', 'sent', 'review_unknown', 'verdict', 'assignment']);

it('rejects changed accepted inputs and reviewer ownership', function (string $change) {
    $workspace = $this->amendOriginal->workspace()->firstOrFail();
    if ($change === 'reviewer') {
        $this->amendReviewer->changed = true;
    } elseif ($change === 'yield') {
        $this->amendReviewer->yielded = false;
    } elseif ($change === 'ownership') {
        $workspace->update(['attention' => 'Other controller owns the checkout.']);
    } elseif ($change === 'issue') {
        $this->amendFixture->base->issues[$this->amendOriginal->issue_id]['description'] = 'Changed acceptance.';
    } else {
        $this->amendFixture->base->repositories[$workspace->id][$change] = 'changed';
    }
    expect(fn () => amendmentApply())->toThrow(LogicException::class)
        ->and(TaskLandingAmendment::query()->count())->toBe(0);
})->with(['reviewer', 'yield', 'ownership', 'issue', 'candidate', 'tree', 'gate_sha256', 'gate_contents']);

it('refuses no-op bodies non-body input fields and unbounded correction data', function (string $change) {
    $request = $this->amendRequest;
    if ($change === 'noop') {
        $request['pull_request_body'] = $this->amendOriginal->request['pull_request_body'];
    } elseif ($change === 'reason') {
        $request['reason'] = str_repeat('x', 4001);
    } elseif ($change === 'body') {
        $request['pull_request_body'] = str_repeat('x', 50_001);
    } else {
        $request[$change] = 'unauthorized';
    }
    expect(fn () => amendmentApply($request))->toThrow($change === 'noop' ? LogicException::class : InvalidArgumentException::class)
        ->and(TaskLanding::query()->count())->toBe(1);
})->with(['noop', 'reason', 'body', 'candidate', 'artifact_sha', 'inputs', 'title']);

it('refuses unsafe stale or oversized correction files', function (string $change) {
    if ($change === 'changed') {
        File::put($this->amendEvidence, 'Changed bytes.');
    } elseif ($change === 'oversized') {
        File::put($this->amendEvidence, str_repeat('x', 65_537));
        $this->amendRequest['evidence']['sha256'] = hash_file('sha256', $this->amendEvidence);
    } elseif ($change === 'symlink') {
        symlink($this->amendEvidence, $this->amendDirectory.'/link');
        $this->amendRequest['evidence']['path'] = $this->amendDirectory.'/link';
    } elseif ($change === 'checkout') {
        $path = $this->amendOriginal->workspace()->firstOrFail()->worktree.'/correction.json';
        File::copy($this->amendEvidence, $path);
        $this->amendRequest['evidence']['path'] = $path;
    } else {
        $this->amendRequest['evidence']['path'] = 'relative.json';
    }
    expect(fn () => amendmentApply())->toThrow(in_array($change, ['changed', 'checkout'], true) ? LogicException::class : InvalidArgumentException::class)
        ->and(TaskLandingAmendment::query()->count())->toBe(0);
})->with(['changed', 'oversized', 'symlink', 'checkout', 'relative']);

it('refuses private dispatch and supplemental review material anywhere in correction input', function (string $private) {
    $secret = $private === 'dispatch'
        ? $this->amendOriginal->workspace()->firstOrFail()->dispatches()->firstOrFail()->handoff_token
        : $this->amendOriginal->{$private};
    File::put($this->amendEvidence, $secret);
    $this->amendRequest['evidence']['sha256'] = hash_file('sha256', $this->amendEvidence);
    expect(fn () => amendmentApply())->toThrow(LogicException::class)
        ->and(TaskLanding::query()->count())->toBe(1);
})->with(['dispatch', 'review_token', 'review_token_hash', 'review_prompt']);

it('refuses already started downstream operations and unknown duplicate rows', function (bool $duplicate) {
    if ($duplicate) {
        $copy = $this->amendOriginal->getRawOriginal();
        unset($copy['id'], $copy['review_assignment']);
        DB::table('task_landings')->insert($copy);
    } else {
        TaskCloseoutOperation::query()->create(['task_landing_id' => $this->amendOriginal->id, 'operation' => 'publication',
            'assignment' => '77777777-7777-4777-8777-777777777777', 'package_hash' => $this->amendOriginal->package_hash,
            'input' => [], 'input_hash' => TaskLandingData::hash([]), 'state' => 'prepared']);
    }
    expect(fn () => amendmentApply())->toThrow(LogicException::class)
        ->and(TaskLandingAmendment::query()->count())->toBe(0);
})->with([true, false]);

it('does not fork or chain a completed amendment and keeps its audit immutable', function () {
    $result = amendmentApply();
    $request = [...$this->amendRequest, 'reason' => 'Conflicting correction.'];
    expect(fn () => amendmentApply($request, $result['amendment']['request_hash']))->toThrow(LogicException::class, 'conflicting');
    expect(fn () => app(AmendTaskLanding::class)->handle($result['landing']['id'], $this->amendRequest, true))->toThrow(LogicException::class, 'original');
    $audit = TaskLandingAmendment::query()->sole();
    expect(fn () => $audit->update(['request_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class, 'immutable');
    expect(fn () => $audit->delete())->toThrow(LogicException::class, 'history');
});

it('rolls back the successor if recording its audit fails', function () {
    Event::listen('eloquent.creating: '.TaskLandingAmendment::class, fn () => throw new RuntimeException('Injected audit failure.'));
    expect(fn () => amendmentApply())->toThrow(RuntimeException::class, 'Injected')
        ->and(TaskLanding::query()->count())->toBe(1)->and(TaskLandingAmendment::query()->count())->toBe(0)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore);
});

it('reconciles interleaved equal attempts exactly once without prompting', function () {
    $preview = app(AmendTaskLanding::class)->handle($this->amendOriginal->id, $this->amendRequest, true);
    $this->amendReviewer->duringObserve = function () use ($preview) {
        $this->amendReviewer->duringObserve = null;
        amendmentApply(hash: $preview['proposal_hash']);
    };
    $result = amendmentApply(hash: $preview['proposal_hash']);
    expect($result['applied'])->toBeFalse()->and(TaskLanding::query()->count())->toBe(2)
        ->and(TaskLandingAmendment::query()->count())->toBe(1)->and($this->amendReviewer->prompts)->toBe([]);
});

it('binds a fresh successor review and preserves rejected or uncertain outcomes', function (string $outcome) {
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    $review = app(ReviewTaskLanding::class);
    $this->amendReviewer->losePrompt = $outcome === 'unknown';
    if ($outcome === 'unknown') {
        expect(fn () => $review->dispatch($successor->id, $successor->package_hash, true, true))->toThrow(RuntimeException::class);
        $review->dispatch($successor->id, $successor->package_hash, true, true);
        expect($successor->refresh()->state)->toBe('review_unknown')->and($this->amendReviewer->prompts)->toHaveCount(1);
    } else {
        $review->dispatch($successor->id, $successor->package_hash, true, true);
        $successor->refresh();
        expect(fn () => $review->submit($successor->id, amendmentReceipt($this->amendOriginal, 'pass')))->toThrow(LogicException::class);
        $receipt = amendmentReceipt($successor, $outcome);
        $review->submit($successor->id, $receipt);
        $review->submit($successor->id, $receipt);
        expect($successor->refresh()->state)->toBe($outcome === 'pass' ? 'approved' : 'rejected');
    }
    expect($successor->review_assignment)->not->toBe($this->amendOriginal->review_assignment)
        ->and($this->amendReviewer->prompts[0])->toContain('Body-only amendment audit:', trim(TaskLandingData::json(File::get($this->amendEvidence))),
            'original rejected package and complete verdict', 'Verify the pinned hashes and amendment audit',
            'Always inspect and reconcile the complete retained correction evidence against the complete current PR body',
            'This audit supplements, and does not replace or alter, the artifact inputs', 'The previous verdict remains rejected',
            'Apply the same reuse limits to unchanged artifact content; independently judge this entire new package',
            'reuse only your own recorded independent assessment',
            'If your own prior assessment is unavailable or its native binding does not match, perform the full relevant independent review',
            'Submit a fresh verdict bound to this exact package and assignment; prior approval never transfers',
            $successor->package_hash, $successor->review_assignment)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore);
})->with(['pass', 'revise', 'blocked', 'unknown']);

it('carries the exact successor approval through closeout and completion without promoting the predecessor', function () {
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    $this->amendFixture->landings[] = $successor;
    expect(fn () => app(CloseTaskLanding::class)->handle($successor->id, $successor->package_hash, 'publish', true, true))
        ->toThrow(LogicException::class, 'approved');
    $reviews = app(ReviewTaskLanding::class);
    $reviews->dispatch($successor->id, $successor->package_hash, true, true);
    $reviews->submit($successor->id, amendmentReceipt($successor->refresh(), 'pass'));
    $successor->refresh();
    $this->amendFixture->land($successor);
    $completed = app(CompleteTaskLanding::class)->handle($successor->id, $successor->package_hash, true, true);
    expect($completed['state'])->toBe('delivered')
        ->and($completed['delivery']['package_hash'])->toBe($successor->package_hash)
        ->and($this->amendFixture->remote->publications[$successor->id]['body_hash'])->toBe(hash('sha256', $successor->package['body']))
        ->and($this->amendFixture->remote->approvals[$successor->id]['review_body_hash'])
        ->toBe(hash('sha256', app(TaskCloseoutContext::class)->approvalMarker($successor)))
        ->and(TaskCloseoutOperation::query()->where('task_landing_id', $this->amendOriginal->id)->count())->toBe(0)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore);
    expect(fn () => app(CompleteTaskLanding::class)->handle($this->amendOriginal->id, $successor->package_hash, true, true))->toThrow(LogicException::class);
    expect(fn () => app(TaskCloseoutContext::class)->approved($this->amendOriginal->id, $this->amendOriginal->package_hash))->toThrow(LogicException::class);
});

it('rejects missing or altered amendment provenance at review closeout and completion', function (string $boundary) {
    $result = amendmentApply();
    $successor = TaskLanding::query()->findOrFail($result['landing']['id']);
    DB::table('task_landing_amendments')->where('id', $result['amendment']['id'])->update(['audit_hash' => str_repeat('a', 64)]);
    $action = match ($boundary) {
        'review' => fn () => app(ReviewTaskLanding::class)->dispatch($successor->id, $successor->package_hash, true, true),
        'closeout' => fn () => app(TaskCloseoutContext::class)->approved($successor->id, $successor->package_hash),
        default => fn () => app(CompleteTaskLanding::class)->handle($successor->id, $successor->package_hash, true, true),
    };
    expect($action)->toThrow(LogicException::class, 'history')
        ->and($this->amendReviewer->prompts)->toBe([])
        ->and($this->amendFixture->remote->effects)->toBe([]);
})->with(['review', 'closeout', 'completion']);

it('reverses and reapplies the migration without changing populated v1 row projections or admission hashes', function () {
    $approved = $this->amendFixture->add(990254);
    $this->amendFixture->land($approved);
    $evidence = app(TaskCompletionEvidence::class);
    $input = $evidence->input($approved);
    $admission = $evidence->admission($approved, $input);
    $before = TaskLandingData::json($approved->fresh()->toArray());
    $columns = Schema::getColumnListing('task_landings');
    $migration = require database_path('migrations/2026_09_13_060000_create_task_landing_amendments_table.php');
    $migration->down();
    expect(Schema::hasTable('task_landing_amendments'))->toBeFalse()
        ->and(TaskLandingData::json($approved->fresh()->toArray()))->toBe($before);
    $migration->up();
    expect(Schema::getColumnListing('task_landings'))->toBe($columns)
        ->and(TaskLandingData::json($approved->fresh()->toArray()))->toBe($before)
        ->and($evidence->admission($approved->fresh(), $input))->toBe($admission)
        ->and($this->amendOriginal->refresh()->getRawOriginal())->toBe($this->amendBefore)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

it('refuses rollback of recorded amendments and enforces unique audit endpoints', function () {
    amendmentApply();
    $migration = require database_path('migrations/2026_09_13_060000_create_task_landing_amendments_table.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Retain');
    $audit = TaskLandingAmendment::query()->sole()->getRawOriginal();
    unset($audit['id']);
    expect(fn () => DB::table('task_landing_amendments')->insert($audit))->toThrow(QueryException::class)
        ->and(TaskLandingAmendment::query()->count())->toBe(1);
});

it('uses explicit preview and pinned apply commands without advancing or reviewing work', function () {
    $path = $this->amendDirectory.'/request.json';
    File::put($path, TaskLandingData::json($this->amendRequest));
    expect(Artisan::call('tasks:landing-amend', ['landing' => $this->amendOriginal->id, '--file' => $path, '--exclusive' => true]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect(TaskLanding::query()->count())->toBe(1);
    expect(Artisan::call('tasks:landing-amend', ['landing' => $this->amendOriginal->id, '--file' => $path,
        '--proposal' => $preview['proposal_hash'], '--exclusive' => true, '--apply' => true]))->toBe(0)
        ->and(TaskLanding::query()->count())->toBe(2)->and($this->amendReviewer->prompts)->toBe([]);
    Queue::assertNothingPushed();
});
