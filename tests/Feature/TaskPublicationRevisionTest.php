<?php

use App\Models\TaskAgentDispatch;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskRun;
use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Closeout\NativeTaskCloseoutGitHub;
use App\Tasks\Closeout\NativeTaskCloseoutRepository;
use App\Tasks\Closeout\ReviseTaskPublication;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->revisionDirectory = storage_path('framework/testing/publication-revision-'.bin2hex(random_bytes(8)));
    $this->revisionFixture = new TaskCloseoutFixture($this->revisionDirectory);
    $this->revisionLanding = $this->revisionFixture->add(200);
    $this->revisionOldHead = $this->revisionLanding->workspace()->firstOrFail()->base_sha;
    $this->revisionRemoteHead = $this->revisionOldHead;
    $this->revisionOldBody = 'Retained legacy PR evidence. The reviewer requested one correction.';
    $this->revisionPr = ['number' => 298, 'html_url' => 'https://github.com/nckrtl/orbit/pull/298',
        'title' => $this->revisionLanding->package['title'], 'body' => $this->revisionOldBody,
        'state' => 'open', 'draft' => false, 'merged' => false, 'mergeable' => true,
        'head' => ['sha' => $this->revisionOldHead, 'ref' => 'orb-200', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'user' => ['login' => 'nckrtl', 'type' => 'User']];
    $this->revisionReviews = [['id' => 100, 'commit_id' => $this->revisionOldHead, 'state' => 'CHANGES_REQUESTED',
        'body' => 'Correct the bounded issue.', 'user' => ['login' => 'tom-nckrtl[bot]', 'type' => 'Bot']]];
    $this->revisionRequest = ['number' => 298, 'url' => $this->revisionPr['html_url'], 'repository' => 'nckrtl/orbit',
        'author_login' => 'nckrtl', 'author_type' => 'User', 'head_ref' => 'orb-200', 'base_ref' => 'main',
        'head_sha' => $this->revisionOldHead, 'title' => $this->revisionPr['title'],
        'title_sha256' => hash('sha256', $this->revisionPr['title']), 'body' => $this->revisionOldBody,
        'body_sha256' => hash('sha256', $this->revisionOldBody),
        'reviews' => [['id' => 100, 'commit_id' => $this->revisionOldHead, 'state' => 'CHANGES_REQUESTED',
            'author_login' => 'tom-nckrtl[bot]', 'author_type' => 'Bot', 'body_sha256' => hash('sha256', 'Correct the bounded issue.')]],
        'reason' => 'Legacy controller is paused; its exact agents yielded. The new independent package review resolves review 100.'];
    $this->revisionEffects = [];
    $this->revisionRequests = [];
    $this->revisionAncestor = true;
    $this->revisionLost = [];
    $this->revisionInvisible = [];
    $this->revisionBeforeWrite = null;
    $this->revisionLevel = DB::transactionLevel();
    config(['commander.hermes.ssh_target' => 'tom@mini', 'commander.hermes.profiles.tom' => '/Users/tom/.hermes/profiles/tom']);
    Process::fake(function ($process) {
        expect(DB::transactionLevel())->toBe($this->revisionLevel);
        $command = $process->command;
        if ($command[0] === 'git') {
            if ($command[2] === 'merge-base') {
                expect($command)->toBe(['git', '--no-replace-objects', 'merge-base', '--is-ancestor',
                    $this->revisionOldHead, $this->revisionLanding->candidate_sha]);

                return Process::result(exitCode: $this->revisionAncestor ? 0 : 1);
            }
            if ($command[2] === 'ls-remote') {
                return Process::result(output: $this->revisionRemoteHead."\trefs/heads/orb-200\n");
            }
            expect($command)->toBe(['git', '--no-replace-objects', 'push', '--porcelain',
                '--force-with-lease=refs/heads/orb-200:'.$this->revisionOldHead, 'origin',
                $this->revisionLanding->candidate_sha.':refs/heads/orb-200']);
            $this->revisionEffects[] = 'branch';
            $this->revisionBeforeWrite?->__invoke('branch');
            if ($this->revisionRemoteHead !== $this->revisionOldHead) {
                return Process::result(exitCode: 1, errorOutput: 'stale info');
            }
            if (! in_array('branch', $this->revisionInvisible, true)) {
                $this->revisionRemoteHead = $this->revisionLanding->candidate_sha;
                $this->revisionPr['head']['sha'] = $this->revisionRemoteHead;
            }

            return Process::result(exitCode: in_array('branch', $this->revisionLost, true) ? 1 : 0);
        }
        expect($command)->toBe(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'tom@mini',
            "'/Users/tom/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc"]);
        $request = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
        expect($request['service'])->toBe('github');
        $this->revisionRequests[] = $request;
        $method = $request['method'] ?? 'GET';
        $path = $request['path'];
        if ($method === 'PATCH') {
            expect($path)->toBe('repos/nckrtl/orbit/pulls/298')->and($request['app'] ?? false)->toBeFalse()
                ->and($request['body'])->toBe(['title' => $this->revisionLanding->package['title'], 'body' => $this->revisionLanding->package['body']]);
            $this->revisionEffects[] = 'publication';
            $this->revisionBeforeWrite?->__invoke('publication');
            if (! in_array('publication', $this->revisionInvisible, true)) {
                $this->revisionPr['body'] = $request['body']['body'];
            }

            return Process::result(output: json_encode($this->revisionPr), exitCode: in_array('publication', $this->revisionLost, true) ? 1 : 0);
        }
        if ($method === 'POST') {
            expect($path)->toBe('repos/nckrtl/orbit/pulls/298/reviews')->and($request['app'])->toBeTrue()
                ->and($request['body']['event'])->toBe('APPROVE');
            $this->revisionEffects[] = 'approval';
            $this->revisionReviews[] = ['id' => 200, 'commit_id' => $request['body']['commit_id'],
                'state' => 'APPROVED', 'body' => $request['body']['body'], 'user' => ['login' => 'tom-nckrtl[bot]', 'type' => 'Bot']];

            return Process::result(output: json_encode(end($this->revisionReviews)));
        }
        expect($method)->toBe('GET');
        $response = match ($path) {
            'repos/nckrtl/orbit/pulls?state=all&head=nckrtl:orb-200&base=main&per_page=100' => [['number' => 298]],
            'repos/nckrtl/orbit/pulls/298' => $this->revisionPr,
            'repos/nckrtl/orbit/pulls/298/reviews?per_page=100' => $this->revisionReviews,
            'repos/nckrtl/orbit/rules/branches/main' => [['type' => 'deletion'], ['type' => 'non_fast_forward']],
            default => throw new LogicException('Unexpected revision RPC.'),
        };

        return Process::result(output: json_encode($response));
    })->preventStrayProcesses();
});

afterEach(fn () => File::deleteDirectory($this->revisionDirectory));

function revisePublication(bool $apply = true, ?array $request = null): array
{
    $test = test();

    return app(ReviseTaskPublication::class)->handle($test->revisionLanding->id, $test->revisionLanding->package_hash,
        $request ?? $test->revisionRequest, true, $apply);
}

it('previews exact existing publication without locks ledger writes jobs or external writes', function () {
    config(['task-runtime.enabled' => false]);
    expect(revisePublication(false)['observed']['state'])->toBe('before')
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)->and($this->revisionEffects)->toBe([]);
    expect(fn () => revisePublication())->toThrow(LogicException::class, 'enable');
    Queue::assertNothingPushed();
});

it('revises once then publishes the new exact approval while retaining all acceptance and old review history', function () {
    $landing = $this->revisionLanding;
    $workspace = $landing->workspace()->firstOrFail();
    $before = [$landing->toArray(), $workspace->toArray(), TaskRun::all()->toArray(), TaskAgentDispatch::all()->toArray()];
    expect(revisePublication()['observed']['state'])->toBe('after');
    revisePublication();
    expect($this->revisionEffects)->toBe(['branch', 'publication'])
        ->and($this->revisionReviews)->toHaveCount(1)
        ->and(TaskCloseoutOperation::query()->count())->toBe(2);
    $closeout = app(CloseTaskLanding::class);
    $closeout->handle($landing->id, $landing->package_hash, 'publish', true, true);
    $closeout->handle($landing->id, $landing->package_hash, 'publish', true, true);
    expect($this->revisionEffects)->toBe(['branch', 'publication', 'approval'])
        ->and($this->revisionReviews[0]['state'])->toBe('CHANGES_REQUESTED')
        ->and([$landing->fresh()->toArray(), $workspace->fresh()->toArray(), TaskRun::all()->toArray(), TaskAgentDispatch::all()->toArray()])->toBe($before);
    $pr = app(NativeTaskCloseoutGitHub::class)->publication($landing);
    expect(app(NativeTaskCloseoutGitHub::class)->approval($landing, $pr, app(TaskCloseoutContext::class)->approvalMarker($landing), true)['review_id'])->toBe(200);
    Queue::assertNothingPushed();
});

it('reconciles an applied write with a lost response without sending it twice', function (string $write) {
    $this->revisionLost = [$write];
    revisePublication();
    revisePublication();
    expect($this->revisionEffects)->toBe(['branch', 'publication'])
        ->and(TaskCloseoutOperation::query()->where('state', 'unknown')->count())->toBe(0);
})->with(['branch', 'publication']);

it('never resends an uncertain unapparent write and can reconcile later exact readback', function (string $write) {
    $this->revisionLost = [$write];
    $this->revisionInvisible = [$write];
    expect(fn () => revisePublication())->toThrow(LogicException::class, 'uncertain');
    expect(fn () => revisePublication())->toThrow(LogicException::class, 'uncertain');
    expect(count(array_filter($this->revisionEffects, fn ($effect) => $effect === $write)))->toBe(1);
    $this->revisionRemoteHead = $this->revisionLanding->candidate_sha;
    $this->revisionPr['head']['sha'] = $this->revisionRemoteHead;
    if ($write === 'publication') {
        $this->revisionPr['body'] = $this->revisionLanding->package['body'];
    }
    $this->revisionInvisible = [];
    revisePublication();
    expect($this->revisionEffects)->toBe(['branch', 'publication']);
})->with(['branch', 'publication']);

it('refuses branch PR identity body head or review preimage drift before writing', function (string $field) {
    match ($field) {
        'branch' => $this->revisionRemoteHead = str_repeat('d', 40),
        'head' => $this->revisionPr['head']['sha'] = str_repeat('d', 40),
        'body' => $this->revisionPr['body'] .= ' changed',
        'title' => $this->revisionPr['title'] .= ' changed',
        'author' => $this->revisionPr['user']['login'] = 'someone-else',
        'repo' => $this->revisionPr['head']['repo']['full_name'] = 'someone/orbit',
        'base' => $this->revisionPr['base']['ref'] = 'other',
        'draft' => $this->revisionPr['draft'] = true,
        'closed' => $this->revisionPr['state'] = 'closed',
        'review' => $this->revisionReviews[0]['body'] .= ' changed',
        'nonancestor' => $this->revisionAncestor = false,
    };
    expect(fn () => revisePublication())->toThrow(LogicException::class)
        ->and($this->revisionEffects)->toBe([])->and(TaskCloseoutOperation::query()->count())->toBe(0);
})->with(['branch', 'head', 'body', 'title', 'author', 'repo', 'base', 'draft', 'closed', 'review', 'nonancestor']);

it('retains the exact lease failure when another writer advances the ref and does not retry', function () {
    $this->revisionBeforeWrite = function (string $write): void {
        if ($write === 'branch') {
            $this->revisionRemoteHead = str_repeat('d', 40);
            $this->revisionPr['head']['sha'] = str_repeat('d', 40);
        }
    };
    expect(fn () => revisePublication())->toThrow(LogicException::class);
    expect(fn () => revisePublication())->toThrow(LogicException::class);
    expect($this->revisionEffects)->toBe(['branch'])->and($this->revisionRemoteHead)->toBe(str_repeat('d', 40))
        ->and(TaskCloseoutOperation::query()->sole()->state)->toBe('unknown');
});

it('refuses an intervening PR change before the body write without retrying the completed branch', function (string $field) {
    $this->revisionLost = ['publication'];
    $this->revisionInvisible = ['publication'];
    expect(fn () => revisePublication())->toThrow(LogicException::class, 'uncertain');
    if ($field === 'body') {
        $this->revisionPr['body'] = 'Another writer changed the body.';
    } else {
        $this->revisionPr['head']['sha'] = str_repeat('e', 40);
    }
    expect(fn () => app(NativeTaskCloseoutGitHub::class)->revisePublication($this->revisionLanding, $this->revisionRequest))
        ->toThrow(LogicException::class);
    expect(fn () => revisePublication())->toThrow(LogicException::class)
        ->and($this->revisionEffects)->toBe(['branch', 'publication']);
})->with(['body', 'head']);

it('does not adopt matching remote effects without retained authority or accept changed completed effects', function (bool $recorded) {
    if ($recorded) {
        revisePublication();
        $this->revisionPr['body'] = $this->revisionOldBody;
    } else {
        $this->revisionRemoteHead = $this->revisionLanding->candidate_sha;
        $this->revisionPr['head']['sha'] = $this->revisionRemoteHead;
        $this->revisionPr['body'] = $this->revisionLanding->package['body'];
    }
    $effects = $this->revisionEffects;
    expect(fn () => revisePublication())->toThrow(LogicException::class)->and($this->revisionEffects)->toBe($effects);
})->with([false, true]);

it('preserves foreign reviewer holds even when explicitly included in the revision preimage', function (bool $commented) {
    $this->revisionReviews[0]['user'] = ['login' => 'another-reviewer', 'type' => 'User'];
    $this->revisionRequest['reviews'][0]['author_login'] = 'another-reviewer';
    $this->revisionRequest['reviews'][0]['author_type'] = 'User';
    if ($commented) {
        $this->revisionReviews[] = [...$this->revisionReviews[0], 'id' => 101, 'state' => 'COMMENTED'];
        $this->revisionRequest['reviews'][] = [...$this->revisionRequest['reviews'][0], 'id' => 101, 'state' => 'COMMENTED'];
    }
    expect(fn () => revisePublication())->toThrow(LogicException::class, 'Another reviewer')
        ->and($this->revisionEffects)->toBe([]);
})->with([false, true]);

it('requires completed revision authority before superseding the same bots prior changes request', function () {
    $landing = $this->revisionLanding;
    $this->revisionRemoteHead = $landing->candidate_sha;
    $this->revisionPr['head']['sha'] = $landing->candidate_sha;
    $this->revisionPr['body'] = $landing->package['body'];
    $github = app(NativeTaskCloseoutGitHub::class);
    $pr = $github->publication($landing);
    expect(fn () => $github->approve($landing, $pr, app(TaskCloseoutContext::class)->approvalMarker($landing)))
        ->toThrow(LogicException::class, 'changes')->and($this->revisionEffects)->toBe([]);
});

it('holds new foreign requests changed retained reviews and stale dismissed or wrong-head approval after revision', function (string $defect) {
    revisePublication();
    $landing = $this->revisionLanding;
    app(CloseTaskLanding::class)->handle($landing->id, $landing->package_hash, 'publish', true, true);
    match ($defect) {
        'foreign' => $this->revisionReviews[] = ['id' => 300, 'commit_id' => $landing->candidate_sha, 'body' => 'New issue.',
            'state' => 'CHANGES_REQUESTED', 'user' => ['login' => 'another-reviewer', 'type' => 'User']],
        'retained' => $this->revisionReviews[0]['state'] = 'DISMISSED',
        'dismissed' => $this->revisionReviews[1]['state'] = 'DISMISSED',
        'head' => $this->revisionReviews[1]['commit_id'] = $this->revisionOldHead,
        'stale' => $this->revisionReviews[] = [...$this->revisionReviews[1], 'id' => 300, 'body' => 'Unrelated generic approval.'],
    };
    $github = app(NativeTaskCloseoutGitHub::class);
    expect(fn () => $github->approval($landing, $github->publication($landing), app(TaskCloseoutContext::class)->approvalMarker($landing), true))
        ->toThrow(LogicException::class)->and($this->revisionEffects)->toBe(['branch', 'publication', 'approval']);
})->with(['foreign', 'retained', 'dismissed', 'head', 'stale']);

it('requires unchanged approved package and immutable revision inputs', function (string $defect) {
    if ($defect === 'input') {
        revisePublication();
        $this->revisionRequest['reason'] .= ' Changed decision.';
    } elseif ($defect === 'review') {
        DB::table('task_landings')->where('id', $this->revisionLanding->id)->update(['state' => 'rejected']);
    } else {
        $this->revisionRequest['body_sha256'] = str_repeat('0', 64);
    }
    $effects = $this->revisionEffects;
    expect(fn () => revisePublication())->toThrow(LogicException::class)->and($this->revisionEffects)->toBe($effects);
})->with(['input', 'review', 'hash']);

it('refuses request fields that would broaden the revision contract', function (string $defect) {
    match ($defect) {
        'unknown' => $this->revisionRequest['force'] = true,
        'prefix' => $this->revisionRequest['head_ref'] = 'orb-200-successor',
        'title' => $this->revisionRequest['title'] = 'A different title',
        'duplicate' => $this->revisionRequest['reviews'][] = $this->revisionRequest['reviews'][0],
        'missing' => $this->revisionRequest['reviews'] = [],
    };
    expect(fn () => revisePublication())->toThrow(LogicException::class)
        ->and($this->revisionEffects)->toBe([])->and(TaskCloseoutOperation::query()->count())->toBe(0);
})->with(['unknown', 'prefix', 'title', 'duplicate', 'missing']);

it('leaves normal create-only publication unable to overwrite this legacy branch or PR', function () {
    expect(fn () => app(NativeTaskCloseoutRepository::class)->push($this->revisionLanding))->toThrow(LogicException::class, 'differs');
    expect(fn () => app(NativeTaskCloseoutGitHub::class)->publish($this->revisionLanding))->toThrow(LogicException::class, 'differs');
    expect($this->revisionEffects)->toBe([]);
});

it('exposes only an explicit bounded-file CLI with a read-only default', function () {
    $path = $this->revisionDirectory.'/request.json';
    File::put($path, TaskLandingData::json($this->revisionRequest));
    $arguments = ['landing' => $this->revisionLanding->id, '--package' => $this->revisionLanding->package_hash,
        '--file' => $path, '--exclusive' => true];
    expect(Artisan::call('tasks:revise-publication', $arguments))->toBe(0)
        ->and(TaskCloseoutOperation::query()->count())->toBe(0)->and($this->revisionEffects)->toBe([]);
    expect(Artisan::call('tasks:revise-publication', [...$arguments, '--exclusive' => false]))->toBe(1);
});

it('uses an exact lease against a real disposable Git remote and never overwrites an intervening ref', function (bool $race) {
    $repository = $this->revisionDirectory.'/git';
    $remote = $this->revisionDirectory.'/remote.git';
    File::makeDirectory($repository);
    File::makeDirectory($remote);
    $git = function (string $directory, array $args, ?string $input = null) use ($repository, $remote): LocalProcess {
        expect($directory)->toBeIn([$repository, $remote]);
        $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$args], $directory,
            [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
                'GIT_AUTHOR_NAME' => 'Revision Test', 'GIT_AUTHOR_EMAIL' => 'revision@example.test',
                'GIT_COMMITTER_NAME' => 'Revision Test', 'GIT_COMMITTER_EMAIL' => 'revision@example.test'], $input, 10);
        $process->run();

        return $process;
    };
    expect($git($repository, ['init', '--initial-branch=main'])->isSuccessful())->toBeTrue();
    expect($git($remote, ['init', '--bare', '--initial-branch=main'])->isSuccessful())->toBeTrue();
    $tree = trim($git($repository, ['mktree'], '')->getOutput());
    $old = trim($git($repository, ['commit-tree', $tree], "Old\n")->getOutput());
    $candidate = trim($git($repository, ['commit-tree', $tree, '-p', $old], "Candidate\n")->getOutput());
    $intervening = trim($git($repository, ['commit-tree', $tree, '-p', $old], "Other writer\n")->getOutput());
    expect($git($repository, ['remote', 'add', 'origin', $remote])->isSuccessful())->toBeTrue();
    expect($git($repository, ['push', 'origin', $old.':refs/heads/orb-200', $intervening.':refs/heads/other'])->isSuccessful())->toBeTrue();
    expect($git($repository, ['merge-base', '--is-ancestor', $old, $candidate])->isSuccessful())->toBeTrue();
    if ($race) {
        expect($git($remote, ['update-ref', 'refs/heads/orb-200', $intervening, $old])->isSuccessful())->toBeTrue();
    }
    $push = $git($repository, ['push', '--porcelain', '--force-with-lease=refs/heads/orb-200:'.$old,
        'origin', $candidate.':refs/heads/orb-200']);
    expect($push->isSuccessful())->toBe(! $race)
        ->and(trim($git($remote, ['rev-parse', 'refs/heads/orb-200'])->getOutput()))->toBe($race ? $intervening : $candidate);
})->with([false, true]);
