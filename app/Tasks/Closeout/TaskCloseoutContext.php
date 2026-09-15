<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingHistory;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\ReadTaskOrbitIssue;
use App\Tasks\TaskPayload;
use LogicException;

final readonly class TaskCloseoutContext
{
    public function __construct(private TaskLandingEvidence $evidence, private TaskLandingRepository $repository,
        private ReadTaskOrbitIssue $issues, private OrbitIssueSnapshotFactory $snapshots, private TaskPayload $payloads,
        private TaskLandingHistory $history) {}

    public function approved(int $id, string $hash): TaskLanding
    {
        $landing = TaskLanding::query()->findOrFail($id);
        $this->history->assert($landing);
        $review = $landing->review_result;
        if ($landing->state !== 'approved' || $landing->package_hash !== $hash || $landing->package === null
            || $landing->artifact_sha === null || TaskLandingData::hash($landing->package) !== $hash
            || TaskLandingData::hash($landing->inputs) !== $landing->input_hash
            || ($landing->package['candidate_sha'] ?? null) !== $landing->candidate_sha
            || ($landing->package['artifact_sha'] ?? null) !== $landing->artifact_sha
            || ($landing->package['artifact_ref'] ?? null) !== $landing->artifact_ref
            || ($landing->package['input_hash'] ?? null) !== $landing->input_hash
            || $review === null || ($review['verdict'] ?? null) !== 'pass'
            || ($review['package_hash'] ?? null) !== $hash || ($review['candidate_sha'] ?? null) !== $landing->candidate_sha
            || ($review['artifact_sha'] ?? null) !== $landing->artifact_sha
            || ($review['assignment'] ?? null) !== $landing->review_assignment
            || ! $this->payloads->matches(TaskLandingData::object($review['session'] ?? null), $landing->review_session ?? [])) {
            throw new LogicException('Closeout requires the exact immutable independently approved Tasks package.');
        }
        $this->guard($landing);

        return $landing;
    }

    public function guard(TaskLanding $landing): TaskWorkspace
    {
        $workspace = $landing->workspace()->firstOrFail();
        $this->evidence->guard($workspace, $landing->request, $landing->inputs);

        return $workspace;
    }

    /** @param array<string,mixed>|null $pr */
    public function observe(TaskLanding $landing, ?array $pr = null): TaskWorkspace
    {
        $workspace = $this->guard($landing);
        $captured = $this->evidence->capture($workspace, $landing->request);
        if ($captured !== $landing->inputs && $pr !== null) {
            $issue = $this->issues->read($landing->issue_id, $workspace->source_key);
            $current = TaskLandingData::object($captured['issue'] ?? null);
            $expected = TaskLandingData::object($landing->inputs['issue'] ?? null);
            $title = TaskLandingData::text($landing->package ?? [], 'title');
            if (($current['contract_hash'] ?? null) !== $issue->contractHash
                || ! $this->snapshots->matchesExpectedContract($issue, TaskLandingData::text($expected, 'contract_hash'), TaskLandingData::text($pr, 'url'), $title)) {
                throw new LogicException('The current Orbit issue contract differs from the approved package.');
            }
            $attachments = $current['attachments'] ?? [];
            if (! is_array($attachments)) {
                throw new LogicException('The current attachment metadata is malformed.');
            }
            $current['attachments'] = array_values(array_filter($attachments,
                fn (mixed $attachment): bool => $attachment !== ['title' => $title, 'repository_url' => $pr['url']]));
            $current['contract_hash'] = $expected['contract_hash'];
            $captured['issue'] = $current;
        }
        if ($captured !== $landing->inputs
            || $this->repository->artifact($workspace, $landing->candidate_sha, $landing->inputs) !== $landing->artifact_sha) {
            throw new LogicException('The approved candidate, artifact, task ownership or requirements changed.');
        }

        return $workspace;
    }

    public function configuration(?TaskWorkspace $workspace = null): OrbitProjectConfig
    {
        $configuration = $workspace === null ? TaskLandingData::object(config('task-runtime.projects.orbit')) : $workspace->configuration;

        // Repository-only native adapters never use the legacy Herdr session name.
        // Tasks session authority remains the recorded socket and pane identifiers.
        return new OrbitProjectConfig('orbit', TaskLandingData::text($configuration, 'repository'),
            TaskLandingData::text($configuration, 'worktree_root'), 'tasks', 1,
            $workspace === null ? 'discovery' : (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? 'discovery'));
    }

    public function approvalMarker(TaskLanding $landing): string
    {
        return "Approved.\n\nPublication of the retained independent Commander Tasks package review; not a new verdict.\n"
            .'Package SHA256: '.$landing->package_hash."\nCandidate: ".$landing->candidate_sha
            ."\nArtifact: ".$landing->artifact_sha
            .(($landing->package['schema'] ?? null) === 2 ? "\nFlow: proof" : '')
            ."\nReview assignment: ".$landing->review_assignment
            ."\nReview SHA256: ".TaskLandingData::hash($landing->review_result)
            ."\nPR title SHA256: ".hash('sha256', TaskLandingData::text($landing->package ?? [], 'title'))
            ."\nPR body SHA256: ".hash('sha256', TaskLandingData::text($landing->package ?? [], 'body'))."\n";
    }
}
