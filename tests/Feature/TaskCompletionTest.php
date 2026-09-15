<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskMainHold;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Completion\CompleteTaskLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskCompletionFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->completionDirectory = storage_path('framework/testing/completion-'.bin2hex(random_bytes(8)));
    $this->completion = new TaskCompletionFixture($this->completionDirectory);
    $this->landing = $this->completion->add();
});

afterEach(fn () => File::deleteDirectory($this->completionDirectory));

function completionHistory(): array
{
    return [Task::query()->get()->toArray(), TaskRun::query()->get()->toArray(), TaskWorkspace::query()->get()->toArray(),
        TaskAgentDispatch::query()->get()->toArray(), TaskLanding::query()->get()->toArray()];
}

it('previews exact completion without database or external writes', function () {
    $this->completion->land($this->landing);
    $before = [completionHistory(), TaskCloseoutOperation::query()->get()->toArray(), $this->completion->remote->effects];
    $result = $this->completion->finish($this->landing, false);
    expect($result['applied'])->toBeFalse()->and($result['state'])->toBe('eligible')
        ->and($result['current']['operational_closeout']['worktree'])->toBe('not_performed')
        ->and($this->completion->writes)->toBe([])
        ->and([completionHistory(), TaskCloseoutOperation::query()->get()->toArray(), $this->completion->remote->effects])->toBe($before);
    Queue::assertNothingPushed();
});

it('completes only the undelegated issue and retains delivered proof without rewriting acceptance', function () {
    $this->completion->land($this->landing);
    $before = completionHistory();
    $this->completion->base->issues[$this->landing->issue_id]['assignee'] = ['id' => config('commander.hermes.nick_linear_user_id')];
    $result = $this->completion->finish($this->landing);
    expect($result['state'])->toBe('delivered')->and($result['current']['completed_issue']['delegate'])->toBeNull()
        ->and($result['current']['completed_issue']['assignee'])->toBe(['id' => config('commander.hermes.nick_linear_user_id')])
        ->and($this->completion->writes)->toHaveCount(1)->and(completionHistory())->toBe($before)
        ->and(TaskCloseoutOperation::query()->where('operation', 'completion-admission')->sole()->result['full_provenance_guard'])->toBe('passed')
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->sole()->preflight['completed_issue'])->toBeNull()
        ->and(TaskCloseoutOperation::query()->where('operation', 'delivery-complete')->sole()->result)->toBe($result['delivery']);
    expect($this->completion->finish($this->landing)['applied'])->toBeFalse()->and($this->completion->writes)->toHaveCount(1);
});

it('adopts an exact already completed issue without changing its permitted owner', function (bool $assigned) {
    $this->completion->land($this->landing);
    $this->completion->base->issues[$this->landing->issue_id]['state'] = ['id' => $this->completion->done, 'name' => 'Done', 'type' => 'completed'];
    $owner = $assigned ? ['id' => config('commander.hermes.nick_linear_user_id')] : null;
    $this->completion->base->issues[$this->landing->issue_id]['assignee'] = $owner;
    $result = $this->completion->finish($this->landing);
    expect($result['state'])->toBe('delivered')->and($result['current']['completed_issue']['assignee'])->toBe($owner)
        ->and($this->completion->writes)->toBe([])
        ->and($this->completion->finish($this->landing)['delivery'])->toBe($result['delivery']);
})->with([false, true]);

it('uses the approved package title for completion when the Linear title differs', function (string $change) {
    $landing = $this->completion->add(242, issueTitle: 'Use Orbit VPN DNS by default on managed peers');
    $this->completion->land($landing);
    $issue = &$this->completion->base->issues[$landing->issue_id];
    expect($landing->package['title'])->not->toBe('ORB-242: '.$issue['title']);
    $issue['attachments']['nodes'][] = ['title' => $landing->package['title'],
        'url' => $this->completion->remote->publications[$landing->id]['url']];
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
        expect($this->completion->finish($landing, false)['state'])->toBe('eligible')
            ->and($this->completion->writes)->toBe([])
            ->and($this->completion->finish($landing)['state'])->toBe('delivered')
            ->and($this->completion->writes)->toHaveCount(1);
    } else {
        expect(fn () => $this->completion->finish($landing))->toThrow(LogicException::class, 'contract')
            ->and($this->completion->writes)->toBe([])
            ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->count())->toBe(0);
    }
})->with(['none', 'title', 'url', 'duplicate', 'description', 'labels', 'other attachment']);

it('reconciles a lost successful Linear response without resending', function () {
    $this->completion->land($this->landing);
    $this->completion->loseResponse = true;
    expect($this->completion->finish($this->landing)['state'])->toBe('delivered')
        ->and($this->completion->finish($this->landing)['state'])->toBe('delivered')
        ->and($this->completion->writes)->toHaveCount(1);
});

it('never replays an uncertain Linear write even after main advances', function () {
    $this->completion->land($this->landing);
    $this->completion->applyMutation = false;
    $this->completion->loseResponse = true;
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'uncertain');
    $intent = TaskCloseoutOperation::query()->where('operation', 'linear-completion')->sole();
    expect($intent->state)->toBe('unknown');
    $input = $intent->input;
    $this->completion->remote->mainSha = sha1('later main');
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'uncertain')
        ->and($this->completion->writes)->toHaveCount(1)
        ->and($intent->fresh()->input)->toBe($input);
    $this->completion->base->issues[$this->landing->issue_id]['state'] = ['id' => $this->completion->done, 'name' => 'Done', 'type' => 'completed'];
    expect($this->completion->finish($this->landing)['state'])->toBe('delivered')->and($this->completion->writes)->toHaveCount(1);
});

it('keeps completed history stable across main advance and removed worktree or session runtime', function () {
    $this->completion->land($this->landing);
    $first = $this->completion->finish($this->landing);
    File::deleteDirectory($this->landing->workspace()->firstOrFail()->worktree);
    $this->completion->remote->mainSha = sha1('new current main');
    $this->completion->remote->failures = ['apps/gateway' => ['case' => 'new unrelated failure']];
    $result = $this->completion->finish($this->landing);
    expect($result['delivery'])->toBe($first['delivery'])->and($result['current']['main_sha'])->toBe($this->completion->remote->mainSha)
        ->and($result['current']['native_failures'])->toBe($this->completion->remote->failures)
        ->and($this->completion->writes)->toHaveCount(1);
    Process::assertRanTimes(fn ($process): bool => $process->command[0] !== 'git', 0);
});

it('preserves a foreign reservation but refuses its own reacquired reservation and reader errors', function (string $state) {
    $this->completion->land($this->landing);
    $this->completion->finish($this->landing);
    $before = TaskCloseoutOperation::query()->get()->toArray();
    if ($state === 'error') {
        $this->completion->failReservation = true;
        expect(fn () => $this->completion->finish($this->landing))->toThrow(RuntimeException::class, 'inspection');
    } else {
        $this->completion->reservationState = ['status' => $state, 'issue_id' => $state === 'owned' ? $this->landing->issue_id : 'other',
            'url' => 'https://github.com/nckrtl/orbit/pull/777', 'reserved_at' => '2026-09-13T01:00:00Z'];
        if ($state === 'owned') {
            expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'reservation');
        } else {
            expect($this->completion->finish($this->landing)['current']['reservation']['status'])->toBe('foreign');
        }
    }
    expect(TaskCloseoutOperation::query()->get()->toArray())->toBe($before)->and($this->completion->writes)->toHaveCount(1);
})->with(['foreign', 'owned', 'error']);

it('refuses issue ownership contract readiness and state drift before the first mutation', function (string $change) {
    $this->completion->land($this->landing);
    $issue = &$this->completion->base->issues[$this->landing->issue_id];
    match ($change) {
        'delegate' => $issue['delegate'] = ['id' => config('commander.hermes.tom_linear_viewer_id')],
        'foreign assignee' => $issue['assignee'] = ['id' => '99999999-9999-4999-8999-999999999999'],
        'omitted owner' => $issue = array_diff_key($issue, ['delegate' => null]),
        'title' => $issue['title'] .= ' changed',
        'description' => $issue['description'] .= ' changed',
        'team' => $issue['team']['id'] = '99999999-9999-4999-8999-999999999999',
        'done state missing' => $issue['team']['states']['nodes'] = [],
        'done state duplicate' => $issue['team']['states']['nodes'][] = $issue['team']['states']['nodes'][0],
        'backlog' => $issue['state'] = ['id' => $issue['state']['id'], 'name' => 'Backlog', 'type' => 'backlog'],
        'partial done' => $issue['state'] = ['id' => $this->completion->done, 'name' => 'Done', 'type' => 'started'],
        'truncated' => $issue['attachments']['pageInfo']['hasNextPage'] = true,
        'child' => $issue['children']['nodes'][] = ['id' => '99999999-9999-4999-8999-999999999999'],
        'blocker' => $issue['inverseRelations']['nodes'][] = ['type' => 'blocks', 'issue' => ['identifier' => 'ORB-123', 'state' => ['type' => 'started']]],
    };
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([])
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->count())->toBe(0);
})->with(['delegate', 'foreign assignee', 'omitted owner', 'title', 'description', 'team', 'done state missing',
    'done state duplicate', 'backlog', 'partial done', 'truncated', 'child', 'blocker']);

it('permits only the exact own PR attachment contract addition', function (bool $valid) {
    $this->completion->land($this->landing);
    $this->completion->base->issues[$this->landing->issue_id]['attachments']['nodes'][] = ['title' => $this->landing->package['title'],
        'url' => $valid ? $this->completion->remote->publications[$this->landing->id]['url'] : 'https://github.com/nckrtl/orbit/pull/9999'];
    if ($valid) {
        expect($this->completion->finish($this->landing)['state'])->toBe('delivered');
    } else {
        expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'contract')
            ->and($this->completion->writes)->toBe([]);
    }
})->with([false, true]);

it('does not hide reopened or changed terminal state behind a completed ledger result', function (string $change) {
    $this->completion->land($this->landing);
    $this->completion->finish($this->landing);
    $before = TaskCloseoutOperation::query()->get()->toArray();
    match ($change) {
        'reopened' => $this->completion->base->issues[$this->landing->issue_id]['state'] = ['id' => $this->completion->done, 'name' => 'In Review', 'type' => 'started'],
        'requirements' => $this->completion->base->issues[$this->landing->issue_id]['description'] .= ' New acceptance.',
        'artifact' => $this->completion->artifactMissing = true,
        'main' => $this->completion->remote->containsMerge = false,
        'merge' => $this->completion->remote->merges[$this->landing->id]['merge_sha'] = sha1('changed merge'),
        'pr' => $this->completion->remote->publications[$this->landing->id]['body_hash'] = hash('sha256', 'changed wording'),
    };
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toHaveCount(1)->and(TaskCloseoutOperation::query()->get()->toArray())->toBe($before);
})->with(['reopened', 'requirements', 'artifact', 'main', 'merge', 'pr']);

it('requires exact merge lineage release and approval evidence', function (string $stage) {
    $this->completion->land($this->landing);
    $query = DB::table('task_closeout_operations');
    $stage === 'verify' ? $query->where('operation', 'like', 'verify:%')->delete() : $query->where('operation', $stage)->delete();
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([]);
})->with(['publication', 'approval', 'merge', 'verify', 'release']);

it('retains main incidents and refuses missing or overall-red repair acceptance', function (string $proof) {
    $hold = $this->completion->hold();
    $other = $this->completion->hold('other-incident');
    $this->completion->remote->failures = ['apps/gateway' => ['unrelated' => 'still red']];
    $this->completion->authorize($hold, $this->landing);
    $this->completion->land($this->landing);
    if ($proof !== 'missing') {
        $this->completion->prove($hold, $this->landing, $proof === 'red' ? 1 : 0);
    }
    $before = TaskMainHold::query()->get()->toArray();
    if ($proof === 'green') {
        $result = $this->completion->finish($this->landing);
        expect($result['current']['open_main_incidents'])->toHaveCount(2)
            ->and($result['current']['repair_proofs'][0]['hold_id'])->toBe($hold->id)
            ->and($result['current']['native_failures'])->toBe($this->completion->remote->failures);
    } else {
        expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'overall zero-exit')
            ->and($this->completion->writes)->toBe([]);
    }
    expect(TaskMainHold::query()->get()->toArray())->toBe($before)->and($other->fresh()->clearance)->toBeNull();
})->with(['missing', 'red', 'green']);

it('keeps a read failure before intent safely retryable', function () {
    $this->completion->land($this->landing);
    $this->completion->failRead = true;
    expect(fn () => $this->completion->finish($this->landing))->toThrow(RuntimeException::class)
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->count())->toBe(0);
    $this->completion->failRead = false;
    expect($this->completion->finish($this->landing)['state'])->toBe('delivered')->and($this->completion->writes)->toHaveCount(1);
});

it('retains unknown when readback fails and reconciles it without a second mutation', function () {
    $this->completion->land($this->landing);
    $this->completion->afterMutation = function () {
        $this->completion->failRead = true;
    };
    expect(fn () => $this->completion->finish($this->landing))->toThrow(RuntimeException::class)
        ->and(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->sole()->state)->toBe('unknown');
    $this->completion->failRead = false;
    expect($this->completion->finish($this->landing)['state'])->toBe('delivered')->and($this->completion->writes)->toHaveCount(1);
});

it('recovers a failed local delivered save after Linear Done without replaying the write', function () {
    $this->completion->land($this->landing);
    $fail = true;
    TaskCloseoutOperation::saving(function (TaskCloseoutOperation $operation) use (&$fail) {
        if ($fail && $operation->operation === 'delivery-complete' && $operation->result !== null) {
            $fail = false;
            throw new RuntimeException('Injected terminal save interruption.');
        }
    });
    expect(fn () => $this->completion->finish($this->landing))->toThrow(RuntimeException::class, 'terminal save');
    $this->completion->remote->mainSha = sha1('main after local save failure');
    expect($this->completion->finish($this->landing)['state'])->toBe('delivered')->and($this->completion->writes)->toHaveCount(1);
});

it('detects changed accepted database evidence after delivery without needing a live worktree', function () {
    $this->completion->land($this->landing);
    $this->completion->finish($this->landing);
    File::deleteDirectory($this->landing->workspace()->firstOrFail()->worktree);
    DB::table('tasks')->where('id', $this->landing->workspace()->firstOrFail()->root_task_id)->update(['title' => 'Changed accepted brief']);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toHaveCount(1);
});

it('exposes explicit CLI preview and refuses disabled writes', function () {
    $this->completion->land($this->landing);
    expect(Artisan::call('tasks:complete', ['landing' => $this->landing->id, '--package' => $this->landing->package_hash, '--exclusive' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['applied'])->toBeFalse();
    config(['task-runtime.enabled' => false]);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class, 'enable')
        ->and(fn () => app(CompleteTaskLanding::class)->handle($this->landing->id, $this->landing->package_hash, false))->toThrow(LogicException::class, 'exclusive');
});

it('pins permitted ownership before intent and refuses drift even between allowed owners', function (string $timing) {
    $this->completion->land($this->landing);
    $change = function () {
        $this->completion->base->issues[$this->landing->issue_id]['assignee'] = ['id' => config('commander.hermes.nick_linear_user_id')];
    };
    if ($timing === 'before intent') {
        $this->completion->beforeRead = function ($landing, int $read) use ($change) {
            if ($read === 3) {
                $change();
            }
        };
    } elseif ($timing === 'after write') {
        $this->completion->afterMutation = $change;
    } else {
        $this->completion->finish($this->landing);
        $change();
    }
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toHaveCount($timing === 'before intent' ? 0 : 1);
    if ($timing === 'after write') {
        expect(TaskCloseoutOperation::query()->where('operation', 'linear-completion')->sole()->state)->toBe('unknown');
        expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
            ->and($this->completion->writes)->toHaveCount(1);
    }
})->with(['before intent', 'after write', 'terminal repeat']);

it('rejects inconsistent retained admission and delivered records without another external write', function (string $field) {
    $this->completion->land($this->landing);
    $this->completion->finish($this->landing);
    $name = str_starts_with($field, 'admission:') ? 'completion-admission' : 'delivery-complete';
    $operation = TaskCloseoutOperation::query()->where('operation', $name)->sole();
    $result = $operation->result;
    match ($field) {
        'admission:binding' => $result['binding']['database_hash'] = str_repeat('0', 64),
        'admission:guard' => $result['full_provenance_guard'] = 'skipped',
        'admission:source' => $result['acceptance_guard_sha256'] = 'unknown',
        'package' => $result['package_hash'] = str_repeat('0', 64),
        'admission_hash' => $result['admission_hash'] = str_repeat('0', 64),
        'linear_operation_id' => $result['linear_operation_id']++,
        'linear_result_hash' => $result['linear_result_hash'] = str_repeat('0', 64),
        'observation_hash' => $result['observation_hash'] = str_repeat('0', 64),
        'state' => $result['state'] = 'eligible',
    };
    DB::table('task_closeout_operations')->where('id', $operation->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
    expect(fn () => $this->completion->finish($this->landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toHaveCount(1);
})->with(['admission:binding', 'admission:guard', 'admission:source', 'package', 'admission_hash',
    'linear_operation_id', 'linear_result_hash', 'observation_hash', 'state']);

it('keeps real admitted reattempt completion usable after removing its worktree without replaying Git provenance', function (bool $interrupted) {
    $landing = $this->completion->reattempt();
    $this->completion->land($landing);
    expect($landing->inputs['reattempt_proof']['candidate'])->toBe($landing->candidate_sha);
    if ($interrupted) {
        $this->completion->afterMutation = function () {
            $this->completion->failRead = true;
        };
        expect(fn () => $this->completion->finish($landing))->toThrow(RuntimeException::class, 'read unavailable');
        $this->completion->failRead = false;
    } else {
        $first = $this->completion->finish($landing);
    }
    $workspace = $landing->workspace()->firstOrFail();
    $this->completion->git($workspace->repository, ['worktree', 'remove', $workspace->worktree]);
    $calls = $this->completion->localCalls;
    $before = completionHistory();
    $result = $this->completion->finish($landing);
    expect($result['state'])->toBe('delivered')->and($this->completion->localCalls)->toBe($calls)
        ->and($this->completion->writes)->toHaveCount(1)->and(completionHistory())->toBe($before);
    if (! $interrupted) {
        expect($result['delivery'])->toBe($first['delivery']);
    }
})->with([false, true]);

it('requires the real reattempt Git proof before first completion admission', function () {
    $landing = $this->completion->reattempt();
    $this->completion->land($landing);
    $workspace = $landing->workspace()->firstOrFail();
    File::put($workspace->worktree.'/feature.txt', 'Unreviewed change after closeout.');
    expect(fn () => $this->completion->finish($landing))->toThrow(LogicException::class)
        ->and($this->completion->writes)->toBe([])
        ->and(TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'completion-admission')->exists())->toBeFalse();
});

it('still requires retained reattempt evidence outside a legitimately removed worktree', function (bool $missing) {
    $landing = $this->completion->reattempt();
    $this->completion->land($landing);
    $this->completion->finish($landing);
    $workspace = $landing->workspace()->firstOrFail();
    $this->completion->git($workspace->repository, ['worktree', 'remove', $workspace->worktree]);
    $file = $this->completionDirectory.'/review.log';
    $missing ? File::delete($file) : File::put($file, 'Changed retained prerequisite review.');
    expect(fn () => $this->completion->finish($landing))->toThrow(InvalidArgumentException::class)
        ->and($this->completion->writes)->toHaveCount(1);
})->with([false, true]);
