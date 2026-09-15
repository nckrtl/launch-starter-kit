<?php

use App\Models\TaskAgentDispatch;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskWorkspace;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Orbit\ReviewTaskSnapshotReacquisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class SnapshotReviewTestAgent implements TaskLandingReviewer
{
    public array $prompts = [];

    public bool $loseResponse = false;

    public bool $changed = false;

    public ?Closure $duringPrompt = null;

    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array
    {
        return $this->changed ? [...$session, 'terminalId' => 'another-terminal'] : $session;
    }

    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = $prompt;
        if ($this->duringPrompt !== null) {
            ($this->duringPrompt)();
        }
        if ($this->loseResponse) {
            throw new RuntimeException('Untrusted transport failure: '.$prompt);
        }

        return $session;
    }
}

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $this->reviewDirectory = storage_path('framework/testing/snapshot-review-'.bin2hex(random_bytes(8)));
    $this->fixture = new TaskCloseoutFixture($this->reviewDirectory);
    app()->useStoragePath($this->reviewDirectory.'/storage');
    $this->landing = $this->fixture->add(91);
    $this->agent = new SnapshotReviewTestAgent;
    app()->instance(TaskLandingReviewer::class, $this->agent);

    // This suite isolates assignment transport from native snapshot provenance.
    // The reacquisition caller must supply the real, validated evidence bundle.
    $workspace = $this->landing->workspace()->firstOrFail();
    $configuration = [...$workspace->configuration,
        'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]];
    DB::table('task_workspaces')->where('id', $workspace->id)->update(['configuration' => TaskLandingData::json($configuration)]);
    $inputs = [...$this->landing->inputs, 'schema' => 2,
        'database' => app(TaskLandingEvidence::class)->database($workspace->fresh(), $this->landing->request)];
    $package = [...$this->landing->package, 'schema' => 2, 'input_hash' => TaskLandingData::hash($inputs)];
    $review = [...$this->landing->review_result, 'package_hash' => TaskLandingData::hash($package)];
    DB::table('task_landings')->where('id', $this->landing->id)->update([
        'inputs' => TaskLandingData::json($inputs), 'input_hash' => TaskLandingData::hash($inputs),
        'package' => TaskLandingData::json($package), 'package_hash' => TaskLandingData::hash($package),
        'review_result' => TaskLandingData::json($review),
    ]);
    $this->landing->refresh();
    $this->bundle = ['schema' => 1, 'candidate_sha' => $this->landing->candidate_sha,
        'generation' => 'disposable-generation', 'discovery' => ['attempt' => 'discovery-fixture'],
        'proof' => ['attempt' => 'proof-fixture'], 'observations' => 'Disposable native evidence stand-in.'];
});

afterEach(fn () => File::deleteDirectory($this->reviewDirectory));

function snapshotReviewReceipt(string $verdict = 'pass'): array
{
    $operation = TaskCloseoutOperation::query()->where('operation', 'like', 'snapshot-review:%')->orderByDesc('id')->firstOrFail();
    $prompt = Crypt::decryptString($operation->preflight['prompt']);
    preg_match('/"token": "([A-Za-z0-9]+)"/', $prompt, $matches);

    return ['token' => $matches[1], 'assignment' => $operation->assignment,
        'bundle_hash' => $operation->input['bundle_hash'], 'verdict' => $verdict,
        'summary' => 'Independent postinstall assessment.', 'evidence' => 'Both exact generation acquisitions and sample observations checked.'];
}

it('previews without creating assignments files or transport writes', function () {
    $before = $this->landing->getRawOriginal();
    $result = app(ReviewTaskSnapshotReacquisition::class)->dispatch($this->landing, $this->bundle, false);
    expect($result['state'])->toBe('eligible')->and($result['applied'])->toBeFalse()
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)->and($this->agent->prompts)->toBe([])
        ->and(is_dir(storage_path('app/private/task-snapshot-reviews')))->toBeFalse()
        ->and($this->landing->fresh()->getRawOriginal())->toBe($before);
    Process::assertNothingRan();
});

it('retains one private immutable bundle and sends only one assignment without altering task review history', function () {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $before = [$this->landing->getRawOriginal(), $this->landing->workspace()->firstOrFail()->getRawOriginal()];
    $result = $service->dispatch($this->landing, $this->bundle, true);
    $operation = TaskCloseoutOperation::query()->findOrFail($result['operation_id']);
    $receipt = snapshotReviewReceipt();
    $path = $operation->preflight['bundle_path'];
    expect($result['state'])->toBe('intended')->and($this->agent->prompts)->toHaveCount(1)
        ->and(File::get($path))->toBe(TaskLandingData::json($this->bundle))
        ->and(fileperms($path) & 0077)->toBe(0)->and(fileperms(dirname($path)) & 0077)->toBe(0)
        ->and($operation->preflight['prompt'])->not->toContain($receipt['token'])
        ->and(TaskLandingData::json($result))->not->toContain($receipt['token'])
        ->and($service->dispatch($this->landing, $this->bundle, true)['applied'])->toBeFalse()
        ->and($this->agent->prompts)->toHaveCount(1)->and(TaskAgentDispatch::query()->count())->toBe(1)
        ->and([$this->landing->fresh()->getRawOriginal(), $this->landing->workspace()->firstOrFail()->getRawOriginal()])->toBe($before);
});

it('keeps ambiguous sends unknown and does not leak transport contents or resend', function () {
    $this->agent->loseResponse = true;
    $service = app(ReviewTaskSnapshotReacquisition::class);
    try {
        $service->dispatch($this->landing, $this->bundle, true);
        test()->fail('An uncertain send must not report success.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('Postinstall review prompt delivery is uncertain; inspect the retained assignment without resending.')
            ->and($exception->getPrevious())->toBeNull();
    }
    $operation = TaskCloseoutOperation::query()->sole();
    expect($operation->state)->toBe('unknown')->and($operation->error)->not->toContain(snapshotReviewReceipt()['token']);
    expect($service->dispatch($this->landing, $this->bundle, true)['state'])->toBe('unknown')
        ->and($this->agent->prompts)->toHaveCount(1);
    expect(fn () => $service->dispatch($this->landing, [...$this->bundle, 'revision' => 2], true))
        ->toThrow(LogicException::class, 'earlier postinstall review');
    $result = $service->submit($operation->id, snapshotReviewReceipt());
    expect($result['review']['verdict'])->toBe('pass')->and($this->agent->prompts)->toHaveCount(1);
});

it('resumes a prepared unsent assignment without changing its durable identity', function () {
    $hash = TaskLandingData::hash($this->bundle);
    $input = ['package_hash' => $this->landing->package_hash, 'candidate_sha' => $this->landing->candidate_sha,
        'bundle_hash' => $hash, 'bundle' => $this->bundle];
    $prepared = TaskCloseoutOperation::query()->create(['task_landing_id' => $this->landing->id,
        'operation' => 'snapshot-review:'.$hash, 'assignment' => (string) Str::uuid(),
        'package_hash' => $this->landing->package_hash, 'input' => $input, 'input_hash' => TaskLandingData::hash($input)]);
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $result = $service->dispatch($this->landing, $this->bundle, true);
    expect($result['operation_id'])->toBe($prepared->id)->and($result['assignment'])->toBe($prepared->assignment)
        ->and($service->submit($prepared->id, snapshotReviewReceipt())['review']['verdict'])->toBe('pass')
        ->and($this->agent->prompts)->toHaveCount(1);
});

it('accepts a fast reviewer while prompt transport is still running and reconciles a lost response', function (bool $lost) {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $this->agent->loseResponse = $lost;
    $this->agent->duringPrompt = function () use ($service) {
        $id = TaskCloseoutOperation::query()->sole()->id;
        expect($service->submit($id, snapshotReviewReceipt())['review']['verdict'])->toBe('pass');
    };
    $result = $service->dispatch($this->landing, $this->bundle, true);
    expect($result['state'])->toBe('completed')->and($result['review']['verdict'])->toBe('pass')
        ->and($this->agent->prompts)->toHaveCount(1);
})->with([false, true]);

it('rejects altered retained files after dispatch without accepting or trusting a verdict', function (string $change, bool $completed) {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $id = $service->dispatch($this->landing, $this->bundle, true)['operation_id'];
    $receipt = snapshotReviewReceipt();
    if ($completed) {
        $service->submit($id, $receipt);
    }
    $operation = TaskCloseoutOperation::query()->findOrFail($id);
    $before = $operation->getRawOriginal();
    $path = $operation->preflight['bundle_path'];
    match ($change) {
        'contents' => File::append($path, ' '),
        'deleted' => File::delete($path),
        'public' => chmod($path, 0644),
        'symlink' => (function () use ($path) {
            File::move($path, $path.'.original');
            symlink($path.'.original', $path);
        })(),
    };
    expect(fn () => $service->submit($id, $receipt))->toThrow(Exception::class)
        ->and(fn () => $service->dispatch($this->landing, $this->bundle, true))->toThrow(Exception::class)
        ->and($operation->fresh()->getRawOriginal())->toBe($before)->and($this->agent->prompts)->toHaveCount(1);
})->with(['contents', 'deleted', 'public', 'symlink'])->with([false, true]);

it('rejects stale conflicting malformed or secret-bearing receipts without recording a verdict', function (string $defect) {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $id = $service->dispatch($this->landing, $this->bundle, true)['operation_id'];
    $receipt = snapshotReviewReceipt();
    match ($defect) {
        'token' => $receipt['token'] = 'different-token',
        'assignment' => $receipt['assignment'] = (string) Str::uuid(),
        'bundle' => $receipt['bundle_hash'] = str_repeat('1', 64),
        'verdict' => $receipt['verdict'] = 'complete',
        'extra' => $receipt['unexpected'] = true,
        'secret' => $receipt['evidence'] .= $receipt['token'],
        'empty' => $receipt['summary'] = '',
        'reviewer' => $this->agent->changed = true,
    };
    expect(fn () => $service->submit($id, $receipt))->toThrow(Exception::class)
        ->and(TaskCloseoutOperation::query()->findOrFail($id)->result)->toBeNull();
})->with(['token', 'assignment', 'bundle', 'verdict', 'extra', 'secret', 'empty', 'reviewer']);

it('acknowledges exact duplicate verdicts and refuses replacement or stale submissions', function () {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $id = $service->dispatch($this->landing, $this->bundle, true)['operation_id'];
    $receipt = snapshotReviewReceipt('revise');
    $service->submit($id, $receipt);
    $before = TaskCloseoutOperation::query()->findOrFail($id)->getRawOriginal();
    expect($service->submit($id, $receipt)['applied'])->toBeFalse()
        ->and(TaskCloseoutOperation::query()->findOrFail($id)->getRawOriginal())->toBe($before)
        ->and(fn () => $service->submit($id, [...$receipt, 'verdict' => 'pass']))
        ->toThrow(LogicException::class, 'cannot be replaced');
    $service->dispatch($this->landing, [...$this->bundle, 'observations' => 'Corrected observation.'], true);
    expect(fn () => $service->submit($id, $receipt))->toThrow(LogicException::class, 'current frozen observation');
    expect(fn () => $service->dispatch($this->landing, $this->bundle, true))->toThrow(LogicException::class, 'latest postinstall');
});

it('does not adopt changed redirected or public retained review files', function (string $defect) {
    $hash = TaskLandingData::hash($this->bundle);
    $path = storage_path('app/private/task-snapshot-reviews/'.$this->landing->id.'/'.$hash.'.json');
    File::makeDirectory(dirname($path), 0700, true);
    File::put($path, TaskLandingData::json($this->bundle));
    chmod($path, 0600);
    match ($defect) {
        'contents' => File::append($path, ' '),
        'public' => chmod($path, 0644),
        'directory' => chmod(dirname($path), 0755),
        'symlink' => (function () use ($path) {
            File::move($path, $path.'.original');
            symlink($path.'.original', $path);
        })(),
    };
    expect(fn () => app(ReviewTaskSnapshotReacquisition::class)->dispatch($this->landing, $this->bundle, true))
        ->toThrow(Exception::class)->and($this->agent->prompts)->toBe([])
        ->and(TaskCloseoutOperation::query()->count())->toBe(0);
})->with(['contents', 'public', 'directory', 'symlink']);

it('requires schema two and an approved replacement profile before sending', function (string $defect) {
    if ($defect === 'approval') {
        DB::table('task_landings')->where('id', $this->landing->id)->update(['state' => 'rejected']);
    } elseif ($defect === 'schema') {
        $package = [...$this->landing->package, 'schema' => 1];
        DB::table('task_landings')->where('id', $this->landing->id)->update(['package' => TaskLandingData::json($package),
            'package_hash' => TaskLandingData::hash($package),
            'review_result' => TaskLandingData::json([...$this->landing->review_result, 'package_hash' => TaskLandingData::hash($package)])]);
    } else {
        $workspace = $this->landing->workspace()->firstOrFail();
        $config = $workspace->configuration;
        $config['orbit_profile']['snapshot_replacement'] = false;
        DB::table('task_workspaces')->where('id', $workspace->id)->update(['configuration' => TaskLandingData::json($config)]);
    }
    expect(fn () => app(ReviewTaskSnapshotReacquisition::class)->dispatch($this->landing->fresh(), $this->bundle, true))
        ->toThrow(LogicException::class)->and($this->agent->prompts)->toBe([]);
})->with(['approval', 'schema', 'profile']);

it('accepts CLI handoff only from the assigned feature worktree and never completes the issue', function () {
    $service = app(ReviewTaskSnapshotReacquisition::class);
    $id = $service->dispatch($this->landing, $this->bundle, true)['operation_id'];
    $file = $this->reviewDirectory.'/handoff.json';
    File::put($file, TaskLandingData::json(snapshotReviewReceipt()));
    chmod($file, 0600);
    expect(Artisan::call('tasks:snapshot-review-submit', ['operation' => $id, '--file' => $file]))->toBe(1)
        ->and(TaskCloseoutOperation::query()->findOrFail($id)->result)->toBeNull();
    $cwd = getcwd();
    try {
        chdir($this->landing->workspace()->firstOrFail()->worktree);
        expect(Artisan::call('tasks:snapshot-review-submit', ['operation' => $id, '--file' => $file]))->toBe(0)
            ->and(Artisan::output())->toContain('Postinstall review recorded: pass');
    } finally {
        chdir($cwd);
    }
    expect(TaskCloseoutOperation::query()->count())->toBe(1)
        ->and($this->fixture->issues[$this->landing->issue_id]['state']['name'])->toBe('In Review');
    Process::assertNothingRan();
});

it('preserves exact historical schema one approval text', function () {
    $landing = $this->fixture->add(249);
    $expected = "Approved.\n\nPublication of the retained independent Commander Tasks package review; not a new verdict.\n"
        .'Package SHA256: '.$landing->package_hash."\nCandidate: ".$landing->candidate_sha
        ."\nArtifact: ".$landing->artifact_sha."\nReview assignment: ".$landing->review_assignment
        ."\nReview SHA256: ".TaskLandingData::hash($landing->review_result)
        ."\nPR title SHA256: ".hash('sha256', $landing->package['title'])
        ."\nPR body SHA256: ".hash('sha256', $landing->package['body'])."\n";
    expect(app(TaskCloseoutContext::class)->approvalMarker($landing))->toBe($expected);
});
