<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use App\Models\TaskLandingAmendment;
use LogicException;

final readonly class TaskLandingHistory
{
    public function original(int $workspaceId): ?TaskLanding
    {
        $landings = TaskLanding::query()->where('task_workspace_id', $workspaceId)->orderBy('id')->limit(3)->get();
        $amendments = TaskLandingAmendment::query()->where('task_workspace_id', $workspaceId)
            ->orWhereIn('predecessor_id', $landings->modelKeys())->orWhereIn('successor_id', $landings->modelKeys())->limit(2)->get();
        $amendment = $amendments->first();
        if ($amendment === null && $landings->count() <= 1) {
            return $landings->first();
        }
        if ($amendment === null || $amendments->count() !== 1 || $amendment->task_workspace_id !== $workspaceId || $landings->count() !== 2
            || $amendment->predecessor_id === $amendment->successor_id
            || TaskLandingData::hash($amendment->audit) !== $amendment->audit_hash) {
            throw new LogicException('Unknown or inconsistent landing amendment history.');
        }
        $original = $landings->firstWhere('id', $amendment->predecessor_id);
        $successor = $landings->firstWhere('id', $amendment->successor_id);
        $audit = $amendment->audit;
        if ($original === null || $successor === null || $original->state !== 'rejected'
            || TaskLandingData::hash($original->getRawOriginal()) !== ($audit['predecessor_sha256'] ?? null)
            || $original->package_hash !== ($amendment->request['package_hash'] ?? null)
            || TaskLandingData::hash($original->review_result) !== ($amendment->request['review_hash'] ?? null)
            || $successor->package_hash !== ($audit['package_hash'] ?? null)
            || TaskLandingData::hash($successor->package) !== $successor->package_hash
            || TaskLandingData::hash($successor->inputs) !== $successor->input_hash
            || TaskLandingData::hash(['landing_id' => $original->id, 'request' => $amendment->request, 'audit' => $audit]) !== $amendment->request_hash
            || $successor->request !== [...$original->request, 'pull_request_body' => $amendment->request['pull_request_body']]
            || $successor->package === null || $original->package === null
            || $successor->package['body'] === $original->package['body']
            || array_diff_key($successor->package, ['body' => true]) !== array_diff_key($original->package, ['body' => true])) {
            throw new LogicException('The audited body-only landing binding changed.');
        }
        foreach (['task_workspace_id', 'final_dispatch_id', 'issue_id', 'candidate_sha', 'input_hash', 'inputs', 'artifact_ref', 'artifact_sha'] as $field) {
            if ($original->{$field} !== $successor->{$field}) {
                throw new LogicException('An amended landing may change only PR wording.');
            }
        }

        return $original;
    }

    public function assert(TaskLanding $landing): void
    {
        if ($this->original($landing->task_workspace_id) === null) {
            throw new LogicException('Missing original landing.');
        }
    }

    /** @return array<string, mixed>|null */
    public function reviewContext(TaskLanding $landing): ?array
    {
        if (! $landing->exists) {
            return null;
        }
        $this->assert($landing);
        $amendment = TaskLandingAmendment::query()->where('successor_id', $landing->id)->first();

        return $amendment === null ? null : [
            'amendment_id' => $amendment->id, 'audit_hash' => $amendment->audit_hash,
            'predecessor_id' => $amendment->predecessor_id, 'predecessor_package_hash' => $amendment->request['package_hash'],
            'predecessor_review_hash' => $amendment->request['review_hash'],
            'reason' => $amendment->request['reason'], 'evidence' => $amendment->audit['evidence'],
        ];
    }
}
