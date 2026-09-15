<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskLandingAmendment;
use App\Tasks\Runtime\TaskRuntimeLock;
use App\Tasks\TaskPayload;
use InvalidArgumentException;
use LogicException;

final readonly class AmendTaskLanding
{
    public function __construct(private TaskRuntimeLock $lock, private TaskLandingHistory $history,
        private TaskLandingPackage $packages, private ReviewTaskLanding $reviews, private TaskLandingEvidence $evidence,
        private TaskLandingReviewer $reviewer, private TaskPayload $payloads) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $id, array $request, bool $exclusive, ?string $expectedHash = null, bool $apply = false): array
    {
        if ($id < 1 || ! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Confirm exclusive ownership and enable Tasks before applying a body-only amendment.');
        }
        $request = $this->normalize($request);
        $landing = TaskLanding::query()->findOrFail($id);
        $original = $this->history->original($landing->task_workspace_id);
        if ($original?->id !== $id) {
            throw new LogicException('Only the original rejected landing may have one body-only amendment.');
        }
        $existing = $this->existing($landing, $request, $expectedHash, $apply);
        if ($existing !== null) {
            return $existing;
        }
        $this->eligible($landing, $request);
        $workspace = $landing->workspace()->firstOrFail();
        $session = $landing->review_session ?? throw new LogicException('Missing retained reviewer.');
        HerdrTaskLandingReviewer::assertIdentity($session, $workspace->reviewer_session ?? []);
        $observed = $this->reviewer->observe($workspace, $session, true);
        HerdrTaskLandingReviewer::assertIdentity($session, $observed);
        $this->reviews->observe($landing);
        $correction = $this->correction($landing, $request);
        $this->evidence->assertNoSecrets($workspace, [$request, $correction]);
        $json = TaskLandingData::json([$request, $correction]);
        foreach ([$landing->review_token, $landing->review_token_hash, $landing->review_prompt] as $private) {
            if (is_string($private) && $private !== '' && (str_contains($json, $private)
                || str_contains($json, trim(TaskLandingData::json($private), "\n\"")))) {
                throw new LogicException('Amendment evidence includes private supplemental review credentials or prompt.');
            }
        }
        $successor = new TaskLanding;
        foreach (['task_workspace_id', 'final_dispatch_id', 'issue_id', 'candidate_sha', 'input_hash', 'inputs', 'artifact_ref', 'artifact_sha'] as $field) {
            $successor->{$field} = $landing->{$field};
        }
        $successor->request = [...$landing->request, 'pull_request_body' => $request['pull_request_body']];
        $artifact = $landing->artifact_sha ?? throw new LogicException('Missing immutable artifact.');
        if ($this->packages->render($workspace, $landing, $artifact) !== $landing->package) {
            throw new LogicException('The original package must match the canonical renderer before body-only amendment.');
        }
        $successor->package = $this->packages->render($workspace, $successor, $artifact);
        $successor->package_hash = TaskLandingData::hash($successor->package);
        $successor->state = 'packaged';
        $this->evidence->assertNoSecrets($workspace, $successor->package);
        $audit = ['predecessor_sha256' => TaskLandingData::hash($landing->getRawOriginal()),
            'package_hash' => $successor->package_hash, 'input_hash' => $landing->input_hash,
            'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha,
            'gate_sha256' => TaskLandingData::text(TaskLandingData::object($landing->inputs['repository'] ?? null), 'gate_sha256'),
            'review_session' => $observed, 'evidence' => $correction];
        $hash = TaskLandingData::hash(['landing_id' => $id, 'request' => $request, 'audit' => $audit]);
        if (! $apply) {
            return ['applied' => false, 'predecessor_id' => $id, 'proposal_hash' => $hash,
                'package_hash' => $successor->package_hash, 'package' => $successor->package, 'audit' => $audit];
        }
        if ($expectedHash === null || ! hash_equals($hash, $expectedHash)) {
            throw new LogicException('Preview and pin the exact amendment proposal hash before applying.');
        }

        return $this->lock->handle($workspace->id, function () use ($landing, $workspace, $request, $expectedHash, $audit, $successor): array {
            $this->history->assert($landing);
            $existing = $this->existing($landing, $request, $expectedHash, true);
            if ($existing !== null) {
                return $existing;
            }
            $landing->refresh();
            $this->eligible($landing, $request);
            $this->evidence->guard($workspace, $landing->request, $landing->inputs);
            if (TaskLandingData::hash($landing->getRawOriginal()) !== $audit['predecessor_sha256']
                || $this->correction($landing, $request) !== $audit['evidence']) {
                throw new LogicException('The predecessor or correction evidence changed during amendment preflight.');
            }
            $successor->save();
            $amendment = TaskLandingAmendment::query()->create(['task_workspace_id' => $workspace->id,
                'predecessor_id' => $landing->id, 'successor_id' => $successor->id,
                'request_hash' => $expectedHash, 'request' => $request, 'audit_hash' => TaskLandingData::hash($audit), 'audit' => $audit]);
            $this->history->assert($successor);

            return ['applied' => true, 'amendment' => $amendment->toArray(), 'landing' => $successor->refresh()->toArray()];
        });
    }

    /** @param array<string, mixed> $request */
    private function eligible(TaskLanding $landing, array $request): void
    {
        $this->reviews->assertPackage($landing, TaskLandingData::text($request, 'package_hash', 64));
        $review = $landing->review_result;
        if ($landing->state !== 'rejected' || $review === null || ! in_array($review['verdict'] ?? null, ['revise', 'blocked'], true)
            || TaskLandingData::hash($review) !== $request['review_hash']
            || ($review['assignment'] ?? null) !== $landing->review_assignment || $landing->review_assignment === null
            || ($review['package_hash'] ?? null) !== $landing->package_hash
            || ($review['candidate_sha'] ?? null) !== $landing->candidate_sha || ($review['artifact_sha'] ?? null) !== $landing->artifact_sha
            || ! $this->payloads->matches(TaskLandingData::object($review['session'] ?? null), $landing->review_session ?? [])
            || $landing->review_token === null || $landing->review_token_hash !== hash('sha256', $landing->review_token)
            || $landing->review_prompt === null
            || TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->exists()) {
            throw new LogicException('Amendment requires the exact bound rejected review without downstream operations.');
        }
        if ($request['pull_request_body'] === $landing->request['pull_request_body']) {
            throw new LogicException('A body-only amendment must change the proposed PR wording.');
        }
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function normalize(array $request): array
    {
        if (array_diff(array_keys($request), ['package_hash', 'review_hash', 'reason', 'pull_request_body', 'evidence']) !== []) {
            throw new InvalidArgumentException('Unknown body-only amendment fields.');
        }
        foreach (['package_hash', 'review_hash'] as $field) {
            if (preg_match('/\\A[a-f0-9]{64}\\z/', TaskLandingData::text($request, $field, 64)) !== 1) {
                throw new InvalidArgumentException('Pin exact predecessor package and review hashes.');
            }
        }
        $evidence = TaskLandingData::object($request['evidence'] ?? null);
        if (array_diff(array_keys($evidence), ['path', 'sha256']) !== []
            || preg_match('/\\A[a-f0-9]{64}\\z/', TaskLandingData::text($evidence, 'sha256', 64)) !== 1) {
            throw new InvalidArgumentException('Pin one complete retained correction evidence file.');
        }

        return ['package_hash' => $request['package_hash'], 'review_hash' => $request['review_hash'],
            'reason' => TaskLandingData::text($request, 'reason', 4000),
            'pull_request_body' => TaskLandingData::text($request, 'pull_request_body', 50_000),
            'evidence' => ['path' => TaskLandingData::text($evidence, 'path'), 'sha256' => $evidence['sha256']]];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function correction(TaskLanding $landing, array $request): array
    {
        $file = TaskLandingData::object($request['evidence']);
        $path = TaskLandingData::text($file, 'path');
        if (str_starts_with($path, $landing->workspace()->firstOrFail()->worktree.'/')) {
            throw new LogicException('Retain correction evidence outside the feature checkout.');
        }
        $contents = TaskLandingData::file($path, 65_536);
        if (hash('sha256', $contents) !== $file['sha256']) {
            throw new LogicException('The complete correction evidence file changed.');
        }

        return [...$file, 'bytes' => strlen($contents), 'contents' => $contents];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function existing(TaskLanding $landing, array $request, ?string $hash, bool $apply): ?array
    {
        $amendment = TaskLandingAmendment::query()->where('predecessor_id', $landing->id)->first();
        if ($amendment === null) {
            return null;
        }
        if (! $this->payloads->matches($amendment->request, $request)
            || ($apply && ($hash === null || ! hash_equals($amendment->request_hash, $hash)))) {
            throw new LogicException('This landing already has a conflicting one-shot amendment intent.');
        }

        return ['applied' => false, 'amendment' => $amendment->toArray(),
            'landing' => TaskLanding::query()->findOrFail($amendment->successor_id)->toArray()];
    }
}
