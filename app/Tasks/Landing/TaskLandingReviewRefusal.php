<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use Carbon\CarbonImmutable;
use LogicException;

final class TaskLandingReviewRefusal
{
    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function normalize(array $request): array
    {
        $normalized = [];
        foreach (['assignment', 'package_hash', 'candidate_sha', 'artifact_sha', 'input_hash', 'original_prompt_sha256', 'original_wire_sha256'] as $key) {
            $normalized[$key] = TaskLandingData::text($request, $key, 64);
        }
        if (! is_int($request['original_wire_bytes'] ?? null) || $request['original_wire_bytes'] <= TaskLandingReviewTransport::MAX_REQUEST_BYTES) {
            throw new LogicException('Recovery is limited to an oversized retained supplemental request.');
        }
        $normalized['original_wire_bytes'] = $request['original_wire_bytes'];
        $session = TaskLandingData::object($request['session'] ?? null);
        $normalized['session'] = HerdrTaskLandingReviewer::identity($session);
        $refusal = TaskLandingData::object($request['refusal'] ?? null);
        $normalized['refusal'] = [];
        foreach (['kind', 'path', 'sha256', 'occurred_at'] as $key) {
            $normalized['refusal'][$key] = TaskLandingData::text($refusal, $key, 4096);
        }
        if (array_diff(array_keys($request), array_keys($normalized)) !== []
            || array_diff(array_keys($session), array_keys($normalized['session'])) !== []
            || array_diff(array_keys($refusal), array_keys($normalized['refusal'])) !== []
            || $normalized['refusal']['kind'] !== 'request_line_too_large'
            || preg_match('/\A[a-f0-9]{64}\z/', $normalized['refusal']['sha256']) !== 1
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $normalized['refusal']['occurred_at']) !== 1) {
            throw new LogicException('Pin only the known request-line-too-large refusal and exact original assignment.');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function read(TaskLanding $landing, array $request): array
    {
        $refusal = TaskLandingData::object($request['refusal']);
        $path = TaskLandingData::text($refusal, 'path');
        $workspace = $landing->workspace()->firstOrFail();
        if (str_starts_with($path, $workspace->worktree.'/')) {
            throw new LogicException('Retain transport refusal evidence outside the feature worktree.');
        }
        $contents = TaskLandingData::file($path, 4096);
        $timestamp = TaskLandingData::text($refusal, 'occurred_at');
        if (hash('sha256', $contents) !== $refusal['sha256'] || count(explode("\n", trim($contents))) !== 1
            || ! str_contains($contents, $timestamp) || ! str_contains($contents, 'request line is too large')
            || $landing->updated_at === null || abs(CarbonImmutable::parse($timestamp)->diffInSeconds($landing->updated_at)) > 2) {
            throw new LogicException('Retain the exact contemporaneous request-line-too-large server diagnostic; generic uncertainty is insufficient.');
        }

        return [...$refusal, 'raw_line' => $contents, 'bytes' => strlen($contents)];
    }
}
