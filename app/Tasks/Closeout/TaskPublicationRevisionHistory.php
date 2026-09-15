<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use LogicException;

final readonly class TaskPublicationRevisionHistory
{
    public function __construct(private TaskCloseoutContext $context) {}

    /** Completed revision, not a generic pass, authorizes superseding the same bot's retained changes request.
     * @return array<string,mixed>|null
     */
    public function authorized(TaskLanding $landing, string $identity): ?array
    {
        if (! $landing->exists) {
            return null;
        }
        $operations = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->whereIn('operation', ['revision-branch', 'revision-publication'])->get()->keyBy('operation');
        if ($operations->isEmpty()) {
            return null;
        }
        $this->context->approved($landing->id, (string) $landing->package_hash);
        $branch = $operations->get('revision-branch');
        $publication = $operations->get('revision-publication');
        if ($branch === null || $publication === null || $branch->state !== 'completed' || $publication->state !== 'completed') {
            throw new LogicException('Reconcile both publication revision writes before issuing a new approval.');
        }
        $request = TaskLandingData::object($branch->input['request'] ?? null);
        $input = TaskPublicationRevisionData::input($landing, $request, $identity);
        foreach ([$branch, $publication] as $operation) {
            if ($operation->package_hash !== $landing->package_hash || $operation->input_hash !== TaskLandingData::hash($input)
                || TaskLandingData::hash($operation->input) !== $operation->input_hash) {
                throw new LogicException('The retained publication revision authority changed.');
            }
        }
        if ($branch->result !== TaskPublicationRevisionData::branch($landing, $request)
            || $publication->result !== TaskPublicationRevisionData::publication($landing, $request)) {
            throw new LogicException('The completed publication revision does not match the reviewed package.');
        }

        return $request;
    }
}
