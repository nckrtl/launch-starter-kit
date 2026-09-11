<?php

declare(strict_types=1);

namespace App\Delivery\PullRequests;

use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitLandingReservation;
use App\Delivery\Exceptions\OrbitPullRequestLandingFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitPullRequestLandingGateway implements OrbitPullRequestLandingGateway
{
    private const string REPOSITORY = 'nckrtl/orbit';

    private const string REVIEWER_LOGIN = 'tom-nckrtl[bot]';

    public function reserve(string $issueId, string $pullRequestUrl): OrbitLandingReservation
    {
        $this->assertReservationInput($issueId, $pullRequestUrl);
        $reservation = $this->reservationStatus();

        if ($reservation !== null) {
            return $this->reservationResult($reservation, $issueId, $pullRequestUrl);
        }

        $failure = null;

        try {
            $this->request([
                'service' => 'reservation',
                'action' => 'reserve',
                'issue' => $issueId,
                'pr' => $pullRequestUrl,
            ]);
        } catch (OrbitPullRequestLandingFailed $exception) {
            $failure = $exception;
        }

        $reservation = $this->reservationStatus();

        if ($reservation === null) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit merge reservation outcome is unresolved.',
                previous: $failure,
            );
        }

        return $this->reservationResult($reservation, $issueId, $pullRequestUrl);
    }

    public function inspectApproved(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
        int $publishedReviewId,
    ): ApprovedOrbitPullRequest {
        $this->assertApprovalInput($number, $issueKey, $candidateSha, $pullRequestBody, $publishedReviewId);
        $pullRequest = $this->pullRequest($number);
        $this->assertOpenCandidate($pullRequest, $number, $issueKey, $candidateSha, $pullRequestBody);
        $reviews = $this->reviews($number);
        $matching = array_values(array_filter(
            $reviews,
            static fn (array $review): bool => $review['id'] === $publishedReviewId,
        ));

        if (count($matching) !== 1) {
            throw new OrbitPullRequestLandingFailed(
                'The exact independent Orbit approval is no longer active.',
            );
        }

        $approval = $matching[0];

        if ($approval['user']['login'] !== self::REVIEWER_LOGIN
            || $approval['commit_id'] !== $candidateSha || $approval['body'] !== 'Approved.'
            || $approval['state'] !== 'APPROVED') {
            throw new OrbitPullRequestLandingFailed(
                'The exact independent Orbit approval is no longer active.',
            );
        }

        if (collect($reviews)->contains(
            static fn (array $review): bool => $review['id'] > $publishedReviewId
                && $review['commit_id'] === $candidateSha
                && $review['state'] === 'CHANGES_REQUESTED',
        )) {
            throw new OrbitPullRequestLandingFailed(
                'A later Orbit pull request review requests changes.',
            );
        }

        $this->assertBranchProtection();
        $mergeable = $pullRequest['mergeable'] ?? null;

        if (! is_bool($mergeable) && $mergeable !== null) {
            throw new OrbitPullRequestLandingFailed(
                'GitHub returned invalid Orbit mergeability metadata.',
            );
        }

        return new ApprovedOrbitPullRequest(
            number: $number,
            url: "https://github.com/nckrtl/orbit/pull/{$number}",
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            reviewId: $publishedReviewId,
            reviewerLogin: self::REVIEWER_LOGIN,
            mergeable: $mergeable,
        );
    }

    public function merge(int $number, string $candidateSha): MergedOrbitPullRequest
    {
        $this->assertMergeInput($number, $candidateSha);
        $failure = null;

        try {
            $this->github(
                "pulls/{$number}/merge",
                'PUT',
                ['sha' => $candidateSha, 'merge_method' => 'merge'],
            );
        } catch (OrbitPullRequestLandingFailed $exception) {
            $failure = $exception;
        }

        try {
            $pullRequest = $this->pullRequest($number);
            $mergeCommitSha = $this->assertMergedCandidate($pullRequest, $number, $candidateSha);
        } catch (OrbitPullRequestLandingFailed $exception) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit pull request merge outcome is unresolved; retain the merge reservation.',
                previous: $failure ?? $exception,
            );
        }

        return new MergedOrbitPullRequest(
            number: $number,
            url: "https://github.com/nckrtl/orbit/pull/{$number}",
            candidateSha: $candidateSha,
            mergeCommitSha: $mergeCommitSha,
        );
    }

    public function release(string $issueId, string $pullRequestUrl): void
    {
        $this->assertReservationInput($issueId, $pullRequestUrl);
        $reservation = $this->reservationStatus();

        if ($reservation === null) {
            return;
        }

        if ($reservation['issue_id'] !== $issueId || $reservation['pr_url'] !== $pullRequestUrl) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit merge reservation belongs to another delivery.',
            );
        }

        $failure = null;

        try {
            $this->request([
                'service' => 'reservation',
                'action' => 'release',
                'issue' => $issueId,
                'pr' => $pullRequestUrl,
            ]);
        } catch (OrbitPullRequestLandingFailed $exception) {
            $failure = $exception;
        }

        if ($this->reservationStatus() !== null) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit merge reservation release outcome is unresolved.',
                previous: $failure,
            );
        }
    }

    /** @param array{issue_id: string, pr_url: string, reserved_at: string} $reservation */
    private function reservationResult(
        array $reservation,
        string $issueId,
        string $pullRequestUrl,
    ): OrbitLandingReservation {
        return new OrbitLandingReservation(
            owned: $reservation['issue_id'] === $issueId && $reservation['pr_url'] === $pullRequestUrl,
            issueId: $reservation['issue_id'],
            pullRequestUrl: $reservation['pr_url'],
            reservedAt: $reservation['reserved_at'],
        );
    }

    /** @return array{issue_id: string, pr_url: string, reserved_at: string}|null */
    private function reservationStatus(): ?array
    {
        $response = $this->request(['service' => 'reservation', 'action' => 'status']);

        if (! is_array($response) || array_is_list($response) || ($response['version'] ?? null) !== 1
            || ! is_string($response['status'] ?? null)) {
            throw new OrbitPullRequestLandingFailed(
                'Hermes returned invalid Orbit merge reservation state.',
            );
        }

        if ($response['status'] === 'idle'
            && ($response['issue_id'] ?? null) === null
            && ($response['pr_url'] ?? null) === null
            && ($response['reserved_at'] ?? null) === null) {
            return null;
        }

        $issueId = $response['issue_id'] ?? null;
        $pullRequestUrl = $response['pr_url'] ?? null;
        $reservedAt = $response['reserved_at'] ?? null;

        if ($response['status'] !== 'active' || ! is_string($issueId) || ! $this->isUuid($issueId)
            || ! is_string($pullRequestUrl)
            || preg_match('#^https://github\.com/nckrtl/orbit/pull/[1-9][0-9]*$#', $pullRequestUrl) !== 1
            || ! is_string($reservedAt) || trim($reservedAt) === '') {
            throw new OrbitPullRequestLandingFailed(
                'Hermes returned invalid Orbit merge reservation state.',
            );
        }

        return [
            'issue_id' => $issueId,
            'pr_url' => $pullRequestUrl,
            'reserved_at' => $reservedAt,
        ];
    }

    /** @return list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}> */
    private function reviews(int $number): array
    {
        $response = $this->github("pulls/{$number}/reviews?per_page=100");

        if (! is_array($response) || ! array_is_list($response) || count($response) === 100) {
            throw new OrbitPullRequestLandingFailed(
                count(is_array($response) ? $response : []) === 100
                    ? 'Orbit pull request review history requires pagination.'
                    : 'GitHub returned an invalid Orbit pull request review list.',
            );
        }

        foreach ($response as $review) {
            $user = is_array($review) && ! array_is_list($review) ? ($review['user'] ?? null) : null;

            if (! is_array($review) || array_is_list($review)
                || ! is_int($review['id'] ?? null) || $review['id'] < 1
                || ! is_array($user) || array_is_list($user) || ! is_string($user['login'] ?? null)
                || ! is_string($review['commit_id'] ?? null)
                || ! is_string($review['body'] ?? null)
                || ! is_string($review['state'] ?? null)) {
                throw new OrbitPullRequestLandingFailed(
                    'GitHub returned an invalid Orbit pull request review list.',
                );
            }
        }

        /** @var list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}> $response */
        return $response;
    }

    private function assertBranchProtection(): void
    {
        $response = $this->github('rules/branches/main');

        if (! is_array($response) || ! array_is_list($response)) {
            throw new OrbitPullRequestLandingFailed(
                'GitHub returned invalid Orbit main branch protection rules.',
            );
        }

        $types = [];

        foreach ($response as $rule) {
            if (! is_array($rule) || array_is_list($rule) || ! is_string($rule['type'] ?? null)) {
                throw new OrbitPullRequestLandingFailed(
                    'GitHub returned invalid Orbit main branch protection rules.',
                );
            }

            $types[] = $rule['type'];
        }

        if (array_diff(['deletion', 'non_fast_forward'], $types) !== []) {
            throw new OrbitPullRequestLandingFailed(
                'Orbit main branch protection differs from the approved merge contract.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function pullRequest(int $number): array
    {
        $response = $this->github("pulls/{$number}");

        if (! is_array($response) || array_is_list($response)) {
            throw new OrbitPullRequestLandingFailed(
                'GitHub returned invalid Orbit pull request details.',
            );
        }

        foreach ($response as $key => $_value) {
            if (! is_string($key)) {
                throw new OrbitPullRequestLandingFailed(
                    'GitHub returned invalid Orbit pull request details.',
                );
            }
        }

        return $response;
    }

    /** @param array<string, mixed> $pullRequest */
    private function assertOpenCandidate(
        array $pullRequest,
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
    ): void {
        $head = $pullRequest['head'] ?? null;
        $base = $pullRequest['base'] ?? null;
        $user = $pullRequest['user'] ?? null;
        $headRepository = is_array($head) ? ($head['repo'] ?? null) : null;
        $baseRepository = is_array($base) ? ($base['repo'] ?? null) : null;

        if (($pullRequest['number'] ?? null) !== $number
            || ($pullRequest['html_url'] ?? null) !== "https://github.com/nckrtl/orbit/pull/{$number}"
            || ($pullRequest['state'] ?? null) !== 'open' || ($pullRequest['merged'] ?? false) !== false
            || ($pullRequest['draft'] ?? null) !== false || ($pullRequest['body'] ?? null) !== $pullRequestBody
            || ! is_array($head) || ($head['sha'] ?? null) !== $candidateSha
            || ($head['ref'] ?? null) !== mb_strtolower($issueKey)
            || ! is_array($headRepository) || ($headRepository['full_name'] ?? null) !== self::REPOSITORY
            || ! is_array($base) || ($base['ref'] ?? null) !== 'main'
            || ! is_array($baseRepository) || ($baseRepository['full_name'] ?? null) !== self::REPOSITORY
            || ! is_array($user) || ($user['login'] ?? null) !== 'nckrtl') {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit pull request is not the approved open candidate targeting main.',
            );
        }
    }

    /** @param array<string, mixed> $pullRequest */
    private function assertMergedCandidate(array $pullRequest, int $number, string $candidateSha): string
    {
        $head = $pullRequest['head'] ?? null;
        $base = $pullRequest['base'] ?? null;
        $headRepository = is_array($head) ? ($head['repo'] ?? null) : null;
        $baseRepository = is_array($base) ? ($base['repo'] ?? null) : null;
        $mergeCommitSha = $pullRequest['merge_commit_sha'] ?? null;

        if (($pullRequest['number'] ?? null) !== $number
            || ($pullRequest['html_url'] ?? null) !== "https://github.com/nckrtl/orbit/pull/{$number}"
            || ($pullRequest['state'] ?? null) !== 'closed' || ($pullRequest['merged'] ?? null) !== true
            || ! is_array($head) || ($head['sha'] ?? null) !== $candidateSha
            || ! is_array($headRepository) || ($headRepository['full_name'] ?? null) !== self::REPOSITORY
            || ! is_array($base) || ($base['ref'] ?? null) !== 'main'
            || ! is_array($baseRepository) || ($baseRepository['full_name'] ?? null) !== self::REPOSITORY
            || ! is_string($mergeCommitSha) || preg_match('/^[a-f0-9]{40}$/', $mergeCommitSha) !== 1) {
            throw new OrbitPullRequestLandingFailed(
                'GitHub did not confirm the exact merged Orbit candidate.',
            );
        }

        return $mergeCommitSha;
    }

    private function assertReservationInput(string $issueId, string $pullRequestUrl): void
    {
        $this->configuration();

        if (! $this->isUuid($issueId)
            || preg_match('#^https://github\.com/nckrtl/orbit/pull/[1-9][0-9]*$#', $pullRequestUrl) !== 1) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit merge reservation input is invalid.',
            );
        }
    }

    private function assertApprovalInput(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
        int $publishedReviewId,
    ): void {
        $this->configuration();

        if ($number < 1 || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || trim($pullRequestBody) === '' || $publishedReviewId < 1) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit approved pull request inspection input is invalid.',
            );
        }
    }

    private function assertMergeInput(int $number, string $candidateSha): void
    {
        $this->configuration();

        if ($number < 1 || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1) {
            throw new OrbitPullRequestLandingFailed(
                'The Orbit pull request merge input is invalid.',
            );
        }
    }

    /** @param array<string, mixed>|null $body */
    private function github(string $path, ?string $method = null, ?array $body = null): mixed
    {
        return $this->request([
            'service' => 'github',
            'path' => 'repos/'.self::REPOSITORY.'/'.$path,
            ...($method === null ? [] : ['method' => $method]),
            ...($body === null ? [] : ['body' => $body]),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload): mixed
    {
        [$target, $profile] = $this->configuration();

        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitPullRequestLandingFailed(
                'The Hermes Orbit landing gateway could not run.',
                previous: $exception,
            );
        }

        if ($result->failed()) {
            throw new OrbitPullRequestLandingFailed(
                'The Hermes Orbit landing gateway failed.',
            );
        }

        try {
            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitPullRequestLandingFailed(
                'The Hermes Orbit landing gateway returned invalid JSON.',
                previous: $exception,
            );
        }
    }

    /** @return array{string, string} */
    private function configuration(): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1) {
            throw new OrbitPullRequestLandingFailed(
                'The Hermes Orbit landing gateway is not configured.',
            );
        }

        return [$target, $profile];
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
