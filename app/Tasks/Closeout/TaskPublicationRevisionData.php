<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskLanding;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use LogicException;

final class TaskPublicationRevisionData
{
    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function request(TaskLanding $landing, array $request): array
    {
        $keys = ['number', 'url', 'repository', 'author_login', 'author_type', 'head_ref', 'base_ref',
            'head_sha', 'title', 'title_sha256', 'body', 'body_sha256', 'reviews', 'reason'];
        if (array_diff(array_keys($request), $keys) !== [] || array_diff($keys, array_keys($request)) !== []
            || ! is_int($request['number']) || $request['number'] < 1) {
            throw new LogicException('Pin exactly one complete existing PR revision preimage.');
        }
        $issue = TaskLandingData::text(TaskLandingData::object($landing->inputs['issue'] ?? null), 'identifier');
        $constants = ['url' => 'https://github.com/nckrtl/orbit/pull/'.$request['number'], 'repository' => 'nckrtl/orbit',
            'author_login' => 'nckrtl', 'author_type' => 'User', 'head_ref' => strtolower($issue), 'base_ref' => 'main'];
        foreach ($constants as $key => $value) {
            if ($request[$key] !== $value) {
                throw new LogicException('The revision PR repository, author, branch or base is not the owned Orbit identity.');
            }
        }
        $head = TaskLandingData::text($request, 'head_sha', 40);
        GitObjectId::validate($head);
        if ($head === $landing->candidate_sha) {
            throw new LogicException('A publication revision requires a new reviewed descendant candidate.');
        }
        foreach (['title' => 255, 'body' => 60_000] as $key => $limit) {
            $text = TaskLandingData::text($request, $key, $limit);
            if (! mb_check_encoding($text, 'UTF-8') || $request[$key.'_sha256'] !== hash('sha256', $text)) {
                throw new LogicException('The revision must retain exact UTF-8 PR bytes and their hashes.');
            }
        }
        if ($request['title'] !== ($landing->package['title'] ?? null)) {
            throw new LogicException('Publication revision keeps the existing title unchanged.');
        }
        if ($request['body'] === ($landing->package['body'] ?? null)) {
            throw new LogicException('Publication revision requires a distinct reviewed PR body.');
        }
        $reviews = $request['reviews'];
        if (! is_array($reviews) || ! array_is_list($reviews) || count($reviews) >= 100) {
            throw new LogicException('Retain the complete bounded ordered PR review snapshot.');
        }
        $last = 0;
        $normalized = [];
        foreach ($reviews as $review) {
            $review = TaskLandingData::object($review);
            $reviewKeys = ['id', 'commit_id', 'state', 'author_login', 'author_type', 'body_sha256'];
            if (array_diff(array_keys($review), $reviewKeys) !== [] || array_diff($reviewKeys, array_keys($review)) !== []
                || ! is_int($review['id']) || $review['id'] <= $last
                || ! in_array($review['state'], ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED', 'DISMISSED'], true)
                || ! in_array($review['author_type'], ['Bot', 'User'], true)
                || preg_match('/\A[a-f0-9]{64}\z/', TaskLandingData::text($review, 'body_sha256', 64)) !== 1) {
                throw new LogicException('The retained review identities must be complete, unique and ordered by ID.');
            }
            GitObjectId::validate(TaskLandingData::text($review, 'commit_id', 40));
            TaskLandingData::text($review, 'author_login', 255);
            $last = $review['id'];
            $normalized[] = array_replace(array_fill_keys($reviewKeys, null), $review);
        }
        TaskLandingData::text($request, 'reason', 10_000);

        return array_replace(array_fill_keys($keys, null), $request, ['reviews' => $normalized]);
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function input(TaskLanding $landing, array $request, string $identity): array
    {
        return ['schema' => 1, 'landing_id' => $landing->id, 'package_hash' => $landing->package_hash,
            'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha,
            'review_hash' => TaskLandingData::hash($landing->review_result), 'identity_hash' => $identity,
            'request' => self::request($landing, $request)];
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function publication(TaskLanding $landing, array $request): array
    {
        return ['number' => $request['number'], 'url' => $request['url'], 'candidate_sha' => $landing->candidate_sha,
            'title_hash' => hash('sha256', TaskLandingData::text($landing->package ?? [], 'title')),
            'body_hash' => hash('sha256', TaskLandingData::text($landing->package ?? [], 'body'))];
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function branch(TaskLanding $landing, array $request): array
    {
        return ['ref' => 'refs/heads/'.TaskLandingData::text($request, 'head_ref'), 'candidate_sha' => $landing->candidate_sha];
    }
}
