<?php

namespace Tests\Support;

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Models\TaskAgentDispatch;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskMainHold;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskWorkspace;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\Closeout\TaskMainHoldService;
use App\Tasks\Completion\CompleteTaskLanding;
use App\Tasks\Completion\TaskCompletionIssue;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ApplyTaskReattempt;
use App\Tasks\Runtime\PrepareTaskReattempt;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process as LocalProcess;

final class TaskCompletionFixture implements TaskCompletionIssue
{
    public TaskCloseoutFixture $base;

    public TaskCloseoutFakeRemote $remote;

    public array $landings = [];

    public array $writes = [];

    public array $reservationState = ['status' => 'idle', 'issue_id' => null, 'url' => null, 'reserved_at' => null];

    public bool $loseResponse = false;

    public bool $applyMutation = true;

    public bool $failRead = false;

    public bool $failReservation = false;

    public bool $artifactMissing = false;

    public ?\Closure $afterMutation = null;

    public ?\Closure $beforeRead = null;

    public int $reads = 0;

    public bool $localGit = false;

    public int $localCalls = 0;

    public string $done = '33333333-3333-4333-8333-333333333333';

    public function __construct(public string $directory)
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->base = new TaskCloseoutFixture($directory);
        $this->remote = new TaskCloseoutFakeRemote;
        app()->instance(TaskCloseoutGitHub::class, $this->remote);
        app()->instance(TaskCloseoutRepository::class, $this->remote);
        app()->instance(TaskCompletionIssue::class, $this);
        config(['commander.hermes.ssh_target' => 'tom@mini', 'commander.hermes.profiles.tom' => '/Users/tom/.hermes/profiles/tom',
            'commander.hermes.tom_linear_viewer_id' => '44444444-4444-4444-8444-444444444444',
            'commander.hermes.nick_linear_user_id' => '55555555-5555-4555-8555-555555555555']);
        File::put($directory.'/evidence.txt', 'Retained exact-case acceptance evidence on the recorded main.');
        Process::fake(function ($process) {
            $command = $process->command;
            if ($this->localGit && array_slice($command, 0, 2) !== ['git', '--no-replace-objects']) {
                if ($command === ['mktemp', '-d', sys_get_temp_dir().'/commander-reattempt-XXXXXX']) {
                    $temporary = $this->directory.'/objects-'.bin2hex(random_bytes(8));
                    File::makeDirectory($temporary, 0700);

                    return Process::result(output: $temporary."\n");
                }
                expect(str_starts_with($process->path, $this->directory.'/'))->toBeTrue();
                if ($command === [PHP_BINARY, '-r', 'exit(0);']) {
                    return Process::result();
                }
                expect($command[0])->toBe('git');
                $this->localCalls++;
                $actual = new LocalProcess($command, $process->path, $process->environment, $process->input, 30);
                $actual->run();

                return Process::result(output: $actual->getOutput(), errorOutput: $actual->getErrorOutput(), exitCode: $actual->getExitCode());
            }
            expect($process->path)->toBe($this->directory.'/repository')
                ->and($process->environment['GIT_NO_LAZY_FETCH'])->toBe('1');
            expect(array_slice($command, 0, 2))->toBe(['git', '--no-replace-objects']);
            if (array_slice($command, 2) === ['remote', 'get-url', 'origin']) {
                return Process::result(output: "git@github.com:nckrtl/orbit.git\n");
            }
            foreach ($this->landings as $landing) {
                $arguments = array_slice($command, 2);
                if ($arguments === ['show-ref', '--verify', $landing->artifact_ref]) {
                    return Process::result(output: $landing->artifact_sha.' '.$landing->artifact_ref."\n", exitCode: $this->artifactMissing ? 1 : 0);
                }
                if ($arguments === ['ls-remote', '--refs', 'origin', $landing->artifact_ref]) {
                    return Process::result(output: $landing->artifact_sha."\t".$landing->artifact_ref."\n");
                }
                if ($arguments === ['cat-file', '-e', $landing->artifact_sha.'^{commit}']) {
                    return Process::result();
                }
            }
            throw new LogicException('No other process is allowed by the isolated completion fixture.');
        })->preventStrayProcesses();
    }

    public function add(int $number = 249, ?string $issueTitle = null): TaskLanding
    {
        $landing = $this->base->add($number, issueTitle: $issueTitle);
        $this->base->issues[$landing->issue_id]['team']['states']['nodes'] = [['id' => $this->done, 'name' => 'Done']];
        $this->landings[] = $landing;

        return $landing;
    }

    public function land(TaskLanding $landing): void
    {
        foreach (['publish', 'merge', 'verify', 'release'] as $stage) {
            app(CloseTaskLanding::class)->handle($landing->id, $landing->package_hash, $stage, true, true);
        }
    }

    /** Exercises the real first-child reattempt ledger and Git proof with disposable local repositories. */
    public function reattempt(): TaskLanding
    {
        $this->localGit = true;
        $repository = $this->directory.'/repository';
        $worktree = $this->directory.'/worktrees/orb-990001';
        $this->git($repository, ['init', '--initial-branch=main']);
        $this->git($repository, ['config', 'user.name', 'Completion Fixture']);
        $this->git($repository, ['config', 'user.email', 'completion@example.test']);
        $this->git($repository, ['remote', 'add', 'origin', $repository]);
        File::put($repository.'/feature.txt', "original\n");
        File::put($repository.'/prerequisite.txt', "broken\n");
        $this->git($repository, ['add', '--all']);
        $this->git($repository, ['commit', '-m', 'Original fixture']);
        $base = trim($this->git($repository, ['rev-parse', 'HEAD']));
        $this->git($repository, ['worktree', 'add', '-b', 'orb-990001', $worktree]);
        config(['task-runtime.projects.orbit' => ['repository' => $repository, 'worktree_root' => dirname($worktree),
            'socket' => '/unused-fixture.sock', 'agent_kind' => 'codex', 'agent_arguments' => [], 'flow_version' => 1,
            'instructions' => 'Disposable automated completion fixture.', 'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 5]]);
        app()->instance(TaskAgents::class, new class implements TaskAgents
        {
            public array $sessions = [];

            public function assertSession(TaskWorkspace $workspace, array $session): void
            {
                if (! in_array($session, $this->sessions, true)) {
                    throw new LogicException('Unknown disposable completion agent.');
                }
            }

            public function start(TaskWorkspace $workspace, string $name): array
            {
                if ($workspace->herdr_workspace === null) {
                    $workspace->update(['herdr_workspace' => ['workspaceId' => 'fixture', 'checkoutPath' => $workspace->worktree]]);
                }

                return $this->sessions[$name] = ['workspaceId' => 'fixture', 'tabId' => 'tab', 'paneId' => 'pane-'.$name,
                    'terminalId' => 'terminal-'.$name, 'agentName' => $name, 'agentId' => 'native-'.$name, 'workingDirectory' => $workspace->worktree];
            }

            public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
            {
                return $session;
            }
        });
        $root = app(CreateTask::class)->handle('orbit', 'Reattempt completion', 'Complete audited work.', TaskKind::Group, acceptanceCriteria: 'Exact proof passes.');
        app(CreateTask::class)->handle('orbit', 'One task', 'Implement one task.', parent: $root, acceptanceCriteria: 'Focused cases pass.');
        $workspace = app(StartTaskWorkspace::class)->handle($root, $worktree, app(TaskRuntimePlan::class)->hash($root), 'ORB-990001', true);
        $origin = app(AdvanceTaskWorkspace::class)->handle($workspace);
        File::put($worktree.'/feature.txt', "preserved work\n");
        $this->submit($origin, 'blocked');
        $this->git($repository, ['checkout', '-b', 'repair']);
        File::put($repository.'/prerequisite.txt', "reviewed repair\n");
        $this->git($repository, ['add', '--all']);
        $this->git($repository, ['commit', '-m', 'Separate reviewed repair']);
        $repair = trim($this->git($repository, ['rev-parse', 'HEAD']));
        $this->git($repository, ['checkout', 'main']);
        $this->git($repository, ['merge', '--no-ff', '--no-edit', 'repair']);
        $main = trim($this->git($repository, ['rev-parse', 'HEAD']));
        $logs = [];
        foreach (['review', 'main', 'restoration'] as $name) {
            $path = $this->directory.'/'.$name.'.log';
            File::put($path, $name.' passed for '.$main);
            $logs[$name] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        }
        $request = ['reason' => 'Reviewed prerequisite resolved the block.', 'evidence' => 'Original receipt retained.',
            'prerequisite' => ['source' => 'ORB-990002', 'pull_request' => 'https://github.com/nckrtl/orbit/pull/990002',
                'candidate' => $repair, 'merge' => $main, 'main' => $main, 'review' => $logs['review'],
                'verification' => ['head' => $main, 'command' => ['composer', 'test:affected'], 'working_directory' => $repository,
                    'exit_code' => 0, 'log' => $logs['main']]]];
        $prepare = app(PrepareTaskReattempt::class);
        $preview = $prepare->handle($workspace->id, $origin->id, $base, $workspace->manifest_hash, $request, true);
        $prepare->handle($workspace->id, $origin->id, $base, $workspace->manifest_hash, $request, true, $preview['state_hash'], true);
        $checkpoint = TaskReattemptCheckpoint::query()->where('task_workspace_id', $workspace->id)->sole();
        $this->git($worktree, ['read-tree', '--reset', '-u', $main]);
        $this->git($worktree, ['update-ref', 'HEAD', $main, $base]);
        foreach (['tree' => [], 'index_tree' => ['--cached']] as $kind => $flags) {
            $patch = $this->git($worktree, ['diff', '--binary', '--full-index', $base, $checkpoint->observation[$kind]]);
            if ($patch !== '') {
                $this->git($worktree, ['apply', '--binary', ...$flags], $patch);
            }
        }
        $request = ['reason' => 'Resume preserved work.', 'evidence' => 'Restored source checked.', 'log' => $logs['restoration']];
        $apply = app(ApplyTaskReattempt::class);
        $preview = $apply->handle($checkpoint->id, $request, true);
        $apply->handle($checkpoint->id, $request, true, $preview['state_hash'], true);
        $successor = app(AdvanceTaskWorkspace::class)->handle($workspace);
        File::put($worktree.'/feature.txt', "completed beyond checkpoint\n");
        $this->submit($successor);
        $this->submit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
        $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
        $this->git($worktree, ['add', '--all']);
        $this->git($worktree, ['commit', '-m', 'Accepted task']);
        $this->submit($commit);
        $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
        $this->submit($final, 'pass');
        $workspace->refresh();
        $candidate = trim($this->git($worktree, ['rev-parse', 'HEAD']));
        $tree = trim($this->git($worktree, ['rev-parse', 'HEAD^{tree}']));
        $issueId = (string) Str::uuid();
        $payload = ['id' => $issueId, 'identifier' => 'ORB-990001', 'title' => $root->title, 'description' => 'Complete audited work.',
            'url' => 'https://linear.app/orbit/issue/ORB-990001', 'updatedAt' => '2026-09-13T00:25:00Z',
            'state' => ['id' => (string) Str::uuid(), 'name' => 'In Review', 'type' => 'started'], 'delegate' => null, 'assignee' => null,
            'team' => ['id' => config('commander.hermes.orbit_linear_team_id'), 'states' => ['nodes' => [['id' => $this->done, 'name' => 'Done']]]]];
        foreach (['labels', 'attachments', 'children', 'inverseRelations'] as $key) {
            $payload[$key] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
        }
        $this->base->issues[$issueId] = $payload;
        $gate = $this->directory.'/reattempt-gate.json';
        File::put($gate, 'Retained exact reattempt candidate proof.');
        $this->base->repositories[$workspace->id] = ['candidate' => $candidate, 'tree' => $tree, 'gate_path' => $gate,
            'gate_sha256' => hash_file('sha256', $gate), 'gate_contents' => File::get($gate),
            'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'discovery'])];
        $request = ['candidate' => $candidate, 'manifest' => app(TaskRuntimePlan::class)->hash($root->fresh()), 'final_dispatch' => $final->id,
            'issue_id' => $issueId, 'gate_receipt' => $gate, 'pull_request_body' => 'Exact reattempt acceptance evidence.', 'evidence_files' => []];
        $inputs = app(TaskLandingEvidence::class)->capture($workspace, $request);
        $artifact = sha1('ORB-990001artifact');
        $ref = 'refs/tags/loop/orb-990001/'.$candidate;
        $package = ['schema' => 1, 'issue_id' => $issueId, 'issue_key' => 'ORB-990001', 'candidate_sha' => $candidate,
            'artifact_sha' => $artifact, 'artifact_ref' => $ref, 'input_hash' => TaskLandingData::hash($inputs),
            'title' => 'ORB-990001: '.$root->title, 'body' => 'Exact reviewed reattempt PR.'];
        $assignment = (string) Str::uuid();
        $session = [...$workspace->reviewer_session, 'agentId' => 'independent-package-review', 'agentName' => 'package-review'];
        $landing = TaskLanding::query()->create(['task_workspace_id' => $workspace->id, 'final_dispatch_id' => $final->id,
            'issue_id' => $issueId, 'candidate_sha' => $candidate, 'input_hash' => $package['input_hash'], 'request' => $request, 'inputs' => $inputs,
            'artifact_ref' => $ref, 'artifact_sha' => $artifact, 'package' => $package, 'package_hash' => TaskLandingData::hash($package),
            'state' => 'approved', 'review_assignment' => $assignment, 'review_session' => $session,
            'review_result' => ['assignment' => $assignment, 'package_hash' => TaskLandingData::hash($package), 'candidate_sha' => $candidate,
                'artifact_sha' => $artifact, 'session' => $session, 'verdict' => 'pass', 'summary' => 'Independent package pass.',
                'evidence' => 'Exact reattempt package checked.']])->refresh();
        $this->landings[] = $landing;

        return $landing;
    }

    public function git(string $directory, array $arguments, ?string $input = null): string
    {
        expect(str_starts_with($directory, $this->directory.'/'))->toBeTrue();
        $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments], $directory,
            [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0'], $input, 10);
        $process->mustRun();

        return $process->getOutput();
    }

    private function submit(TaskAgentDispatch $dispatch, ?string $verdict = null): void
    {
        app(SubmitTaskDispatch::class)->handle($dispatch, ['token' => $dispatch->handoff_token,
            'summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable automated evidence.', ...($verdict === null ? [] : ['verdict' => $verdict])]);
    }

    public function finish(TaskLanding $landing, bool $apply = true): array
    {
        return app(CompleteTaskLanding::class)->handle($landing->id, $landing->package_hash, true, $apply);
    }

    public function identityHash(): string
    {
        return hash('sha256', 'exact Tasks completion identities');
    }

    public function read(TaskLanding $landing): OrbitIssueSnapshot
    {
        $this->reads++;
        $this->beforeRead?->__invoke($landing, $this->reads);
        if ($this->failRead) {
            throw new RuntimeException('Completion read unavailable.');
        }

        return $this->base->read($landing->issue_id, $landing->inputs['issue']['identifier']);
    }

    public function complete(TaskLanding $landing, string $stateId): void
    {
        expect(DB::transactionLevel())->toBe($this->remote->level)
            ->and(TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'linear-completion')->sole()->state)->toBe('intended');
        $this->writes[] = ['issue_id' => $landing->issue_id, 'state_id' => $stateId];
        if ($this->applyMutation) {
            $this->base->issues[$landing->issue_id]['state'] = ['id' => $stateId, 'name' => 'Done', 'type' => 'completed'];
        }
        $this->afterMutation?->__invoke($landing);
        if ($this->loseResponse) {
            throw new RuntimeException('Lost Linear write response.');
        }
    }

    public function reservation(TaskLanding $landing, array $pr): array
    {
        if ($this->failReservation) {
            throw new RuntimeException('Reservation inspection unavailable.');
        }

        return $this->reservationState;
    }

    public function hold(string $incident = 'known-main-cases'): TaskMainHold
    {
        $result = app(TaskMainHoldService::class)->handle('import', $this->request(['incident' => $incident,
            'relevant_cases' => ['case-one', 'case-two']]), true, true);

        return TaskMainHold::query()->findOrFail($result['hold']['id']);
    }

    public function authorize(TaskMainHold $hold, TaskLanding $landing): void
    {
        app(TaskMainHoldService::class)->handle('repair', $this->request(['hold_id' => $hold->id, 'landing_id' => $landing->id,
            'package_hash' => $landing->package_hash, 'retained_holds' => TaskMainHold::query()->whereNull('clearance')
                ->whereKeyNot($hold->id)->orderBy('id')->get()->map(fn ($other): array => ['id' => $other->id, 'evidence_hash' => $other->evidence_hash])->all()]), true, true);
    }

    public function prove(TaskMainHold $hold, TaskLanding $landing, int $exit = 0): void
    {
        app(TaskMainHoldService::class)->handle('observe', $this->request(['hold_id' => $hold->id, 'landing_id' => $landing->id,
            'package_hash' => $landing->package_hash, 'merge_sha' => $this->remote->merges[$landing->id]['merge_sha'],
            'verification_exit_code' => $exit, 'checks' => array_map(fn (string $case): array => ['case' => $case,
                'command' => 'composer test', 'cwd' => $this->directory.'/repository', 'main_sha' => $this->remote->mainSha,
                'executed' => true, 'selected' => true, 'cached' => false, 'exit_code' => 0], ['case-one', 'case-two'])]), true, true);
    }

    private function request(array $extra): array
    {
        return ['repository' => $this->directory.'/repository', 'main_sha' => $this->remote->mainSha,
            'attestation' => 'Inspected the actual relevant cases and full command result.',
            'evidence_file' => $this->directory.'/evidence.txt',
            'evidence_sha256' => hash('sha256', File::get($this->directory.'/evidence.txt')), ...$extra];
    }
}
