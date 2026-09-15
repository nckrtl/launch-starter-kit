<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use LogicException;

final readonly class TaskLandingPackage
{
    /** @return array<string, mixed> */
    public function render(TaskWorkspace $workspace, TaskLanding $landing, string $artifact): array
    {
        $root = $workspace->root()->firstOrFail();
        $repository = TaskLandingData::object($landing->inputs['repository'] ?? null);
        if (($landing->inputs['schema'] ?? null) === 2) {
            $preProof = TaskLandingData::object($landing->inputs['pre_proof_artifact'] ?? null);
            $nativeProof = TaskLandingData::object($landing->inputs['native_proof'] ?? null);
            if (($preProof['sha'] ?? null) !== $artifact || ($preProof['ref'] ?? null) !== $landing->artifact_ref) {
                throw new LogicException('The schema-2 package must adopt the exact pre-proof artifact.');
            }
            $proofHash = TaskLandingData::hash($nativeProof);
            $body = 'Issue: '.$workspace->source_key."\nCandidate: ".$landing->candidate_sha."\nArtifact: ".$artifact
                ."\nFlow: proof\nSnapshot replacement: declared"
                ."\nProof attempt: ".TaskLandingData::text($nativeProof, 'attempt_id', 32)
                ."\nCapture fingerprint: ".TaskLandingData::text($nativeProof, 'capture_fingerprint', 64)
                ."\nTasks input SHA256: ".$landing->input_hash."\nProof evidence SHA256: ".$proofHash
                ."\nArtifact ref: ".$landing->artifact_ref
                ."\n\n".TaskLandingData::text($landing->request, 'pull_request_body', 50_000)
                ."\n\n## Frozen proof inputs\n\nThe accepted task chain, successful Builder gate, proof plan, and exact referenced fixtures are frozen in the unchanged pre-proof artifact. The final verdict and normalized prove, capture, retained-topology, interactive-review, and native primary archive evidence are bound by this schema-2 package outside that artifact. This package authorizes neither merge, topology release, snapshot installation, nor closeout.\n";
            if (strlen($body) > 60_000) {
                throw new LogicException('The complete PR body exceeds the supported size; do not truncate its evidence.');
            }

            return ['schema' => 2, 'issue_id' => $landing->issue_id, 'issue_key' => $workspace->source_key,
                'candidate_sha' => $landing->candidate_sha, 'artifact_ref' => $landing->artifact_ref, 'artifact_sha' => $artifact,
                'input_hash' => $landing->input_hash, 'proof_evidence_hash' => $proofHash,
                'native_proof' => $nativeProof, 'title' => $workspace->source_key.': '.$root->title, 'body' => $body];
        }
        $body = 'Issue: '.$workspace->source_key."\nCandidate: ".$landing->candidate_sha."\nArtifact: ".$artifact
            ."\nFlow: discovery\nBuilder gate: passed (".TaskLandingData::text($repository, 'gate_path').')'
            ."\nBuilder receipt SHA256: ".TaskLandingData::text($repository, 'gate_sha256')
            ."\nTasks input SHA256: ".$landing->input_hash."\nArtifact ref: ".$landing->artifact_ref
            ."\n\n".TaskLandingData::text($landing->request, 'pull_request_body', 50_000)
            ."\n\n## Frozen inputs\n\nThe ordered task briefs, accepted commits, review handoffs, successful Builder receipt and explicit retained evidence files are frozen in `.loop/commander-tasks.json` at the artifact above. Commander remains the task authority. References outside the embedded evidence are not promised to survive cleanup.\n"
            ."\nThe existing final pass establishes feature completion. Supplemental independent approval must cover this exact candidate, artifact, and PR body. This package alone authorizes neither merge nor cleanup.\n";
        if (strlen($body) > 60_000) {
            throw new LogicException('The complete PR body exceeds the supported size; do not truncate its evidence.');
        }

        return ['schema' => 1, 'issue_id' => $landing->issue_id, 'issue_key' => $workspace->source_key,
            'candidate_sha' => $landing->candidate_sha, 'artifact_ref' => $landing->artifact_ref, 'artifact_sha' => $artifact,
            'input_hash' => $landing->input_hash, 'title' => $workspace->source_key.': '.$root->title, 'body' => $body];
    }
}
