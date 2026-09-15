<?php

use App\Models\TaskLanding;
use App\Tasks\Closeout\NativeTaskCloseoutGitHub;
use App\Tasks\Closeout\NativeTaskCloseoutRepository;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\TaskCloseoutFixture;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

/** Real Git runs only inside this test's disposable fixture, with local remotes. */
function closeoutLocalGit(string $path, array $arguments, ?string $input = null, bool $allowFailure = false): LocalProcess
{
    expect(str_starts_with($path, test()->nativeDirectory.'/'))->toBeTrue();
    $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments], $path,
        [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_AUTHOR_NAME' => 'Closeout Test', 'GIT_AUTHOR_EMAIL' => 'closeout@example.test',
            'GIT_COMMITTER_NAME' => 'Closeout Test', 'GIT_COMMITTER_EMAIL' => 'closeout@example.test'], $input, 10);
    $process->run();
    if (! $allowFailure && ! $process->isSuccessful()) {
        throw new RuntimeException('Disposable closeout Git fixture failed: '.$process->getErrorOutput());
    }

    return $process;
}

describe('exact Tasks GitHub RPC', function () {
    beforeEach(function () {
        config(['commander.hermes.ssh_target' => 'tom@mini', 'commander.hermes.profiles.tom' => '/Users/tom/.hermes/profiles/tom']);
        $this->nativeLanding = new TaskLanding(['issue_id' => '22222222-2222-4222-8222-222222222222', 'candidate_sha' => str_repeat('a', 40),
            'artifact_sha' => str_repeat('b', 40), 'package_hash' => str_repeat('c', 64), 'review_assignment' => 'exact-independent-assignment',
            'review_result' => ['verdict' => 'pass', 'evidence' => 'Reviewed exact package.'],
            'inputs' => ['issue' => ['identifier' => 'ORB-249']], 'package' => ['title' => 'ORB-249: Exact title', 'body' => 'Exact reviewed body.']]);
        $this->nativePr = null;
        $this->nativeReviews = [];
        $this->nativeRequests = [];
        $this->nativeReservation = ['version' => 1, 'status' => 'idle', 'issue_id' => null, 'pr_url' => null, 'reserved_at' => null];
        $this->nativeRules = [['type' => 'deletion'], ['type' => 'non_fast_forward']];
        $_ENV['TASK_CLOSEOUT_PRIVATE_FIXTURE'] = 'must-not-reach-child';
        Process::fake(function ($process) {
            expect($process->command)->toBe(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'tom@mini',
                "'/Users/tom/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc"])
                ->and($process->environment['TASK_CLOSEOUT_PRIVATE_FIXTURE'] ?? null)->toBeFalse();
            $request = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->nativeRequests[] = $request;
            if ($request['service'] === 'reservation') {
                if ($request['action'] === 'reserve') {
                    $this->nativeReservation = ['version' => 1, 'status' => 'active', 'issue_id' => $request['issue'],
                        'pr_url' => $request['pr'], 'reserved_at' => '2026-09-12T23:00:00Z'];
                } elseif ($request['action'] === 'release') {
                    $this->nativeReservation = ['version' => 1, 'status' => 'idle', 'issue_id' => null, 'pr_url' => null, 'reserved_at' => null];
                }
                $response = $this->nativeReservation;
            } else {
                expect($request['service'])->toBe('github');
                $path = $request['path'];
                $method = $request['method'] ?? 'GET';
                if ($method === 'POST' && $path === 'repos/nckrtl/orbit/pulls') {
                    expect($request['app'] ?? false)->toBeFalse();
                    $this->nativePr = ['number' => 77, 'html_url' => 'https://github.com/nckrtl/orbit/pull/77', 'title' => $request['body']['title'],
                        'body' => $request['body']['body'], 'draft' => false, 'state' => 'open', 'merged' => false, 'mergeable' => true,
                        'head' => ['sha' => $this->nativeLanding->candidate_sha, 'ref' => 'orb-249', 'repo' => ['full_name' => 'nckrtl/orbit']],
                        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']], 'user' => ['login' => 'nckrtl', 'type' => 'User']];
                    $response = $this->nativePr;
                } elseif ($method === 'POST' && $path === 'repos/nckrtl/orbit/pulls/77/reviews') {
                    expect($request['app'])->toBeTrue()->and($request['body']['event'])->toBe('APPROVE');
                    $response = ['id' => count($this->nativeReviews) + 10, 'commit_id' => $request['body']['commit_id'],
                        'body' => $request['body']['body'], 'state' => 'APPROVED', 'user' => ['login' => 'tom-nckrtl[bot]', 'type' => 'Bot']];
                    $this->nativeReviews[] = $response;
                } elseif ($method === 'PUT' && $path === 'repos/nckrtl/orbit/pulls/77/merge') {
                    expect($request['body'])->toBe(['sha' => $this->nativeLanding->candidate_sha, 'merge_method' => 'merge']);
                    $this->nativePr = [...$this->nativePr, 'state' => 'closed', 'merged' => true, 'merge_commit_sha' => str_repeat('d', 40)];
                    $response = ['merged' => true, 'sha' => str_repeat('d', 40)];
                } else {
                    expect($method)->toBe('GET');
                    $response = match ($path) {
                        'repos/nckrtl/orbit/pulls?state=all&head=nckrtl:orb-249&base=main&per_page=100' => $this->nativePr === null ? [] : [['number' => 77]],
                        'repos/nckrtl/orbit/pulls/77' => $this->nativePr,
                        'repos/nckrtl/orbit/pulls/77/reviews?per_page=100' => $this->nativeReviews,
                        'repos/nckrtl/orbit/rules/branches/main' => $this->nativeRules,
                        default => throw new LogicException('Unexpected RPC path.'),
                    };
                }
            }

            return Process::result(output: json_encode($response, JSON_THROW_ON_ERROR));
        })->preventStrayProcesses();
        $this->nativeGithub = app(NativeTaskCloseoutGitHub::class);
        $this->nativeMarker = app(TaskCloseoutContext::class)->approvalMarker($this->nativeLanding);
    });

    afterEach(function () {
        unset($_ENV['TASK_CLOSEOUT_PRIVATE_FIXTURE']);
    });

    it('uses exact publication and distinct package-specific review through every real SSH adapter operation', function () {
        $landing = $this->nativeLanding;
        $github = $this->nativeGithub;
        expect($github->publication($landing))->toBeNull();
        $github->publish($landing);
        $pr = $github->publication($landing);
        $github->approve($landing, $pr, $this->nativeMarker);
        expect($github->approval($landing, $pr, $this->nativeMarker, true)['review_id'])->toBe(10)
            ->and($this->nativeMarker)->toContain($landing->package_hash, $landing->candidate_sha, $landing->artifact_sha,
                hash('sha256', $landing->package['title']), hash('sha256', $landing->package['body']));
        $github->reserve($landing, $pr);
        expect($github->reservation($landing, $pr)['issue_id'])->toBe($landing->issue_id);
        $github->merge($landing, $pr);
        expect($github->merged($landing, $pr)['merge_sha'])->toBe(str_repeat('d', 40));
        $github->release($landing, $pr);
        expect($github->reservation($landing, $pr))->toBeNull();
    });

    it('does not reuse a generic same-head approval or patch published wording', function () {
        $landing = $this->nativeLanding;
        $github = $this->nativeGithub;
        $github->publish($landing);
        $pr = $github->publication($landing);
        $this->nativeReviews[] = ['id' => 1, 'commit_id' => $landing->candidate_sha, 'body' => 'Approved.', 'state' => 'APPROVED',
            'user' => ['login' => 'tom-nckrtl[bot]', 'type' => 'Bot']];
        expect($github->approval($landing, $pr, $this->nativeMarker))->toBeNull();
        $github->approve($landing, $pr, $this->nativeMarker);
        $github->approve($landing, $pr, $this->nativeMarker);
        expect($this->nativeReviews)->toHaveCount(2)
            ->and(array_filter($this->nativeRequests, fn (array $request): bool => ($request['method'] ?? null) === 'PATCH'))->toBe([]);
    });

    it('holds exact PR identity and content drift', function (string $field) {
        $this->nativeGithub->publish($this->nativeLanding);
        match ($field) {
            'title' => $this->nativePr['title'] .= ' changed',
            'body' => $this->nativePr['body'] .= ' changed',
            'head' => $this->nativePr['head']['sha'] = str_repeat('0', 40),
            'branch' => $this->nativePr['head']['ref'] = 'orb-other',
            'repo' => $this->nativePr['head']['repo']['full_name'] = 'other/orbit',
            'base' => $this->nativePr['base']['ref'] = 'other',
            'author' => $this->nativePr['user']['login'] = 'other',
            'draft' => $this->nativePr['draft'] = true,
            'closed' => $this->nativePr['state'] = 'closed',
        };
        expect(fn () => $this->nativeGithub->publication($this->nativeLanding))->toThrow(LogicException::class);
    })->with(['title', 'body', 'head', 'branch', 'repo', 'base', 'author', 'draft', 'closed']);

    it('holds unauthorized dismissed duplicate or wrong-head exact review markers', function (string $field) {
        $this->nativeGithub->publish($this->nativeLanding);
        $pr = $this->nativeGithub->publication($this->nativeLanding);
        $this->nativeGithub->approve($this->nativeLanding, $pr, $this->nativeMarker);
        match ($field) {
            'principal' => $this->nativeReviews[0]['user']['login'] = 'nckrtl',
            'type' => $this->nativeReviews[0]['user']['type'] = 'User',
            'dismissed' => $this->nativeReviews[0]['state'] = 'DISMISSED',
            'head' => $this->nativeReviews[0]['commit_id'] = str_repeat('0', 40),
            'duplicate' => $this->nativeReviews[] = [...$this->nativeReviews[0], 'id' => 99],
        };
        expect(fn () => $this->nativeGithub->approval($this->nativeLanding, $pr, $this->nativeMarker))->toThrow(LogicException::class);
    })->with(['principal', 'type', 'dismissed', 'head', 'duplicate']);

    it('does not hide another reviewer changes request behind a later bot approval or comment', function (bool $oldHead) {
        $this->nativeGithub->publish($this->nativeLanding);
        $pr = $this->nativeGithub->publication($this->nativeLanding);
        $this->nativeGithub->approve($this->nativeLanding, $pr, $this->nativeMarker);
        $this->nativeReviews[] = ['id' => 1, 'commit_id' => $oldHead ? str_repeat('f', 40) : $this->nativeLanding->candidate_sha, 'body' => 'Needs repair.', 'state' => 'CHANGES_REQUESTED',
            'user' => ['login' => 'another-reviewer', 'type' => 'User']];
        $this->nativeReviews[] = [...$this->nativeReviews[1], 'id' => 20, 'state' => 'COMMENTED'];
        expect(fn () => $this->nativeGithub->approval($this->nativeLanding, $pr, $this->nativeMarker))->toThrow(LogicException::class, 'changes');
    })->with([false, true]);

    it('requires complete reviews protected main and confirmed mergeability', function (string $defect) {
        $this->nativeGithub->publish($this->nativeLanding);
        $pr = $this->nativeGithub->publication($this->nativeLanding);
        $this->nativeGithub->approve($this->nativeLanding, $pr, $this->nativeMarker);
        match ($defect) {
            'pagination' => $this->nativeReviews = array_fill(0, 100, $this->nativeReviews[0]),
            'rules' => $this->nativeRules = [],
            'mergeability' => $this->nativePr['mergeable'] = null,
        };
        expect(fn () => $this->nativeGithub->approval($this->nativeLanding, $pr, $this->nativeMarker, true))->toThrow(LogicException::class);
    })->with(['pagination', 'rules', 'mergeability']);

    it('refuses partial idle and foreign active reservations', function (bool $partial) {
        $this->nativeGithub->publish($this->nativeLanding);
        $pr = $this->nativeGithub->publication($this->nativeLanding);
        $this->nativeReservation = $partial ? ['version' => 1, 'status' => 'idle'] : ['version' => 1, 'status' => 'active',
            'issue_id' => 'foreign-issue', 'pr_url' => $pr['url'], 'reserved_at' => '2026-09-12'];
        expect(fn () => $this->nativeGithub->reserve($this->nativeLanding, $pr))->toThrow(LogicException::class);
    })->with([false, true]);
});

describe('native Tasks main and discovery verification', function () {
    beforeEach(function () {
        $this->nativeDirectory = storage_path('framework/testing/native-closeout-'.bin2hex(random_bytes(8)));
        $this->fixture = new TaskCloseoutFixture($this->nativeDirectory);
        $this->nativeLanding = $this->fixture->add();
        foreach (['tia-cache', 'loop-flow'] as $script) {
            File::put($this->nativeDirectory.'/repository/bin/'.$script, "#!/bin/sh\nexit 99\n");
            chmod($this->nativeDirectory.'/repository/bin/'.$script, 0755);
        }
        $this->nativeMain = str_repeat('d', 40);
        $this->nativeMerge = str_repeat('e', 40);
        $this->nativeFailures = [];
        $this->nativeAncestry = 0;
        $this->nativeFlow = 'discovery';
        Process::fake(function ($process) {
            expect($process->path)->toBe($this->nativeDirectory.'/repository')
                ->and($process->environment['GIT_NO_LAZY_FETCH'])->toBe('1');
            $command = $process->command;
            if ($command === [$this->nativeDirectory.'/repository/bin/tia-cache', 'status', '--json', '--remote']) {
                $output = ['schema' => 1, 'main' => $this->nativeMain, 'correctness_failures' => $this->nativeFailures];
            } elseif ($command[0] === $this->nativeDirectory.'/repository/bin/loop-flow') {
                expect($command)->toBe([$command[0], 'verify-merge', '--worktree='.$this->nativeLanding->workspace()->firstOrFail()->worktree,
                    '--candidate='.$this->nativeLanding->candidate_sha, '--merge='.$this->nativeMerge]);
                $output = ['flow' => $this->nativeFlow, 'candidate' => $this->nativeLanding->candidate_sha,
                    'merge' => $this->nativeMerge, 'tree' => str_repeat('f', 40)];
            } elseif ($command === ['git', '--no-replace-objects', 'fetch', 'origin', 'main']) {
                return Process::result();
            } elseif ($command === ['git', '--no-replace-objects', 'merge-base', '--is-ancestor', $this->nativeMerge, $this->nativeMain]) {
                return Process::result(exitCode: $this->nativeAncestry);
            } else {
                throw new LogicException('Unexpected native closeout command.');
            }

            return Process::result(output: json_encode($output, JSON_THROW_ON_ERROR));
        })->preventStrayProcesses();
    });

    afterEach(fn () => File::deleteDirectory($this->nativeDirectory));

    it('explicitly fetches verifies native discovery lineage and preserves failing main evidence', function () {
        $this->nativeFailures = ['apps/e2e' => ['case' => 'failed']];
        $result = app(NativeTaskCloseoutRepository::class)->verify($this->nativeLanding, ['merge_sha' => $this->nativeMerge]);
        expect($result['native_failures'])->toBe($this->nativeFailures)->and($result['lineage']['flow'])->toBe('discovery')
            ->and($result['main_sha'])->toBe($this->nativeMain);
        Process::assertRanTimes(fn (): bool => true, 4);
    });

    it('refuses unsupported flow or unproven main ancestry', function (string $defect) {
        match ($defect) {
            'flow' => $this->nativeFlow = 'proof',
            'not-ancestor' => $this->nativeAncestry = 1,
            'missing-object' => $this->nativeAncestry = 128,
        };
        expect(fn () => app(NativeTaskCloseoutRepository::class)->verify($this->nativeLanding, ['merge_sha' => $this->nativeMerge]))
            ->toThrow(LogicException::class);
    })->with(['flow', 'not-ancestor', 'missing-object']);

    it('publishes only the exact missing candidate branch with a create-only lease and unchanged artifact inputs', function () {
        $landing = $this->nativeLanding;
        $published = false;
        Process::fake(function ($process) use ($landing, &$published) {
            expect($process->environment['GIT_NO_LAZY_FETCH'])->toBe('1');
            $ref = 'refs/heads/orb-249';
            if ($process->command === ['git', '--no-replace-objects', 'ls-remote', '--refs', 'origin', $ref]) {
                return Process::result(output: $published ? $landing->candidate_sha."\t".$ref."\n" : '');
            }
            expect($process->command)->toBe(['git', '--no-replace-objects', 'push', '--porcelain', '--force-with-lease='.$ref.':',
                'origin', $landing->candidate_sha.':'.$ref])
                ->and($process->path)->toBe($landing->workspace()->firstOrFail()->worktree);
            $published = true;

            return Process::result();
        })->preventStrayProcesses();
        $repository = app(NativeTaskCloseoutRepository::class);
        $repository->push($landing);
        $repository->push($landing);
        expect($repository->branch($landing)['candidate_sha'])->toBe($landing->candidate_sha);
        Process::assertRanTimes(fn ($process): bool => in_array('push', $process->command, true), 1);
    });

    it('atomically creates a local ref but refuses an ancestor published during the missing-ref gap', function (bool $race) {
        $repository = $this->nativeDirectory.'/cas-repository';
        $remote = $this->nativeDirectory.'/cas-remote.git';
        File::makeDirectory($repository, 0700);
        File::makeDirectory($remote, 0700);
        closeoutLocalGit($repository, ['init', '--initial-branch=main']);
        closeoutLocalGit($remote, ['init', '--bare', '--initial-branch=main']);
        $tree = trim(closeoutLocalGit($repository, ['mktree'], '')->getOutput());
        $base = trim(closeoutLocalGit($repository, ['commit-tree', $tree], "Base\n")->getOutput());
        $candidate = trim(closeoutLocalGit($repository, ['commit-tree', $tree, '-p', $base], "Candidate\n")->getOutput());
        closeoutLocalGit($repository, ['remote', 'add', 'origin', $remote]);
        closeoutLocalGit($repository, ['push', 'origin', $candidate.':refs/heads/main']);
        $ref = 'refs/heads/orb-249';
        expect(trim(closeoutLocalGit($repository, ['ls-remote', '--refs', 'origin', $ref])->getOutput()))->toBe('');
        if ($race) {
            closeoutLocalGit($remote, ['update-ref', $ref, $base]);
        }
        $push = closeoutLocalGit($repository, ['--no-replace-objects', 'push', '--porcelain', '--force-with-lease='.$ref.':',
            'origin', $candidate.':'.$ref], allowFailure: true);
        expect($push->isSuccessful())->toBe(! $race)
            ->and(trim(closeoutLocalGit($remote, ['rev-parse', $ref])->getOutput()))->toBe($race ? $base : $candidate);
        if ($race) {
            expect($push->getOutput())->toContain('stale info');
            // The former ordinary push would incorrectly advance the other writer's ancestor.
            closeoutLocalGit($repository, ['push', 'origin', $candidate.':'.$ref]);
            expect(trim(closeoutLocalGit($remote, ['rev-parse', $ref])->getOutput()))->toBe($candidate);
        }
    })->with([false, true]);
});
