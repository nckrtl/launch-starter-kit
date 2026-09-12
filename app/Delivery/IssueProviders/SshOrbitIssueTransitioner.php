<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Contracts\OrbitIssueCompletionTransitioner;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitPlanningResolutionIssueProvider;
use App\Delivery\Contracts\OrbitPlanningResolutionTransitioner;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitPlanningResolutionCorrection;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitIssueTransitioner implements OrbitIssueCompletionTransitioner, OrbitIssueTransitioner, OrbitPlanningResolutionTransitioner, OrbitReviewIssueTransitioner
{
    private const string MUTATION = <<<'GRAPHQL'
mutation LoopState($id: String!, $input: IssueUpdateInput!) {
  issueUpdate(id: $id, input: $input) { success }
}
GRAPHQL;

    public function __construct(
        private OrbitIssueProvider $issues,
        private OrbitActiveIssueProvider $activeIssues,
        private OrbitCloseoutIssueProvider $closeoutIssues,
        private OrbitPlanningResolutionIssueProvider $planningResolutionIssues,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        return $this->transition($current, $expectedContractHash, 'In Progress', false);
    }

    public function transitionToInReview(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        return $this->transition($current, $expectedContractHash, 'In Review', true);
    }

    public function applyPlanningResolution(
        OrbitIssueSnapshot $current,
        OrbitPlanningResolutionCorrection $correction,
    ): OrbitIssueSnapshot {
        [$target, $profile] = $this->planningResolutionConfiguration($current, $correction);
        $correctedPayload = $current->payload;
        $correctedPayload['description'] = $correction->correctedDescription;
        $correctedContractHash = $correction->correctedContractHash;
        $computedCorrectedContractHash = $this->snapshots->contractHash($correctedPayload);
        $backlogStateId = $this->planningResolutionStateId($current, 'Backlog');
        $todoStateId = $this->planningResolutionStateId($current, 'Todo');
        $inProgressStateId = $this->planningResolutionStateId($current, 'In Progress');
        $state = $current->payload['state'] ?? null;
        $stateName = is_array($state) ? ($state['name'] ?? null) : null;
        $stateType = is_array($state) ? ($state['type'] ?? null) : null;
        $stateId = is_array($state) ? ($state['id'] ?? null) : null;
        $isCorrected = hash_equals($correctedContractHash, $current->contractHash)
            && ($current->payload['description'] ?? null) === $correction->correctedDescription;

        if ($stateName === 'Todo' && $stateType === 'unstarted' && $stateId === $todoStateId
            && $isCorrected && $current->payload['assignee'] === null) {
            return $current;
        }

        if ($stateName === 'In Progress'
            && $stateType === 'started'
            && $stateId === $inProgressStateId
            && hash_equals($correctedContractHash, $computedCorrectedContractHash)
            && hash_equals($correction->currentContractHash, $current->contractHash)) {
            $mutationFailure = null;

            try {
                $this->mutate($target, $profile, $current->issueId, [
                    'stateId' => $backlogStateId,
                    'description' => $correction->correctedDescription,
                ]);
            } catch (OrbitIssueTransitionFailed $exception) {
                $mutationFailure = $exception;
            }

            $current = $this->planningResolutionReadBack(
                $current,
                $correction,
                $correctedContractHash,
                'Backlog',
                $backlogStateId,
                $mutationFailure,
            );
            $state = $current->payload['state'] ?? null;

            if (! is_array($state)) {
                throw new OrbitIssueTransitionFailed(
                    'The Orbit planning-resolution Backlog read-back has no state evidence.',
                    ambiguous: true,
                );
            }

            $stateName = $state['name'] ?? null;
            $stateType = $state['type'] ?? null;
            $stateId = $state['id'] ?? null;
            $isCorrected = true;
        }

        $recoverableStage = ($stateName === 'Backlog'
                && $stateType === 'backlog'
                && $stateId === $backlogStateId)
            || ($stateName === 'Todo'
                && $stateType === 'unstarted'
                && $stateId === $todoStateId);

        if (! $recoverableStage || ! $isCorrected) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit planning-resolution correction is not at an exact recoverable Linear stage.',
            );
        }

        $mutationFailure = null;

        try {
            $this->mutate($target, $profile, $current->issueId, [
                'stateId' => $todoStateId,
                'assigneeId' => null,
            ]);
        } catch (OrbitIssueTransitionFailed $exception) {
            $mutationFailure = $exception;
        }

        return $this->planningResolutionReadBack(
            $current,
            $correction,
            $correctedContractHash,
            'Todo',
            $todoStateId,
            $mutationFailure,
        );
    }

    public function transitionToDone(
        string $issueId,
        string $issueKey,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        try {
            $current = $this->closeoutIssues->fetchForCloseout($issueId, $issueKey);
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit issue could not be read before its Linear closeout.',
                previous: $exception,
            );
        }

        [$target, $profile] = $this->completionConfiguration(
            $current,
            $issueId,
            $issueKey,
            $expectedContractHash,
        );
        $targetStateId = $this->completionStateId($current);
        $state = $current->payload['state'];
        $alreadyCompleted = is_array($state)
            && ($state['id'] ?? null) === $targetStateId
            && ($state['name'] ?? null) === 'Done'
            && ($state['type'] ?? null) === 'completed'
            && $current->payload['assignee'] === null
            && $current->payload['delegate'] === null;

        if ($alreadyCompleted) {
            return $current;
        }

        $mutationFailure = null;

        try {
            $this->mutate($target, $profile, $current->issueId, [
                'stateId' => $targetStateId,
                'assigneeId' => null,
                'delegateId' => null,
            ]);
        } catch (OrbitIssueTransitionFailed $exception) {
            $mutationFailure = $exception;
        }

        try {
            $readBack = $this->closeoutIssues->fetchForCloseout($current->issueId, $current->issueKey);
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit issue completion could not be verified by Linear read-back.',
                ambiguous: true,
                mutationFailure: $mutationFailure,
                previous: $exception,
            );
        }

        if ($this->isVerifiedCompletion(
            $readBack,
            $current,
            $expectedContractHash,
            $targetStateId,
        )) {
            return $readBack;
        }

        throw new OrbitIssueTransitionFailed(
            'The Orbit issue completion Linear read-back did not confirm the exact Done state, cleared ownership, and contract.',
            ambiguous: true,
            mutationFailure: $mutationFailure,
        );
    }

    private function transition(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
        string $targetState,
        bool $clearNickAssignee,
    ): OrbitIssueSnapshot {
        [$target, $profile] = $this->configuration(
            $current,
            $expectedContractHash,
            $clearNickAssignee,
        );
        $targetStateId = $this->targetStateId($current, $targetState);
        $state = $current->payload['state'] ?? null;
        $assignee = $current->payload['assignee'] ?? null;
        $attemptedMutation = is_array($state) && (
            ($state['id'] ?? null) !== $targetStateId
            || ($clearNickAssignee && $assignee !== null)
        );
        $mutationFailure = null;

        if ($attemptedMutation) {
            try {
                $input = ['stateId' => $targetStateId];

                if ($clearNickAssignee) {
                    $input['assigneeId'] = null;
                }

                $this->mutate($target, $profile, $current->issueId, $input);
            } catch (OrbitIssueTransitionFailed $exception) {
                $mutationFailure = $exception;
            }
        }

        try {
            $readBack = $clearNickAssignee
                ? $this->activeIssues->fetchActive($current->issueId, $current->issueKey)
                : $this->issues->fetch($current->issueId, $current->issueKey);
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit issue transition could not be verified by Linear read-back.',
                ambiguous: $attemptedMutation,
                mutationFailure: $mutationFailure,
                previous: $exception,
            );
        }

        if ($this->isVerifiedReadBack(
            $readBack,
            $current,
            $expectedContractHash,
            $targetStateId,
            $targetState,
            $clearNickAssignee,
        )) {
            return $readBack;
        }

        throw new OrbitIssueTransitionFailed(
            "The Orbit issue transition Linear read-back did not confirm the exact {$targetState} state, ownership, and contract.",
            ambiguous: $attemptedMutation,
            mutationFailure: $mutationFailure,
        );
    }

    /** @return array{string, string} */
    private function configuration(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
        bool $allowNickAssignee,
    ): array {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');
        $payload = $current->payload;
        $delegate = $payload['delegate'] ?? null;
        $assigneePresent = array_key_exists('assignee', $payload);
        $assignee = $payload['assignee'] ?? null;
        $validAssignee = $assignee === null || ($allowNickAssignee
            && is_array($assignee)
            && ($assignee['id'] ?? null) === $nickId);

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ($allowNickAssignee && (! is_string($nickId) || ! $this->isUuid($nickId)))
            || ! $this->isUuid($current->issueId) || preg_match('/^ORB-[0-9]+$/', $current->issueKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedContractHash) !== 1
            || ! hash_equals($expectedContractHash, $current->contractHash)
            || ($payload['id'] ?? null) !== $current->issueId
            || ($payload['identifier'] ?? null) !== $current->issueKey
            || ! $assigneePresent || ! $validAssignee
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId) {
            throw new OrbitIssueTransitionFailed('The Orbit issue transition input or Hermes configuration is invalid.');
        }

        return [$target, $profile];
    }

    /** @return array{string, string} */
    private function completionConfiguration(
        OrbitIssueSnapshot $current,
        string $issueId,
        string $issueKey,
        string $expectedContractHash,
    ): array {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');
        $payload = $current->payload;
        $state = $payload['state'] ?? null;
        $delegate = $payload['delegate'] ?? null;
        $assignee = $payload['assignee'] ?? null;
        $active = is_array($state)
            && ($state['name'] ?? null) === 'In Review'
            && ($state['type'] ?? null) === 'started'
            && is_array($delegate) && ($delegate['id'] ?? null) === $viewerId
            && $assignee === null;
        $partiallyCompleted = is_array($state)
            && ($state['name'] ?? null) === 'Done'
            && ($state['type'] ?? null) === 'completed'
            && ($delegate === null || (is_array($delegate) && ($delegate['id'] ?? null) === $viewerId))
            && ($assignee === null || (is_array($assignee) && ($assignee['id'] ?? null) === $nickId));

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! is_string($nickId) || ! $this->isUuid($nickId)
            || ! $this->isUuid($issueId) || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedContractHash) !== 1
            || $current->issueId !== $issueId || $current->issueKey !== $issueKey
            || ! hash_equals($expectedContractHash, $current->contractHash)
            || ($payload['id'] ?? null) !== $issueId
            || ($payload['identifier'] ?? null) !== $issueKey
            || ! array_key_exists('assignee', $payload)
            || ! array_key_exists('delegate', $payload)
            || (! $active && ! $partiallyCompleted)) {
            throw new OrbitIssueTransitionFailed('The Orbit issue completion input or Hermes configuration is invalid.');
        }

        return [$target, $profile];
    }

    private function targetStateId(
        OrbitIssueSnapshot $current,
        string $targetState,
    ): string {
        $team = $current->payload['team'] ?? null;
        $states = is_array($team) ? ($team['states'] ?? null) : null;
        $nodes = is_array($states) ? ($states['nodes'] ?? null) : null;
        $currentState = $current->payload['state'] ?? null;

        $allowedCurrentStates = $targetState === 'In Progress'
            ? ['Todo', 'In Progress', 'In Review']
            : ['In Progress', 'In Review'];

        if (! is_array($nodes) || ! is_array($currentState)
            || ! $this->isUuid($currentState['id'] ?? null)
            || ! in_array($currentState['name'] ?? null, $allowedCurrentStates, true)) {
            throw new OrbitIssueTransitionFailed('The Orbit issue has invalid workflow state metadata.');
        }

        $matches = array_values(array_filter(
            $nodes,
            static fn (mixed $state): bool => is_array($state) && ($state['name'] ?? null) === $targetState,
        ));
        $targetStateId = count($matches) === 1 ? ($matches[0]['id'] ?? null) : null;

        if (! is_string($targetStateId) || ! $this->isUuid($targetStateId)) {
            throw new OrbitIssueTransitionFailed("Linear did not provide exactly one valid {$targetState} state for the Orbit team.");
        }

        return $targetStateId;
    }

    private function completionStateId(OrbitIssueSnapshot $current): string
    {
        $team = $current->payload['team'] ?? null;
        $states = is_array($team) ? ($team['states'] ?? null) : null;
        $nodes = is_array($states) ? ($states['nodes'] ?? null) : null;
        $currentState = $current->payload['state'] ?? null;

        if (! is_array($nodes) || ! is_array($currentState)
            || ! $this->isUuid($currentState['id'] ?? null)
            || ! in_array($currentState['name'] ?? null, ['In Review', 'Done'], true)) {
            throw new OrbitIssueTransitionFailed('The Orbit issue has invalid completion workflow state metadata.');
        }

        $matches = array_values(array_filter(
            $nodes,
            static fn (mixed $state): bool => is_array($state) && ($state['name'] ?? null) === 'Done',
        ));
        $targetStateId = count($matches) === 1 ? ($matches[0]['id'] ?? null) : null;

        if (! is_string($targetStateId) || ! $this->isUuid($targetStateId)) {
            throw new OrbitIssueTransitionFailed('Linear did not provide exactly one valid Done state for the Orbit team.');
        }

        return $targetStateId;
    }

    /** @param array{stateId: string, description?: string, assigneeId?: null, delegateId?: null} $fields */
    private function mutate(string $target, string $profile, string $issueId, array $fields): void
    {
        try {
            $input = json_encode([
                'service' => 'linear',
                'document' => self::MUTATION,
                'variables' => ['id' => $issueId, 'input' => $fields],
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);

            if ($result->failed()) {
                throw new RuntimeException('The Hermes Linear mutation process failed.');
            }

            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Hermes Orbit issue transition returned an uncertain result.',
                ambiguous: true,
                previous: $exception,
            );
        }

        if (! is_array($response)) {
            throw new OrbitIssueTransitionFailed(
                'The Hermes Orbit issue transition returned an uncertain result.',
                ambiguous: true,
            );
        }

        $data = $response['data'] ?? null;
        $updated = is_array($data) ? ($data['issueUpdate'] ?? null) : null;

        if (! empty($response['errors']) || ! is_array($updated) || ($updated['success'] ?? null) !== true) {
            throw new OrbitIssueTransitionFailed(
                'Linear did not confirm the Orbit issue transition mutation.',
                ambiguous: true,
            );
        }
    }

    /** @return array{string, string} */
    private function planningResolutionConfiguration(
        OrbitIssueSnapshot $current,
        OrbitPlanningResolutionCorrection $correction,
    ): array {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');
        $payload = $current->payload;
        $delegate = $payload['delegate'] ?? null;
        $assignee = $payload['assignee'] ?? null;
        $validAssignee = $assignee === null
            || (is_array($assignee) && ($assignee['id'] ?? null) === $nickId);

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! is_string($nickId) || ! $this->isUuid($nickId)
            || preg_match('/^[a-f0-9]{64}$/', $correction->currentContractHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $correction->correctedContractHash) !== 1
            || $current->issueId !== $correction->issueId
            || $current->issueKey !== $correction->issueKey
            || ($payload['id'] ?? null) !== $correction->issueId
            || ($payload['identifier'] ?? null) !== $correction->issueKey
            || ! $validAssignee
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || hash('sha256', $correction->correctedDescription) !== $correction->correctedDescriptionHash) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit planning-resolution correction input or Hermes configuration is invalid.',
            );
        }

        return [$target, $profile];
    }

    private function planningResolutionStateId(OrbitIssueSnapshot $current, string $name): string
    {
        $team = $current->payload['team'] ?? null;
        $states = is_array($team) ? ($team['states'] ?? null) : null;
        $nodes = is_array($states) ? ($states['nodes'] ?? null) : null;
        $matches = is_array($nodes) ? array_values(array_filter(
            $nodes,
            static fn (mixed $state): bool => is_array($state) && ($state['name'] ?? null) === $name,
        )) : [];
        $stateId = count($matches) === 1 ? ($matches[0]['id'] ?? null) : null;

        if (! is_string($stateId) || ! $this->isUuid($stateId)) {
            throw new OrbitIssueTransitionFailed(
                "Linear did not provide exactly one valid {$name} state for the Orbit team.",
            );
        }

        return $stateId;
    }

    private function planningResolutionReadBack(
        OrbitIssueSnapshot $before,
        OrbitPlanningResolutionCorrection $correction,
        string $correctedContractHash,
        string $stateName,
        string $stateId,
        ?OrbitIssueTransitionFailed $mutationFailure,
    ): OrbitIssueSnapshot {
        try {
            $readBack = $this->planningResolutionIssues->fetchForPlanningResolution(
                $correction->issueId,
                $correction->issueKey,
            );
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit planning-resolution correction could not be verified by Linear read-back.',
                ambiguous: true,
                mutationFailure: $mutationFailure,
                previous: $exception,
            );
        }

        $expectedType = $stateName === 'Backlog' ? 'backlog' : 'unstarted';
        $state = $readBack->payload['state'] ?? null;
        $beforePayload = $before->payload;
        $afterPayload = $readBack->payload;
        unset($beforePayload['description'], $beforePayload['state'], $beforePayload['updatedAt']);
        unset($afterPayload['description'], $afterPayload['state'], $afterPayload['updatedAt']);
        $ownershipMatches = $stateName === 'Todo'
            ? ($readBack->payload['assignee'] ?? null) === null
            : ($readBack->payload['assignee'] ?? null) === ($before->payload['assignee'] ?? null);
        unset($beforePayload['assignee'], $afterPayload['assignee']);

        if ($readBack->issueId === $before->issueId
            && $readBack->issueKey === $before->issueKey
            && hash_equals($correctedContractHash, $readBack->contractHash)
            && ($readBack->payload['description'] ?? null) === $correction->correctedDescription
            && is_array($state)
            && ($state['id'] ?? null) === $stateId
            && ($state['name'] ?? null) === $stateName
            && ($state['type'] ?? null) === $expectedType
            && $ownershipMatches
            && $afterPayload === $beforePayload) {
            return $readBack;
        }

        throw new OrbitIssueTransitionFailed(
            "The Orbit planning-resolution Linear read-back did not confirm the exact corrected {$stateName} contract.",
            ambiguous: true,
            mutationFailure: $mutationFailure,
        );
    }

    private function isVerifiedReadBack(
        OrbitIssueSnapshot $readBack,
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
        string $targetStateId,
        string $targetState,
        bool $clearedAssignee,
    ): bool {
        $state = $readBack->payload['state'] ?? null;

        return $readBack->issueId === $current->issueId
            && $readBack->issueKey === $current->issueKey
            && hash_equals($expectedContractHash, $readBack->contractHash)
            && is_array($state)
            && ($state['id'] ?? null) === $targetStateId
            && ($state['name'] ?? null) === $targetState
            && ($state['type'] ?? null) === 'started'
            && ($readBack->payload['delegate'] ?? null) === ($current->payload['delegate'] ?? null)
            && array_key_exists('assignee', $readBack->payload)
            && $readBack->payload['assignee'] === ($clearedAssignee ? null : $current->payload['assignee']);
    }

    private function isVerifiedCompletion(
        OrbitIssueSnapshot $readBack,
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
        string $targetStateId,
    ): bool {
        $state = $readBack->payload['state'] ?? null;

        return $readBack->issueId === $current->issueId
            && $readBack->issueKey === $current->issueKey
            && hash_equals($expectedContractHash, $readBack->contractHash)
            && is_array($state)
            && ($state['id'] ?? null) === $targetStateId
            && ($state['name'] ?? null) === 'Done'
            && ($state['type'] ?? null) === 'completed'
            && array_key_exists('assignee', $readBack->payload)
            && $readBack->payload['assignee'] === null
            && array_key_exists('delegate', $readBack->payload)
            && $readBack->payload['delegate'] === null;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
