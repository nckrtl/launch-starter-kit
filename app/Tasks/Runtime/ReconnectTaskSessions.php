<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Delivery;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskLanding;
use App\Models\TaskReattempt;
use App\Models\TaskRecovery;
use App\Models\TaskRun;
use App\Models\TaskSessionReconnection;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\TaskGraph;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;

final readonly class ReconnectTaskSessions
{
    public function __construct(private TaskRuntimeLock $lock, private TaskRuntimePlan $plans, private TaskGraph $graph,
        private GitTaskWorktree $git, private TaskSessionObserver $observer, private TaskSessionTranscript $transcripts) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $workspaceId, array $request, bool $exclusive, ?string $expectedHash = null, bool $apply = false): array
    {
        $this->request($request);
        $request = Data::object($this->normalize($request));
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Reconnection requires exclusive ownership and an enabled runtime for apply.');
        }
        $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
        $existing = TaskSessionReconnection::query()->where('task_workspace_id', $workspaceId)->first();
        if ($existing !== null) {
            if ($existing->request !== $request || ($expectedHash !== null && $existing->request_hash !== $expectedHash)) {
                throw new LogicException('This workspace already has a conflicting reconnection; audit history cannot be replaced.');
            }

            return ['recorded' => true, 'request_hash' => $existing->request_hash, 'audit' => $existing->toArray()];
        }
        $dispatch = $this->guard($workspace, $request);
        $binding = $this->binding($workspace);
        $source = $this->source($workspace, Data::text($request, 'head'));
        $reviewer = $workspace->dispatches()->where('kind', 'commit')->orderByDesc('id')->firstOrFail();
        $roles = Data::object($request['sessions']);
        $sessions = [];
        foreach (['implementer' => $dispatch, 'reviewer' => $reviewer] as $role => $origin) {
            $input = Data::object($roles[$role]);
            $previous = $origin->session ?? throw new LogicException('Missing original agent session.');
            $current = Data::object($input['session']);
            $conversation = Data::text($input, 'conversation_id');
            $oldIdentity = HerdrTaskLandingReviewer::identity($previous);
            $newIdentity = HerdrTaskLandingReviewer::identity($current);
            if ($oldIdentity['terminalId'] === $newIdentity['terminalId']
                || array_diff_key($oldIdentity, ['terminalId' => true, 'agentId' => true]) !== array_diff_key($newIdentity, ['terminalId' => true, 'agentId' => true])
                || ($oldIdentity['agentId'] !== null && $oldIdentity['agentId'] !== $conversation)
                || ($newIdentity['agentId'] !== null && $newIdentity['agentId'] !== $conversation)) {
                throw new LogicException('Reconnect only the same named pane, worktree and native conversation to a new terminal.');
            }
            $this->transcripts->verify($workspace, $origin, $conversation, Data::object($input['transcript']));
            $observation = $this->observer->observe($workspace, $current, $conversation, true);
            $sessions[$role] = ['dispatch_id' => $origin->id, 'previous' => $previous,
                'current' => HerdrTaskLandingReviewer::identity($observation['session']),
                'conversation_id' => $conversation, 'process' => $observation['process']];
        }
        if ($sessions['implementer']['conversation_id'] === $sessions['reviewer']['conversation_id']
            || $sessions['implementer']['process']['pid'] === $sessions['reviewer']['process']['pid']
            || $sessions['implementer']['current']['terminalId'] === $sessions['reviewer']['current']['terminalId']) {
            throw new LogicException('Worker and retained reviewer require distinct native processes and terminals.');
        }
        $this->guard($workspace->refresh(), $request);
        if ($binding !== $this->binding($workspace) || $source !== $this->source($workspace, Data::text($request, 'head'))) {
            throw new LogicException('Task or source state changed during reconnection observation.');
        }
        $binding['source'] = $source;
        $hash = Data::hash(['workspace_id' => $workspaceId, 'request' => $request, 'binding' => $binding, 'sessions' => $sessions]);
        if (! $apply) {
            return ['recorded' => false, 'request_hash' => $hash, 'binding' => $binding, 'sessions' => $sessions];
        }
        if ($expectedHash === null || ! hash_equals($hash, $expectedHash)) {
            throw new LogicException('Apply requires the unchanged exact preview request hash.');
        }

        return $this->lock->handle($workspaceId, function () use ($workspaceId, $request, $binding, $sessions, $hash): array {
            if (TaskSessionReconnection::query()->where('task_workspace_id', $workspaceId)->exists()) {
                throw new LogicException('A concurrent reconnection was already recorded; inspect it without resending.');
            }
            $current = TaskWorkspace::query()->findOrFail($workspaceId);
            $dispatch = $this->guard($current, $request);
            if (array_diff_key($binding, ['source' => true]) !== $this->binding($current)) {
                throw new LogicException('Task ownership changed before reconnection could be recorded.');
            }
            foreach ($sessions as $session) {
                $observed = $this->observer->observe($current, $session['current'], $session['conversation_id'], true);
                if ($observed['process'] !== $session['process']
                    || HerdrTaskLandingReviewer::identity($observed['session']) !== $session['current']) {
                    throw new LogicException('The resumed process changed before reconnection could be recorded.');
                }
            }
            if ($binding['source'] !== $this->source($current, Data::text($request, 'head'))
                || array_diff_key($binding, ['source' => true]) !== $this->binding($current->refresh())) {
                throw new LogicException('Source or task state changed before reconnection could be recorded.');
            }
            $audit = TaskSessionReconnection::query()->create(['task_workspace_id' => $workspaceId,
                'task_agent_dispatch_id' => $dispatch->id, 'request_hash' => $hash, 'request' => $request,
                'binding' => $binding, 'sessions' => $sessions, 'created_at' => now()]);
            $current->update(['reviewer_session' => $sessions['reviewer']['current']]);

            return ['recorded' => true, 'request_hash' => $hash, 'audit' => $audit->fresh()?->toArray()];
        });
    }

    /** @param array<string, mixed> $request */
    private function request(array $request): void
    {
        if (array_diff(array_keys($request), ['dispatch_id', 'head', 'manifest', 'reason', 'sessions']) !== []
            || ! is_int($request['dispatch_id'] ?? null) || $request['dispatch_id'] < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', Data::text($request, 'manifest')) !== 1) {
            throw new LogicException('Pin the current implementation dispatch, head and approved manifest.');
        }
        GitObjectId::validate(Data::text($request, 'head'));
        Data::text($request, 'reason', 4000);
        $roles = Data::object($request['sessions'] ?? null);
        if (count($roles) !== 2 || ! isset($roles['implementer'], $roles['reviewer'])) {
            throw new LogicException('Reconnect the implementer and retained reviewer together.');
        }
        foreach ($roles as $value) {
            $role = Data::object($value);
            if (array_diff(array_keys($role), ['conversation_id', 'transcript', 'session']) !== [] || ! Str::isUuid(Data::text($role, 'conversation_id'))) {
                throw new LogicException('Pin each original native conversation and private transcript.');
            }
            $session = Data::object($role['session'] ?? null);
            if (array_diff(array_keys($session), array_keys(HerdrTaskLandingReviewer::identity($session))) !== []) {
                throw new LogicException('Use only the exact normalized Herdr session identity.');
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function guard(TaskWorkspace $workspace, array $request): TaskAgentDispatch
    {
        $root = $workspace->root()->firstOrFail();
        $dispatch = $workspace->dispatches()->orderByDesc('id')->first();
        $run = $dispatch?->run()->first();
        $task = $run?->task()->first();
        $reviewer = $workspace->dispatches()->where('kind', 'commit')->orderByDesc('id')->first();
        $accepted = TaskRun::query()->where('root_task_id', $root->id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
        $remaining = array_values(array_filter($this->graph->orderedChildren($root), fn ($item): bool => $item->status !== TaskStatus::Completed));
        if ($workspace->project_id !== 'orbit' || ($workspace->configuration['agent_kind'] ?? null) !== 'codex'
            || ($workspace->configuration['flow_version'] ?? null) !== 1 || $root->status !== TaskStatus::Pending
            || $workspace->attention !== null || $workspace->final_check !== null || $workspace->final_result !== null
            || $workspace->reviewer_session === null || $workspace->herdr_workspace === null
            || $dispatch === null || $dispatch->id !== $request['dispatch_id'] || $dispatch->kind !== 'implement'
            || $dispatch->state !== 'sent' || $dispatch->round !== 0 || $dispatch->result !== null || $dispatch->error !== null || $dispatch->session === null
            || $run === null || $run->status !== TaskRunStatus::Running || $run->attempt !== 1 || $run->reviews()->exists()
            || $run->active_root_task_id !== $root->id || $run->active_task_id !== $task?->id
            || $task?->status !== TaskStatus::Running || ($remaining[0]->id ?? null) !== $task->id
            || $run->base_sha !== $request['head'] || $accepted === null || $accepted->commit_sha !== $request['head']
            || $reviewer === null || $reviewer->state !== 'acknowledged' || $reviewer->task_run_id !== $accepted->id
            || $run->worker_ref !== ($dispatch->session['agentName'] ?? null)
            || $run->reviewer_ref !== ($workspace->reviewer_session['agentName'] ?? null)
            || $run->reviewer_ref === $run->worker_ref
            || $this->plans->hash($root) !== $request['manifest'] || $this->plans->effectiveHash($workspace) !== $request['manifest']
            || $workspace->dispatches()->whereNotIn('state', ['acknowledged', 'check_failed'])->count() !== 1
            || TaskRecovery::query()->where('root_task_id', $root->id)->exists()
            || TaskReattempt::query()->where('task_workspace_id', $workspace->id)->exists()
            || TaskFinalContinuation::query()->where('task_workspace_id', $workspace->id)->exists()
            || TaskLanding::query()->where('task_workspace_id', $workspace->id)->exists()
            || Delivery::query()->active()->where(fn ($query) => $query->where('worktree_path', $workspace->worktree)->orWhere('external_issue_key', $workspace->source_key))->exists()) {
            throw new LogicException('Only the unchanged initial sent implementation after an accepted child supports session reconnection.');
        }
        HerdrTaskLandingReviewer::assertIdentity($reviewer->session ?? [], $workspace->reviewer_session);
        $serialized = Data::json($request);
        foreach ($workspace->dispatches()->get() as $instruction) {
            if (str_contains($serialized, $instruction->handoff_token)) {
                throw new LogicException('Reconnection requests must not contain handoff secrets.');
            }
        }

        return $dispatch;
    }

    /** @return array<string, mixed> */
    private function binding(TaskWorkspace $workspace): array
    {
        $root = $workspace->root()->firstOrFail();

        return ['workspace_id' => $workspace->id, 'root_id' => $root->id,
            'workspace_hash' => Data::hash($workspace->getRawOriginal()),
            'ledger_hash' => Data::hash([$root->toArray(), $root->children()->with('runs.reviews')->orderBy('id')->get()->toArray(),
                $workspace->dispatches()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all()])];
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map($this->normalize(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function source(TaskWorkspace $workspace, string $head): array
    {
        if ($this->git->inspectAssignment($workspace->repository, $workspace->worktree) !== $head) {
            throw new LogicException('The interrupted implementation HEAD changed.');
        }
        $run = fn (array $args): string => Process::path($workspace->worktree)->timeout(30)
            ->env(array_merge(TaskProcessEnvironment::isolated(), ['GIT_OPTIONAL_LOCKS' => '0', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_NO_LAZY_FETCH' => '1']))
            ->run(['git', '-c', 'core.fsmonitor=false', ...$args])->throw()->output();
        $files = [];
        foreach (explode("\0", $run(['ls-files', '--others', '--exclude-standard', '-z'])) as $path) {
            if ($path === '') {
                continue;
            }
            $full = $workspace->worktree.'/'.$path;
            $digest = is_link($full) ? hash('sha256', (string) readlink($full)) : hash_file('sha256', $full);
            if ($digest === false) {
                throw new LogicException('Cannot fingerprint the interrupted untracked work.');
            }
            $files[$path] = [$digest, fileperms($full)];
        }

        return ['head' => $head, 'status' => hash('sha256', $run(['status', '--porcelain=v1', '-z', '--untracked-files=all'])),
            'working' => hash('sha256', $run(['diff', '--binary', '--no-ext-diff', '--no-textconv', 'HEAD', '--'])),
            'staged' => hash('sha256', $run(['diff', '--cached', '--binary', '--no-ext-diff', '--no-textconv', 'HEAD', '--'])),
            'untracked' => $files];
    }
}
