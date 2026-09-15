<?php

use App\Models\TaskAgentDispatch;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskMainHold;
use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Closeout\TaskCloseoutLock;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\Closeout\TaskMainHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskCloseoutFakeRemote;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    Process::preventStrayProcesses();
    Http::preventStrayRequests();
    Queue::fake();
    $this->closeoutDirectory = storage_path('framework/testing/closeout-'.bin2hex(random_bytes(8)));
    $this->fixture = new TaskCloseoutFixture($this->closeoutDirectory);
    $this->remote = new TaskCloseoutFakeRemote;
    app()->instance(TaskCloseoutGitHub::class, $this->remote);
    app()->instance(TaskCloseoutRepository::class, $this->remote);
    File::put($this->closeoutDirectory.'/evidence.txt', 'Actual relevant case results for the exact pinned main.');
});

afterEach(fn () => File::deleteDirectory($this->closeoutDirectory));

function closeoutStage(TaskLanding $landing, string $stage, bool $apply = true): array
{
    return app(CloseTaskLanding::class)->handle($landing->id, $landing->package_hash, $stage, true, $apply);
}

function closeoutHoldRequest(array $extra = []): array
{
    $test = test();

    return ['repository' => $test->closeoutDirectory.'/repository', 'main_sha' => $test->remote->mainSha,
        'incident' => 'known-main-cases', 'relevant_cases' => ['case-one', 'case-two'],
        'attestation' => 'I inspected the exact retained case results, not just the cached summary.',
        'evidence_file' => $test->closeoutDirectory.'/evidence.txt',
        'evidence_sha256' => hash('sha256', File::get($test->closeoutDirectory.'/evidence.txt')), ...$extra];
}

function closeoutImport(): TaskMainHold
{
    $result = app(TaskMainHoldService::class)->handle('import', closeoutHoldRequest(), true, true);

    return TaskMainHold::query()->findOrFail($result['hold']['id']);
}

function closeoutAuthorize(TaskMainHold $hold, TaskLanding $landing): void
{
    app(TaskMainHoldService::class)->handle('repair', closeoutHoldRequest(['hold_id' => $hold->id,
        'landing_id' => $landing->id, 'package_hash' => $landing->package_hash,
        'retained_holds' => TaskMainHold::query()->whereNull('clearance')->whereKeyNot($hold->id)->orderBy('id')->get()
            ->map(fn (TaskMainHold $other): array => ['id' => $other->id, 'evidence_hash' => $other->evidence_hash])->all()]), true, true);
}

function closeoutClearRequest(TaskMainHold $hold, TaskLanding $landing): array
{
    $test = test();

    return closeoutHoldRequest(['hold_id' => $hold->id, 'landing_id' => $landing->id, 'package_hash' => $landing->package_hash,
        'merge_sha' => $test->remote->merges[$landing->id]['merge_sha'], 'verification_exit_code' => 0,
        'checks' => array_map(fn (string $case): array => ['case' => $case, 'command' => 'composer test -- --filter='.$case,
            'cwd' => $test->closeoutDirectory.'/repository', 'main_sha' => $test->remote->mainSha,
            'executed' => true, 'selected' => true, 'cached' => false, 'exit_code' => 0], ['case-one', 'case-two'])]);
}

function closeoutWithdrawRequest(TaskMainHold $hold, array $extra = []): array
{
    return [...array_intersect_key(closeoutHoldRequest(), array_flip(['repository', 'main_sha', 'evidence_file', 'evidence_sha256'])),
        'hold_id' => $hold->id, 'hold_hash' => $hold->evidence_hash,
        'attestation' => 'The user canceled this unused reporting requirement; withdrawal does not claim any repair or passing tests.', ...$extra];
}

it('previews without writes or another reviewer dispatch', function () {
    $landing = $this->fixture->add();
    $workspace = $landing->workspace()->firstOrFail();
    $before = [$landing->toArray(), $workspace->toArray(), $workspace->root()->firstOrFail()->toArray()];
    expect(closeoutStage($landing, 'publish', false)['applied'])->toBeFalse()
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)->and($this->remote->effects)->toBe([])
        ->and([$landing->fresh()->toArray(), $workspace->fresh()->toArray(), $workspace->root()->firstOrFail()->toArray()])->toBe($before)
        ->and(TaskAgentDispatch::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('publishes merges verifies and releases exact reviewed work without changing acceptance history', function () {
    $landing = $this->fixture->add();
    $before = $landing->toArray();
    foreach (['publish', 'merge', 'verify', 'release'] as $stage) {
        closeoutStage($landing, $stage);
        closeoutStage($landing, $stage);
    }
    expect($this->remote->effects)->toBe(['branch', 'publication', 'approval', 'reservation', 'merge', 'verify', 'verify', 'release'])
        ->and($landing->fresh()->toArray())->toBe($before)->and(TaskAgentDispatch::query()->count())->toBe(1)
        ->and(TaskCloseoutOperation::query()->where('operation', 'merge')->sole()->preflight['main']['main_sha'])->toBe($this->remote->mainSha);
    Queue::assertNothingPushed();
});

it('reconciles successful writes with lost responses without resending', function (string $lost) {
    $landing = $this->fixture->add();
    $this->remote->lost = [$lost];
    foreach (['publish', 'merge', 'verify', 'release'] as $stage) {
        closeoutStage($landing, $stage);
    }
    expect(count(array_filter($this->remote->effects, fn (string $effect): bool => $effect === $lost)))->toBe(1)
        ->and(TaskCloseoutOperation::query()->where('state', 'unknown')->count())->toBe(0);
})->with(['branch', 'publication', 'approval', 'reservation', 'merge', 'release']);

it('retains an uncertain write and never automatically retries it', function () {
    $landing = $this->fixture->add();
    $this->remote->invisible = ['publication'];
    $this->remote->lost = ['publication'];
    expect(fn () => closeoutStage($landing, 'publish'))->toThrow(LogicException::class, 'uncertain');
    expect(fn () => closeoutStage($landing, 'publish'))->toThrow(LogicException::class, 'uncertain');
    expect($this->remote->effects)->toBe(['branch', 'publication'])
        ->and(TaskCloseoutOperation::query()->where('operation', 'publication')->sole()->state)->toBe('unknown');
});

it('does not report an old successful release after the same reservation is reacquired', function () {
    $landing = $this->fixture->add();
    foreach (['publish', 'merge', 'verify', 'release'] as $stage) {
        closeoutStage($landing, $stage);
    }
    $this->remote->reserved = ['url' => $this->remote->publications[$landing->id]['url']];
    expect(fn () => closeoutStage($landing, 'release'))->toThrow(LogicException::class, 'acquired again')
        ->and(count(array_filter($this->remote->effects, fn (string $effect): bool => $effect === 'release')))->toBe(1)
        ->and($this->remote->reserved)->not->toBeNull();
});

it('keeps pre-write inspection failures retryable and records intent immediately before the only write', function () {
    $landing = $this->fixture->add();
    $ledger = app(TaskCloseoutLedger::class);
    $writes = 0;
    $observed = null;
    expect(fn () => $ledger->step($landing, 'probe', ['exact' => true], fn () => throw new RuntimeException('Read unavailable'), fn () => null))
        ->toThrow(RuntimeException::class);
    expect(TaskCloseoutOperation::query()->where('operation', 'probe')->sole()->state)->toBe('prepared');
    $result = $ledger->step($landing, 'probe', ['exact' => true], function () use (&$observed) {
        return $observed;
    }, function () use (&$observed, &$writes) {
        expect(TaskCloseoutOperation::query()->where('operation', 'probe')->sole()->state)->toBe('intended');
        $writes++;
        $observed = ['exact' => 'result'];
    });
    expect($result)->toBe(['exact' => 'result'])->and($writes)->toBe(1);
    expect(fn () => $ledger->step($landing, 'probe', ['exact' => false], fn () => $result, fn () => null))->toThrow(LogicException::class);
});

it('refuses changed PR or approval readback before any merge', function (string $drift) {
    $landing = $this->fixture->add();
    closeoutStage($landing, 'publish');
    if ($drift === 'PR') {
        $this->remote->publications[$landing->id]['body_hash'] = str_repeat('0', 64);
    } else {
        $this->remote->approvals[$landing->id]['review_id']++;
    }
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class)
        ->and($this->remote->effects)->toBe(['branch', 'publication', 'approval']);
})->with(['PR', 'approval']);

it('rechecks main after reservation and retains a pre-write hold if new failure appears', function () {
    $landing = $this->fixture->add();
    closeoutStage($landing, 'publish');
    $this->remote->beforeWrite = function (string $name): void {
        if ($name === 'reservation') {
            $this->remote->failures = ['apps/e2e' => ['new-failure']];
        }
    };
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class)
        ->and($this->remote->merges)->toBe([])
        ->and(TaskCloseoutOperation::query()->where('operation', 'merge')->sole()->state)->toBe('prepared');
});

it('allows only the exact newly attached own PR without accepting broader issue drift', function () {
    $landing = $this->fixture->add();
    closeoutStage($landing, 'publish');
    $pr = $this->remote->publications[$landing->id];
    $this->fixture->issues[$landing->issue_id]['attachments']['nodes'][] = ['title' => $landing->package['title'], 'url' => $pr['url']];
    expect(app(TaskCloseoutContext::class)->observe($landing, $pr)->id)->toBe($landing->task_workspace_id);
    $this->fixture->issues[$landing->issue_id]['description'] .= ' A new requirement.';
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class);
});

it('uses the approved package title for closeout when the Linear title differs', function (string $change) {
    $landing = $this->fixture->add(242, issueTitle: 'Use Orbit VPN DNS by default on managed peers');
    closeoutStage($landing, 'publish');
    $pr = $this->remote->publications[$landing->id];
    $issue = &$this->fixture->issues[$landing->issue_id];
    expect($landing->package['title'])->not->toBe('ORB-242: '.$issue['title']);
    $issue['attachments']['nodes'][] = ['title' => $landing->package['title'], 'url' => $pr['url']];
    match ($change) {
        'none' => null,
        'title' => $issue['attachments']['nodes'][0]['title'] = 'ORB-242: '.$issue['title'],
        'url' => $issue['attachments']['nodes'][0]['url'] = 'https://github.com/nckrtl/orbit/pull/9999',
        'duplicate' => $issue['attachments']['nodes'][] = $issue['attachments']['nodes'][0],
        'description' => $issue['description'] .= ' A new requirement.',
        'labels' => $issue['labels']['nodes'][] = ['name' => 'apps:cli'],
        'other attachment' => $issue['attachments']['nodes'][] = ['title' => 'Other', 'url' => 'https://github.com/nckrtl/orbit/pull/9999'],
    };

    if ($change === 'none') {
        expect(closeoutStage($landing, 'merge', false)['applied'])->toBeFalse();
        closeoutStage($landing, 'merge');
        expect($this->remote->merges)->toHaveCount(1);
    } else {
        expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class, 'contract')
            ->and($this->remote->merges)->toBe([])
            ->and($this->remote->effects)->toBe(['branch', 'publication', 'approval']);
    }
})->with(['none', 'title', 'url', 'duplicate', 'description', 'labels', 'other attachment']);

it('imports a durable project hold before any candidate exists and keeps cached green from clearing it', function () {
    $hold = closeoutImport();
    expect(TaskLanding::query()->count())->toBe(0)->and($hold->clearance)->toBeNull();
    $landing = $this->fixture->add(248);
    closeoutStage($landing, 'publish');
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class, 'unrelated')
        ->and($hold->fresh()->clearance)->toBeNull()->and($this->remote->merges)->toBe([]);
});

it('allows several exact reviewed repair packages while holding unrelated work', function () {
    $this->remote->failures = ['apps/e2e' => ['case-one' => 'failed']];
    $hold = closeoutImport();
    $first = $this->fixture->add(249);
    $second = $this->fixture->add(250);
    $unrelated = $this->fixture->add(248);
    closeoutAuthorize($hold, $first);
    $initial = $hold->fresh()->repairs[0];
    closeoutAuthorize($hold, $second);
    expect($hold->fresh()->repairs)->toHaveCount(2)->and($hold->fresh()->repairs[0])->toBe($initial);
    foreach ([$first, $second] as $repair) {
        if ($repair->id === $second->id) {
            $this->remote->mainSha = sha1('main after first repair');
            $this->remote->failures = ['apps/e2e' => ['case-two' => 'failed']];
            closeoutAuthorize($hold, $repair);
        }
        closeoutStage($repair, 'publish');
        closeoutStage($repair, 'merge');
        closeoutStage($repair, 'verify');
        closeoutStage($repair, 'release');
    }
    closeoutStage($unrelated, 'publish');
    expect(fn () => closeoutStage($unrelated, 'merge'))->toThrow(LogicException::class, 'unrelated')
        ->and($hold->fresh()->clearance)->toBeNull()->and($this->remote->merges)->toHaveCount(2);
    expect($hold->fresh()->repairs)->toHaveCount(3)->and($hold->fresh()->repairs[0])->toBe($initial);
});

it('permits only the named repair while explicitly retaining other exact incident holds', function () {
    $own = closeoutImport();
    $other = app(TaskMainHoldService::class)->handle('import', closeoutHoldRequest(['incident' => 'another-known-defect']), true, true);
    $landing = $this->fixture->add(249);
    expect(fn () => app(TaskMainHoldService::class)->handle('repair', closeoutHoldRequest(['hold_id' => $own->id,
        'landing_id' => $landing->id, 'package_hash' => $landing->package_hash]), true, true))->toThrow(LogicException::class, 'acknowledge');
    closeoutAuthorize($own, $landing);
    closeoutStage($landing, 'publish');
    closeoutStage($landing, 'merge');
    $preflight = TaskCloseoutOperation::query()->where('operation', 'merge')->sole()->preflight;
    expect($preflight['main']['repair_incidents'][0]['id'])->toBe($own->id)
        ->and($preflight['main']['retained_incidents'])->toBe([['id' => $other['hold']['id'], 'evidence_hash' => $other['hold']['evidence_hash']]])
        ->and(TaskMainHold::query()->whereNull('clearance')->count())->toBe(2);
});

it('refuses new native failures and every unrecognized open incident', function (bool $secondHold) {
    $landing = $this->fixture->add();
    $hold = closeoutImport();
    closeoutAuthorize($hold, $landing);
    if ($secondHold) {
        app(TaskMainHoldService::class)->handle('import', closeoutHoldRequest(['incident' => 'other-incident']), true, true);
    } else {
        $this->remote->failures = ['apps/cli' => ['new-case' => 'failed']];
    }
    closeoutStage($landing, 'publish');
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class)
        ->and($this->remote->merges)->toBe([]);
})->with([false, true]);

it('requires a fresh append-only authorization after main advances even with unchanged failure metadata', function () {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    $this->remote->mainSha = sha1('new authoritative main');
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class)
        ->and($this->remote->merges)->toBe([]);
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'merge');
    expect($hold->fresh()->repairs)->toHaveCount(2);
});

it('requires fresh authorization when failed metadata becomes cached green on the same main', function () {
    $this->remote->failures = ['apps/gateway' => ['known-case' => 'failed']];
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    $this->remote->failures = [];
    expect(fn () => closeoutStage($landing, 'merge'))->toThrow(LogicException::class)
        ->and($hold->fresh()->clearance)->toBeNull()->and($this->remote->merges)->toBe([]);
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'merge');
    expect($hold->fresh()->repairs)->toHaveCount(2)->and($hold->fresh()->clearance)->toBeNull();
});

it('clears only after explicit passing relevant-case evidence on main containing the recorded repair', function () {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    closeoutStage($landing, 'merge');
    closeoutStage($landing, 'verify');
    $request = closeoutClearRequest($hold, $landing);
    expect(app(TaskMainHoldService::class)->handle('clear', $request, true)['applied'])->toBeFalse()
        ->and($hold->fresh()->clearance)->toBeNull();
    app(TaskMainHoldService::class)->handle('clear', $request, true, true);
    expect($hold->fresh()->clearance['checks'])->toBe($request['checks'])
        ->and(fn () => $hold->fresh()->update(['evidence' => []]))->toThrow(LogicException::class)
        ->and(fn () => $hold->fresh()->delete())->toThrow(LogicException::class);
});

it('refuses cached skipped failed missing or wrong-main case proof', function (string $defect) {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    closeoutStage($landing, 'merge');
    closeoutStage($landing, 'verify');
    $request = closeoutClearRequest($hold, $landing);
    match ($defect) {
        'cached' => $request['checks'][0]['cached'] = true,
        'unselected' => $request['checks'][0]['selected'] = false,
        'unexecuted' => $request['checks'][0]['executed'] = false,
        'failed' => $request['checks'][0]['exit_code'] = 1,
        'wrong-main' => $request['checks'][0]['main_sha'] = sha1('other-main'),
        'missing' => $request['checks'] = [],
        'ancestry' => $this->remote->containsMerge = false,
        'native-failure' => $this->remote->failures = ['apps/cli' => ['failure']],
        'red-command' => $request['verification_exit_code'] = 1,
    };
    expect(fn () => app(TaskMainHoldService::class)->handle('clear', $request, true, true))->toThrow(LogicException::class)
        ->and($hold->fresh()->clearance)->toBeNull();
})->with(['cached', 'unselected', 'unexecuted', 'failed', 'wrong-main', 'missing', 'ancestry', 'native-failure', 'red-command']);

it('retains exact repaired-case proof from an overall red run without clearing the incident', function () {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    closeoutStage($landing, 'merge');
    closeoutStage($landing, 'verify');
    $this->remote->failures = ['apps/gateway' => ['other-known-case' => 'failed']];
    $request = closeoutClearRequest($hold, $landing);
    $request['verification_exit_code'] = 1;
    $result = app(TaskMainHoldService::class)->handle('observe', $request, true, true);
    expect($result['attestation']['verification_exit_code'])->toBe(1)
        ->and($result['attestation']['native_failures'])->toBe($this->remote->failures)
        ->and(TaskCloseoutOperation::query()->where('operation', 'like', 'main-proof:%')->count())->toBe(1)
        ->and($hold->fresh()->clearance)->toBeNull();
});

it('requires retained exact discovery lineage before observing or clearing main case proof', function (string $action) {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    closeoutStage($landing, 'publish');
    closeoutStage($landing, 'merge');
    expect(fn () => app(TaskMainHoldService::class)->handle($action, closeoutClearRequest($hold, $landing), true, true))
        ->toThrow(LogicException::class, 'discovery merge lineage')
        ->and($hold->fresh()->clearance)->toBeNull()
        ->and(TaskCloseoutOperation::query()->where('operation', 'like', 'main-proof:%')->count())->toBe(0);
})->with(['observe', 'clear']);

it('previews and withdraws an obsolete hold without a candidate or invented proof and replays inertly', function () {
    $hold = closeoutImport();
    $before = $hold->toArray();
    File::put($this->closeoutDirectory.'/evidence.txt', 'The user canceled the unused reporting requirement. No tests were run for withdrawal.');
    $request = closeoutWithdrawRequest($hold);
    $file = $this->closeoutDirectory.'/withdraw.json';
    File::put($file, json_encode($request, JSON_THROW_ON_ERROR));
    $arguments = ['action' => 'withdraw', '--file' => $file, '--exclusive' => true];

    expect(Artisan::call('tasks:main-hold', $arguments))->toBe(0)
        ->and(json_decode(Artisan::output(), true)['applied'])->toBeFalse()
        ->and($hold->fresh()->toArray())->toBe($before);
    expect(Artisan::call('tasks:main-hold', [...$arguments, '--apply' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true);
    $resolved = $hold->fresh()->toArray();
    expect($result['attestation'])->toBe([
        'resolution' => 'withdrawn', 'hold_id' => $hold->id, 'hold_hash' => $hold->evidence_hash,
        'repository' => $request['repository'], 'observed_main' => $request['main_sha'],
        'attestation' => ['summary' => $request['attestation'], 'file' => $request['evidence_file'],
            'sha256' => $request['evidence_sha256'], 'contents' => File::get($request['evidence_file'])],
    ])->and($resolved['clearance'])->toBe($result['attestation'])
        ->and($resolved['evidence'])->toBe($before['evidence'])
        ->and($resolved['evidence_hash'])->toBe($before['evidence_hash'])
        ->and($resolved['repairs'])->toBe($before['repairs']);

    $this->travel(1)->minute();
    expect(Artisan::call('tasks:main-hold', [...$arguments, '--apply' => true]))->toBe(0)
        ->and($hold->fresh()->toArray())->toBe($resolved)
        ->and(TaskLanding::query()->count())->toBe(0)
        ->and(TaskAgentDispatch::query()->count())->toBe(0)
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)
        ->and($this->remote->effects)->toBe([]);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('requires exclusive enabled runtime and the shared lock for withdrawal writes', function () {
    $hold = closeoutImport();
    $request = closeoutWithdrawRequest($hold);
    $service = app(TaskMainHoldService::class);
    expect(fn () => $service->handle('withdraw', $request, false))->toThrow(LogicException::class, 'exclusive');
    config(['task-runtime.enabled' => false]);
    expect($service->handle('withdraw', $request, true)['applied'])->toBeFalse()
        ->and(fn () => $service->handle('withdraw', $request, true, true))->toThrow(LogicException::class, 'exclusive');
    config(['task-runtime.enabled' => true]);
    app(TaskCloseoutLock::class)->handle(function () use ($service, $request): void {
        expect(fn () => $service->handle('withdraw', $request, true, true))->toThrow(LogicException::class, 'Another coordinator');
    });
    expect($hold->fresh()->clearance)->toBeNull();
});

it('refuses stale identity or changed evidence for withdrawal', function (string $defect) {
    $hold = closeoutImport();
    $request = closeoutWithdrawRequest($hold);
    match ($defect) {
        'hold-id' => $request['hold_id'] = (string) $hold->id,
        'hold-hash' => $request['hold_hash'] = str_repeat('0', 64),
        'missing-hash' => $request['hold_hash'] = null,
        'main' => $request['main_sha'] = sha1('stale main'),
        'repository' => $request['repository'] = $this->closeoutDirectory.'/different-repository',
        'evidence' => File::put($request['evidence_file'], 'Changed evidence.'),
        'attestation' => $request['attestation'] = '',
    };
    expect(fn () => app(TaskMainHoldService::class)->handle('withdraw', $request, true, true))->toThrow(LogicException::class)
        ->and($hold->fresh()->clearance)->toBeNull();
})->with(['hold-id', 'hold-hash', 'missing-hash', 'main', 'repository', 'evidence', 'attestation']);

it('rejects repair and test claims in a withdrawal instead of silently accepting them', function (string $field) {
    $hold = closeoutImport();
    expect(fn () => app(TaskMainHoldService::class)->handle('withdraw', closeoutWithdrawRequest($hold, [$field => null]), true, true))
        ->toThrow(LogicException::class, 'not repair or test claims')
        ->and($hold->fresh()->clearance)->toBeNull();
})->with(['landing_id', 'package_hash', 'candidate_sha', 'merge_sha', 'checks', 'verification_exit_code', 'resolution', 'unknown']);

it('withdraws only the named hold and preserves its repairs and all other incident history', function () {
    $hold = closeoutImport();
    app(TaskMainHoldService::class)->handle('import', closeoutHoldRequest(['incident' => 'still-relevant']), true, true);
    $other = TaskMainHold::query()->where('incident', 'still-relevant')->sole();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    $repairs = $hold->fresh()->repairs;
    $otherBefore = $other->toArray();
    $service = app(TaskMainHoldService::class);
    $service->handle('withdraw', closeoutWithdrawRequest($hold), true, true);
    expect($hold->fresh()->repairs)->toBe($repairs)
        ->and($other->fresh()->toArray())->toBe($otherBefore)
        ->and(TaskMainHold::query()->whereNull('clearance')->pluck('id')->all())->toBe([$other->id])
        ->and(fn () => $service->guardMerge($landing))->toThrow(LogicException::class, 'unrelated')
        ->and(fn () => closeoutAuthorize($hold, $landing))->toThrow(LogicException::class, 'cannot receive');
});

it('does not bypass native failures before withdrawal or later unrelated merges', function () {
    $hold = closeoutImport();
    $request = closeoutWithdrawRequest($hold);
    $service = app(TaskMainHoldService::class);
    $this->remote->failures = ['apps/gateway' => ['still-failing']];
    expect(fn () => $service->handle('withdraw', $request, true))->toThrow(LogicException::class, 'native correctness')
        ->and(fn () => $service->handle('withdraw', $request, true, true))->toThrow(LogicException::class, 'native correctness')
        ->and($hold->fresh()->clearance)->toBeNull();
    $this->remote->failures = [];
    $landing = $this->fixture->add(248);
    expect(fn () => $service->guardMerge($landing))->toThrow(LogicException::class, 'unrelated');
    $service->handle('withdraw', $request, true, true);
    expect($service->guardMerge($landing)['retained_incidents'])->toBe([]);
    $this->remote->failures = ['apps/gateway' => ['new-failure']];
    expect(fn () => $service->guardMerge($landing))->toThrow(LogicException::class, 'native main failures');
});

it('keeps terminal clearance and withdrawal resolutions immutable', function (string $first) {
    $hold = closeoutImport();
    $landing = $this->fixture->add();
    closeoutAuthorize($hold, $landing);
    foreach (['publish', 'merge', 'verify'] as $stage) {
        closeoutStage($landing, $stage);
    }
    $service = app(TaskMainHoldService::class);
    $requests = ['clear' => closeoutClearRequest($hold, $landing), 'withdraw' => closeoutWithdrawRequest($hold)];
    $service->handle($first, $requests[$first], true, true);
    $before = $hold->fresh()->toArray();
    $other = $first === 'clear' ? 'withdraw' : 'clear';
    expect(fn () => $service->handle($other, $requests[$other], true, true))->toThrow(LogicException::class, 'immutable');
    $changed = [...$requests[$first], 'attestation' => 'Changed terminal decision.'];
    expect(fn () => $service->handle($first, $changed, true, true))->toThrow(LogicException::class, 'immutable')
        ->and($hold->fresh()->toArray())->toBe($before);
})->with(['clear', 'withdraw']);

it('exposes explicit CLI previews without queue or source mutations', function () {
    $landing = $this->fixture->add();
    expect(Artisan::call('tasks:closeout', ['landing' => $landing->id, '--package' => $landing->package_hash, '--stage' => 'publish', '--exclusive' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true)['applied'])->toBeFalse()
        ->and(Artisan::call('tasks:closeout', ['landing' => $landing->id, '--package' => $landing->package_hash, '--stage' => 'publish']))->toBe(1)
        ->and(TaskCloseoutOperation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('adds and reverses only closeout tables without modifying accepted tasks', function () {
    $landing = $this->fixture->add();
    $before = [$landing->toArray(), $landing->workspace()->firstOrFail()->root()->firstOrFail()->toArray()];
    $holds = require database_path('migrations/2026_09_12_224418_create_task_main_holds_table.php');
    $operations = require database_path('migrations/2026_09_12_224417_create_task_closeout_operations_table.php');
    $holds->down();
    $operations->down();
    $operations->up();
    $holds->up();
    expect([$landing->fresh()->toArray(), $landing->workspace()->firstOrFail()->root()->firstOrFail()->toArray()])->toBe($before)
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)->and(TaskMainHold::query()->count())->toBe(0);
});
