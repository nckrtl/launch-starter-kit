<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use App\Models\TaskLandingReviewRecovery;
use App\Tasks\Runtime\TaskRuntimeLock;
use App\Tasks\TaskPayload;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final readonly class RecoverTaskLandingReview
{
    public function __construct(private ReviewTaskLanding $reviews, private TaskLandingEvidence $evidence,
        private TaskLandingReviewer $reviewer, private TaskRuntimeLock $lock, private TaskPayload $payloads) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $id, array $request, bool $exclusive, ?string $requestHash = null, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Confirm exclusive ownership and enable Tasks before applying review transport recovery.');
        }
        $request = TaskLandingReviewRefusal::normalize($request);
        $existing = $this->existing($id, $request, $requestHash, $apply);
        if ($existing !== null) {
            return $existing;
        }
        if ($apply && DB::transactionLevel() !== 0) {
            throw new LogicException('Recovery must commit its own intent before sending; an outer transaction is not allowed.');
        }
        $landing = TaskLanding::query()->findOrFail($id);
        $this->assertOriginal($landing, $request);
        $workspace = $this->reviews->observe($landing);
        $session = $landing->review_session ?? throw new LogicException('Missing original reviewer.');
        $observed = $this->reviewer->observe($workspace, $session, true);
        HerdrTaskLandingReviewer::assertIdentity($session, $observed);
        $refusal = TaskLandingReviewRefusal::read($landing, $request);
        $this->evidence->assertNoSecrets($workspace, $refusal);
        if (str_contains(TaskLandingData::json($refusal), $landing->review_token ?? throw new LogicException('Missing original private receipt.'))) {
            throw new LogicException('Transport evidence must not contain the private review token.');
        }
        $prompt = $this->reviews->referencePrompt($landing, $session,
            $landing->review_assignment ?? throw new LogicException('Missing original assignment.'),
            $landing->review_token ?? throw new LogicException('Missing original private receipt.'));
        $transport = TaskLandingReviewTransport::inspect($session, $prompt);
        $originalHash = TaskLandingData::hash($landing->getRawOriginal());
        $pins = ['request' => $request, 'original_landing_sha256' => $originalHash,
            'observed_session' => $observed, 'transport' => $transport];
        $hash = TaskLandingData::hash(['schema' => 1, 'landing_id' => $id, ...$pins]);
        $preview = ['applied' => false, 'request_hash' => $hash, 'landing_id' => $id, ...$pins, 'refusal' => $refusal];
        if (! $apply) {
            return $preview;
        }
        if ($requestHash === null || ! hash_equals($hash, $requestHash)) {
            throw new LogicException('Preview and pin the exact recovery request hash before applying.');
        }
        $recovery = $this->lock->handle($workspace->id, function () use ($id, $workspace, $landing, $request, $requestHash, $originalHash, $observed, $refusal, $prompt, $transport): ?TaskLandingReviewRecovery {
            if ($this->existing($id, $request, $requestHash, true) !== null) {
                return null;
            }
            $landing->refresh();
            $this->assertOriginal($landing, $request);
            $this->evidence->guard($workspace, $landing->request, $landing->inputs);
            if (TaskLandingData::hash($landing->getRawOriginal()) !== $originalHash
                || TaskLandingReviewRefusal::read($landing, $request) !== $refusal) {
                throw new LogicException('Original landing or refusal evidence changed during recovery preflight.');
            }
            TaskLandingReviewTransport::inspect($landing->review_session ?? [], $prompt);

            return TaskLandingReviewRecovery::query()->create(['task_landing_id' => $id, 'review_assignment' => $landing->review_assignment,
                'request_hash' => $requestHash, 'inputs' => $request,
                'evidence' => ['original_landing_sha256' => $originalHash, 'refusal' => $refusal], 'review_session' => $observed,
                'reference_prompt' => $prompt, 'prompt_sha256' => $transport['prompt_sha256'], 'prompt_bytes' => $transport['prompt_bytes'],
                'wire_sha256' => $transport['wire_sha256'], 'wire_bytes' => $transport['wire_bytes'], 'state' => 'intended']);
        });
        if ($recovery === null) {
            return $this->existing($id, $request, $requestHash, true) ?? throw new LogicException('Missing claimed recovery.');
        }
        try {
            $landing->refresh();
            $this->assertOriginal($landing, $request);
            $workspace = $this->reviews->observe($landing);
            $current = $this->reviewer->observe($workspace, $session, true);
            if (! $this->payloads->matches($observed, $current)
                || TaskLandingReviewRefusal::read($landing, $request) !== $refusal
                || TaskLandingData::hash($landing->getRawOriginal()) !== $originalHash) {
                throw new LogicException('Recovery evidence or the retained reviewer changed after claiming.');
            }
            $this->evidence->guard($workspace, $landing->request, $landing->inputs);
            $sent = $this->reviewer->promptOnce($workspace, $current, $recovery->reference_prompt);
            HerdrTaskLandingReviewer::assertIdentity($current, $sent);
            $this->lock->handle($workspace->id, fn (): bool => $recovery->update(['state' => 'sent']));
        } catch (Throwable $exception) {
            $this->lock->handle($workspace->id, function () use ($recovery): void {
                $recovery->refresh();
                if ($recovery->state === 'intended') {
                    $recovery->update(['state' => 'unknown', 'error' => 'The one-shot recovery did not complete normally. Never resend; await a genuine existing-assignment receipt or inspect retained evidence.']);
                }
            });
            throw $exception;
        }

        return ['applied' => true, 'recovery' => $recovery->fresh()?->toArray(), 'landing' => $landing->fresh()?->toArray()];
    }

    /** @param array<string, mixed> $request */
    private function assertOriginal(TaskLanding $landing, array $request): void
    {
        $this->reviews->assertPackage($landing, TaskLandingData::text($request, 'package_hash'));
        if ($landing->state !== 'review_unknown' || $landing->review_result !== null || $landing->review_assignment === null
            || $landing->review_prompt === null || $landing->review_token === null || $landing->review_token_hash === null
            || hash('sha256', $landing->review_token) !== $landing->review_token_hash
            || $landing->review_assignment !== $request['assignment'] || $landing->candidate_sha !== $request['candidate_sha']
            || $landing->artifact_sha !== $request['artifact_sha'] || $landing->input_hash !== $request['input_hash']
            || hash('sha256', $landing->review_prompt) !== $request['original_prompt_sha256']
            || ! $this->payloads->matches($landing->review_session ?? [], TaskLandingData::object($request['session']))) {
            throw new LogicException('Recovery requires the unchanged uncertain assignment, package, evidence, prompt and reviewer with no verdict.');
        }
        $line = TaskLandingReviewTransport::line($landing->review_session ?? [], $landing->review_prompt);
        if (strlen($line) !== $request['original_wire_bytes'] || hash('sha256', $line) !== $request['original_wire_sha256']) {
            throw new LogicException('The refused serialized request differs from the original supplemental prompt.');
        }
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function existing(int $id, array $request, ?string $hash, bool $apply): ?array
    {
        $recovery = TaskLandingReviewRecovery::query()->where('task_landing_id', $id)->first();
        if ($recovery === null) {
            return null;
        }
        if (! $this->payloads->matches($recovery->inputs, $request)
            || ($apply && ($hash === null || ! hash_equals($recovery->request_hash, $hash)))) {
            throw new LogicException('This landing already has a conflicting one-shot recovery intent.');
        }

        return ['applied' => false, 'recovery' => $recovery->toArray(), 'landing' => TaskLanding::query()->findOrFail($id)->toArray()];
    }
}
