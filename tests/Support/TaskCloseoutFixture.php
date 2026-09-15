<?php

namespace Tests\Support;

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Models\TaskLanding;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class TaskCloseoutFixture implements OrbitIssueReader, TaskLandingRepository
{
    public array $issues = [];

    public array $repositories = [];

    public function __construct(public string $directory)
    {
        File::makeDirectory($directory.'/projects', 0700, true);
        File::makeDirectory($directory.'/repository/bin', 0700, true);
        File::makeDirectory($directory.'/worktrees', 0700, true);
        config(['commander.projects_path' => $directory.'/projects', 'task-runtime.enabled' => true,
            'task-runtime.projects.orbit' => ['repository' => $directory.'/repository', 'worktree_root' => $directory.'/worktrees'],
            'commander.hermes.orbit_linear_team_id' => '11111111-1111-4111-8111-111111111111']);
        app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
        app()->instance(OrbitIssueReader::class, $this);
        app()->instance(TaskLandingRepository::class, $this);
    }

    public function add(int $number = 249, array $reviewOverrides = [], ?string $issueTitle = null): TaskLanding
    {
        $issue = 'ORB-'.$number;
        $candidate = sha1($issue.'candidate');
        $tree = sha1($issue.'tree');
        $base = sha1($issue.'base');
        $worktree = $this->directory.'/worktrees/'.strtolower($issue);
        File::makeDirectory($worktree, 0700, true);
        $root = app(CreateTask::class)->handle('orbit', 'Repair '.$issue, 'Repair the exact failing behavior.',
            TaskKind::Group, acceptanceCriteria: 'Relevant cases actually pass.');
        $child = app(CreateTask::class)->handle('orbit', 'Implement repair', 'One reviewed implementation.', parent: $root,
            acceptanceCriteria: 'Focused proof passes.');
        $run = TaskRun::query()->create(['task_id' => $child->id, 'root_task_id' => $root->id,
            'attempt' => 1, 'idempotency_key' => strtolower($issue), 'worker_ref' => 'implementer-'.$number, 'reviewer_ref' => 'reviewer-'.$number,
            'base_sha' => $base, 'commit_sha' => $candidate, 'status' => TaskRunStatus::Completed,
            'input' => [], 'output' => ['summary' => 'Accepted repair.', 'evidence' => 'Focused cases passed.'],
            'started_at' => now(), 'finished_at' => now()]);
        $run->reviews()->create(['round' => 1, 'tree_sha' => $tree, 'output' => ['summary' => 'Ready.', 'evidence' => 'Passed.'],
            'requested_at' => now(), 'verdict' => 'pass', 'summary' => 'Reviewed.', 'evidence_ref' => 'Exact proof.', 'reviewed_at' => now()]);
        $child->update(['status' => TaskStatus::Completed, 'completed_at' => now(), 'accepted_task_run_id' => $run->id]);
        $root->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $session = ['workspaceId' => 'w'.$number, 'tabId' => 't'.$number, 'paneId' => 'p'.$number, 'terminalId' => 'term'.$number,
            'agentName' => 'reviewer-'.$number, 'agentId' => (string) Str::uuid(), 'workingDirectory' => $worktree];
        $final = ['verdict' => 'pass', 'summary' => 'Integrated repair passes.', 'evidence' => 'All root outcomes verified.'];
        $workspace = TaskWorkspace::query()->create(['root_task_id' => $root->id, 'project_id' => 'orbit', 'source_key' => $issue,
            'repository' => $this->directory.'/repository', 'worktree' => $worktree, 'base_sha' => $base,
            'manifest_hash' => app(TaskRuntimePlan::class)->hash($root),
            'configuration' => [...config('task-runtime.projects.orbit'), 'flow_version' => 1, 'socket' => '/unused.sock', 'agent_kind' => 'codex'],
            'herdr_workspace' => ['workspaceId' => 'w'.$number, 'paneId' => 'p'.$number], 'reviewer_session' => $session,
            'final_check' => ['sha' => $candidate, 'exit_code' => 0], 'final_result' => $final]);
        $dispatch = $workspace->dispatches()->create(['step_key' => 'feature:final', 'kind' => 'final_review', 'state' => 'acknowledged',
            'token_hash' => hash('sha256', 'private'.$number), 'handoff_token' => 'private'.$number, 'prompt' => 'Private raw prompt '.$number,
            'session' => $session, 'result' => $final]);
        $id = (string) Str::uuid();
        $payload = ['id' => $id, 'identifier' => $issue, 'title' => $issueTitle ?? $root->title, 'description' => 'Repair the exact failing behavior.',
            'url' => 'https://linear.app/orbit/issue/'.$issue, 'updatedAt' => '2026-09-12T22:00:00Z',
            'state' => ['id' => (string) Str::uuid(), 'name' => 'In Review', 'type' => 'started'], 'delegate' => null, 'assignee' => null,
            'team' => ['id' => config('commander.hermes.orbit_linear_team_id'), 'states' => ['nodes' => []]]];
        foreach (['children', 'inverseRelations', 'labels', 'attachments'] as $collection) {
            $payload[$collection] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
        }
        $this->issues[$id] = $payload;
        $gate = $this->directory.'/gate-'.$number.'.json';
        File::put($gate, 'Retained Builder result.');
        $this->repositories[$workspace->id] = ['candidate' => $candidate, 'tree' => $tree, 'gate_path' => $gate,
            'gate_sha256' => hash('sha256', 'Retained Builder result.'), 'gate_contents' => 'Retained Builder result.',
            'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'discovery'])];
        $request = ['candidate' => $candidate, 'manifest' => $workspace->manifest_hash, 'final_dispatch' => $dispatch->id,
            'issue_id' => $id, 'gate_receipt' => $gate, 'pull_request_body' => 'Exact reviewed acceptance evidence.', 'evidence_files' => []];
        $inputs = app(TaskLandingEvidence::class)->capture($workspace, $request);
        $artifact = sha1($issue.'artifact');
        $ref = 'refs/tags/loop/'.strtolower($issue).'/'.$candidate;
        $package = ['schema' => 1, 'issue_id' => $id, 'issue_key' => $issue, 'candidate_sha' => $candidate, 'artifact_sha' => $artifact,
            'artifact_ref' => $ref, 'input_hash' => TaskLandingData::hash($inputs), 'title' => $issue.': '.$root->title,
            'body' => 'Exact reviewed PR body for '.$issue.'.'];
        $assignment = (string) Str::uuid();

        return TaskLanding::query()->create(['task_workspace_id' => $workspace->id, 'final_dispatch_id' => $dispatch->id,
            'issue_id' => $id, 'candidate_sha' => $candidate, 'input_hash' => $package['input_hash'], 'request' => $request, 'inputs' => $inputs,
            'artifact_ref' => $ref, 'artifact_sha' => $artifact, 'package' => $package, 'package_hash' => TaskLandingData::hash($package),
            'state' => 'approved', 'review_assignment' => $assignment, 'review_session' => $session,
            'review_result' => ['assignment' => $assignment, 'package_hash' => TaskLandingData::hash($package), 'candidate_sha' => $candidate,
                'artifact_sha' => $artifact, 'session' => $session, 'verdict' => 'pass', 'summary' => 'Independent package pass.',
                'evidence' => 'Exact code, artifact, proof and PR wording checked.'], ...$reviewOverrides])->refresh();
    }

    public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $payload = $this->issues[$issueId];

        return new OrbitIssueSnapshot($issueId, $issueKey, $payload, app(OrbitIssueSnapshotFactory::class)->contractHash($payload));
    }

    public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
    {
        return $this->repositories[$workspace->id];
    }

    public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
    {
        return sha1($workspace->source_key.'artifact');
    }

    public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
    {
        throw new LogicException('Closeout must not republish its approved artifact.');
    }
}

final class TaskCloseoutFakeRemote implements TaskCloseoutGitHub, TaskCloseoutRepository
{
    public array $effects = [];

    public array $branches = [];

    public array $publications = [];

    public array $approvals = [];

    public array $merges = [];

    public ?array $reserved = null;

    public string $mainSha;

    public array $failures = [];

    public bool $containsMerge = true;

    public bool $mergeable = true;

    public array $lost = [];

    public array $invisible = [];

    public ?\Closure $beforeWrite = null;

    public int $level;

    public function __construct()
    {
        $this->mainSha = sha1('main');
        $this->level = DB::transactionLevel();
    }

    public function identityHash(): string
    {
        return hash('sha256', 'exact principals');
    }

    public function branch(TaskLanding $landing): ?array
    {
        return $this->branches[$landing->id] ?? null;
    }

    public function push(TaskLanding $landing): void
    {
        $this->write('branch', function () use ($landing): void {
            $this->branches[$landing->id] = ['candidate_sha' => $landing->candidate_sha];
        });
    }

    public function branchRevision(TaskLanding $landing, array $request): array
    {
        throw new LogicException('This fixture does not authorize publication revisions.');
    }

    public function reviseBranch(TaskLanding $landing, array $request): void
    {
        throw new LogicException('This fixture does not authorize publication revisions.');
    }

    public function publicationRevision(TaskLanding $landing, array $request): array
    {
        throw new LogicException('This fixture does not authorize publication revisions.');
    }

    public function revisePublication(TaskLanding $landing, array $request): void
    {
        throw new LogicException('This fixture does not authorize publication revisions.');
    }

    public function publication(TaskLanding $landing): ?array
    {
        return $this->publications[$landing->id] ?? null;
    }

    public function publish(TaskLanding $landing): void
    {
        $this->write('publication', function () use ($landing): void {
            $this->publications[$landing->id] = ['number' => $landing->id, 'url' => 'https://github.com/nckrtl/orbit/pull/'.$landing->id,
                'candidate_sha' => $landing->candidate_sha, 'title_hash' => hash('sha256', $landing->package['title']),
                'body_hash' => hash('sha256', $landing->package['body'])];
        });
    }

    public function approval(TaskLanding $landing, array $pr, string $marker, bool $forMerge = false): ?array
    {
        if ($forMerge && ! $this->mergeable) {
            throw new LogicException('Mergeability is unconfirmed.');
        }

        return $this->approvals[$landing->id] ?? null;
    }

    public function approve(TaskLanding $landing, array $pr, string $marker): void
    {
        $this->write('approval', function () use ($landing, $marker): void {
            $this->approvals[$landing->id] = ['review_id' => $landing->id, 'review_body_hash' => hash('sha256', $marker)];
        });
    }

    public function reservation(TaskLanding $landing, array $pr): ?array
    {
        if ($this->reserved !== null && ($this->reserved['url'] ?? null) !== $pr['url']) {
            throw new LogicException('Another reservation owns main.');
        }

        return $this->reserved;
    }

    public function reserve(TaskLanding $landing, array $pr): void
    {
        $this->write('reservation', function () use ($pr): void {
            $this->reserved = ['url' => $pr['url']];
        });
    }

    public function merged(TaskLanding $landing, array $pr): ?array
    {
        return $this->merges[$landing->id] ?? null;
    }

    public function merge(TaskLanding $landing, array $pr): void
    {
        $this->write('merge', function () use ($landing, $pr): void {
            $this->merges[$landing->id] = ['number' => $pr['number'], 'url' => $pr['url'], 'candidate_sha' => $landing->candidate_sha,
                'merge_sha' => sha1($landing->candidate_sha.'merge')];
        });
    }

    public function release(TaskLanding $landing, array $pr): void
    {
        $this->write('release', function (): void {
            $this->reserved = null;
        });
    }

    public function main(OrbitProjectConfig $configuration): OrbitMainCorrectness
    {
        expect(DB::transactionLevel())->toBe($this->level);

        return new OrbitMainCorrectness($this->mainSha, $this->failures);
    }

    public function contains(OrbitProjectConfig $configuration, string $ancestor, string $main): bool
    {
        return $this->containsMerge;
    }

    public function verify(TaskLanding $landing, array $merge): array
    {
        $this->effects[] = 'verify';

        return ['lineage' => ['flow' => 'discovery', 'candidate' => $landing->candidate_sha, 'merge' => $merge['merge_sha'],
            'tree' => sha1('merged tree')], 'main_sha' => $this->mainSha, 'native_failures' => $this->failures,
            'native_failures_hash' => TaskLandingData::hash($this->failures)];
    }

    private function write(string $name, \Closure $effect): void
    {
        expect(DB::transactionLevel())->toBe($this->level);
        $this->beforeWrite?->__invoke($name);
        $this->effects[] = $name;
        if (! in_array($name, $this->invisible, true)) {
            $effect();
        }
        if (in_array($name, $this->lost, true)) {
            throw new RuntimeException('Lost '.$name.' response.');
        }
    }
}
