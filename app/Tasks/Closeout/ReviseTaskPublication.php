<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use LogicException;

final readonly class ReviseTaskPublication
{
    public function __construct(private TaskCloseoutLock $lock, private TaskCloseoutContext $context,
        private TaskCloseoutLedger $ledger, private TaskCloseoutRepository $repository, private TaskCloseoutGitHub $github) {}

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function handle(int $id, string $package, array $request, bool $exclusive, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Confirm exclusive revision ownership and enable Tasks before applying.');
        }
        $operation = function () use ($id, $package, $request, $apply): array {
            $landing = $this->context->approved($id, $package);
            $request = TaskPublicationRevisionData::request($landing, $request);
            $input = TaskPublicationRevisionData::input($landing, $request, $this->github->identityHash());
            if (TaskCloseoutOperation::query()->where('task_landing_id', $id)
                ->whereNotIn('operation', ['revision-branch', 'revision-publication'])->exists()) {
                throw new LogicException('Publication revision must precede ordinary closeout; it cannot rewrite existing closeout operations.');
            }
            $observed = $this->observe($landing, $request, $input);
            if (! $apply) {
                return ['applied' => false, 'package_hash' => $package, 'revision_hash' => TaskLandingData::hash($input),
                    'observed' => $observed, 'actions' => ['Exact-lease fast-forward of the owned branch.',
                        'Guarded existing PR body PATCH; no server-side compare-and-swap.',
                        'Then use ordinary closeout publish for exact review approval.']];
            }
            $branchResult = TaskPublicationRevisionData::branch($landing, $request);
            $branch = $this->ledger->step($landing, 'revision-branch', $input,
                fn (): ?array => $this->observe($landing, $request, $input)['state'] === 'before' ? null : $branchResult,
                function () use ($landing, $request): void {
                    if ($this->github->publicationRevision($landing, $request)['state'] !== 'before') {
                        throw new LogicException('The revision PR changed before the branch write.');
                    }
                    $this->repository->reviseBranch($landing, $request);
                }, fn (): array => $this->beforeWrite($landing, $request, $input, 'before'));
            $observed = $this->observe($landing, $request, $input);
            if ($branch !== $branchResult || $observed['state'] === 'before') {
                throw new LogicException('The retained branch revision no longer matches current remote state.');
            }
            $publicationResult = TaskPublicationRevisionData::publication($landing, $request);
            $publication = $this->ledger->step($landing, 'revision-publication', $input,
                fn (): ?array => $this->observe($landing, $request, $input)['state'] === 'after' ? $publicationResult : null,
                function () use ($landing, $request): void {
                    if ($this->repository->branchRevision($landing, $request)['state'] !== 'after') {
                        throw new LogicException('The revision branch changed before the PR body write.');
                    }
                    $this->github->revisePublication($landing, $request);
                }, fn (): array => $this->beforeWrite($landing, $request, $input, 'branch_changed'));
            $observed = $this->observe($landing, $request, $input);
            if ($publication !== $publicationResult || $observed['state'] !== 'after') {
                throw new LogicException('The retained PR revision no longer matches current remote state.');
            }

            return ['applied' => true, 'package_hash' => $package, 'revision_hash' => TaskLandingData::hash($input),
                'branch' => $branch, 'publication' => $publication, 'observed' => $observed,
                'next' => 'Preview ordinary tasks:closeout --stage=publish; no review approval or merge was issued here.'];
        };

        return $apply ? $this->lock->handle($operation) : $operation();
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function observe(TaskLanding $landing, array $request, array $input): array
    {
        $this->context->approved($landing->id, (string) $landing->package_hash);
        $this->context->observe($landing);
        $branch = $this->repository->branchRevision($landing, $request);
        $pr = $this->github->publicationRevision($landing, $request);
        $state = match ([$branch['state'] ?? null, $pr['state'] ?? null]) {
            ['before', 'before'] => 'before',
            ['after', 'branch_changed'] => 'branch_changed',
            ['after', 'after'] => 'after',
            default => throw new LogicException('Branch and PR observations disagree; reconcile without another write.'),
        };
        $records = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->whereIn('operation', ['revision-branch', 'revision-publication'])->get()->keyBy('operation');
        foreach (['revision-branch', 'revision-publication'] as $name) {
            $record = $records->get($name);
            $changed = $name === 'revision-branch' ? $state !== 'before' : $state === 'after';
            if ($record !== null && ($record->package_hash !== $landing->package_hash
                || $record->input_hash !== TaskLandingData::hash($input)
                || TaskLandingData::hash($record->input) !== $record->input_hash)) {
                throw new LogicException('The requested revision differs from its retained immutable intent.');
            }
            if ($changed && ($record === null || ! in_array($record->state, ['intended', 'unknown', 'completed'], true))) {
                throw new LogicException('Do not adopt externally changed revision effects without a matching retained write intent.');
            }
            if (! $changed && $record?->state === 'completed') {
                throw new LogicException('A completed revision effect has drifted back to its old state.');
            }
            if ($record?->state === 'completed') {
                $expected = $name === 'revision-branch' ? TaskPublicationRevisionData::branch($landing, $request)
                    : TaskPublicationRevisionData::publication($landing, $request);
                if ($record->result !== $expected) {
                    throw new LogicException('A completed revision result differs from the approved package.');
                }
            }
        }

        return ['state' => $state, 'branch' => $branch, 'pull_request' => $pr];
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function beforeWrite(TaskLanding $landing, array $request, array $input, string $state): array
    {
        $observed = $this->observe($landing, $request, $input);
        if ($observed['state'] !== $state) {
            throw new LogicException('The exact revision preimage changed immediately before write intent.');
        }

        return $observed;
    }
}
