<?php

declare(strict_types=1);

namespace App\Delivery\PullRequests;

use App\Delivery\Contracts\OrbitPullRequestReviewPublisher;
use App\Delivery\Data\PublishedOrbitPullRequestReview;
use App\Delivery\Exceptions\OrbitPullRequestReviewPublicationFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitPullRequestReviewPublisher implements OrbitPullRequestReviewPublisher
{
    private const string REVIEWER_LOGIN = 'tom-nckrtl[bot]';

    public function publishReview(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $submittedPullRequestBody,
        string $result,
        string $handoff,
        ?string $approvedPullRequestBody,
    ): PublishedOrbitPullRequestReview {
        $this->assertInput(
            $number,
            $issueKey,
            $candidateSha,
            $submittedPullRequestBody,
            $result,
            $handoff,
            $approvedPullRequestBody,
        );

        $pullRequest = $this->pullRequest($number);
        $currentBody = $result === 'approved' && ($pullRequest['body'] ?? null) === $approvedPullRequestBody
            ? (string) $approvedPullRequestBody
            : $submittedPullRequestBody;
        $this->assertPullRequest(
            $pullRequest,
            $number,
            strtolower($issueKey),
            $candidateSha,
            $currentBody,
        );

        if ($result === 'approved') {
            $publishedPullRequestBody = (string) $approvedPullRequestBody;

            if ($currentBody !== $publishedPullRequestBody) {
                try {
                    $this->request(
                        "pulls/{$number}",
                        'PATCH',
                        ['body' => $publishedPullRequestBody],
                    );
                } catch (OrbitPullRequestReviewPublicationFailed) {
                    // The exact read-back below resolves a lost update response.
                }

                $this->assertPullRequest(
                    $this->pullRequest($number),
                    $number,
                    strtolower($issueKey),
                    $candidateSha,
                    $publishedPullRequestBody,
                );
            }
        } else {
            $publishedPullRequestBody = $submittedPullRequestBody;
        }

        $body = $result === 'approved' ? 'Approved.' : $handoff;
        $state = $result === 'approved' ? 'APPROVED' : 'CHANGES_REQUESTED';
        $matching = $this->matchingReviews($this->reviews($number), $candidateSha, $body, $state);

        if ($matching === []) {
            try {
                $this->request(
                    "pulls/{$number}/reviews",
                    'POST',
                    [
                        'commit_id' => $candidateSha,
                        'body' => $body,
                        'event' => $result === 'approved' ? 'APPROVE' : 'REQUEST_CHANGES',
                    ],
                    true,
                );
            } catch (OrbitPullRequestReviewPublicationFailed) {
                // The authoritative review list below resolves a lost creation response.
            }

            $matching = $this->matchingReviews($this->reviews($number), $candidateSha, $body, $state);
        }

        usort($matching, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $review = end($matching);

        if (! is_array($review)) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'The Orbit pull request review publication outcome is unresolved.',
            );
        }

        return new PublishedOrbitPullRequestReview(
            id: $review['id'],
            pullRequestNumber: $number,
            reviewerLogin: self::REVIEWER_LOGIN,
            candidateSha: $candidateSha,
            state: $state,
            reviewBodyHash: hash('sha256', $body),
            pullRequestBodyHash: hash('sha256', $publishedPullRequestBody),
        );
    }

    private function assertInput(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $submittedPullRequestBody,
        string $result,
        string $handoff,
        ?string $approvedPullRequestBody,
    ): void {
        [$target, $profile] = $this->configuration();
        $validBody = $result === 'approved'
            ? is_string($approvedPullRequestBody) && trim($approvedPullRequestBody) !== ''
            : $approvedPullRequestBody === null;

        if ($target === '' || $profile === '' || $number < 1
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || trim($submittedPullRequestBody) === ''
            || ! in_array($result, ['approved', 'changes'], true)
            || trim($handoff) === '' || ! $validBody) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'The Orbit pull request review publication input is invalid.',
            );
        }
    }

    /** @return list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}> */
    private function reviews(int $number): array
    {
        $response = $this->request("pulls/{$number}/reviews?per_page=100");

        if (! is_array($response) || ! array_is_list($response)) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'GitHub returned an invalid Orbit pull request review list.',
            );
        }

        if (count($response) === 100) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'Orbit pull request review history requires pagination.',
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
                throw new OrbitPullRequestReviewPublicationFailed(
                    'GitHub returned an invalid Orbit pull request review list.',
                );
            }
        }

        /** @var list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}> $response */
        return $response;
    }

    /**
     * @param  list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}>  $reviews
     * @return list<array{id: int, user: array{login: string}, commit_id: string, body: string, state: string}>
     */
    private function matchingReviews(
        array $reviews,
        string $candidateSha,
        string $body,
        string $state,
    ): array {
        return array_values(array_filter(
            $reviews,
            static fn (array $review): bool => $review['user']['login'] === self::REVIEWER_LOGIN
                && $review['commit_id'] === $candidateSha
                && $review['body'] === $body
                && $review['state'] === $state,
        ));
    }

    /** @return array<string, mixed> */
    private function pullRequest(int $number): array
    {
        $response = $this->request("pulls/{$number}");

        if (! is_array($response) || array_is_list($response)) {
            throw new OrbitPullRequestReviewPublicationFailed('GitHub returned invalid Orbit pull request details.');
        }

        foreach ($response as $key => $_value) {
            if (! is_string($key)) {
                throw new OrbitPullRequestReviewPublicationFailed('GitHub returned invalid Orbit pull request details.');
            }
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    /** @param array<string, mixed> $pullRequest */
    private function assertPullRequest(
        array $pullRequest,
        int $number,
        string $branch,
        string $candidateSha,
        string $pullRequestBody,
    ): void {
        $head = $pullRequest['head'] ?? null;
        $base = $pullRequest['base'] ?? null;
        $user = $pullRequest['user'] ?? null;
        $url = $pullRequest['html_url'] ?? null;
        $headRepository = is_array($head) ? ($head['repo'] ?? null) : null;
        $baseRepository = is_array($base) ? ($base['repo'] ?? null) : null;

        if (($pullRequest['number'] ?? null) !== $number
            || ($pullRequest['state'] ?? null) !== 'open'
            || ! is_array($head) || ($head['sha'] ?? null) !== $candidateSha
            || ($head['ref'] ?? null) !== $branch
            || ! is_array($headRepository) || ($headRepository['full_name'] ?? null) !== 'nckrtl/orbit'
            || ! is_array($base) || ($base['ref'] ?? null) !== 'main'
            || ! is_array($baseRepository) || ($baseRepository['full_name'] ?? null) !== 'nckrtl/orbit'
            || ! is_array($user) || ($user['login'] ?? null) !== 'nckrtl'
            || ($pullRequest['body'] ?? null) !== $pullRequestBody
            || ($pullRequest['draft'] ?? null) !== false
            || ! is_string($url)
            || $url !== "https://github.com/nckrtl/orbit/pull/{$number}") {
            throw new OrbitPullRequestReviewPublicationFailed(
                'Published Orbit pull request read-back differs from the implementation handoff.',
            );
        }
    }

    /** @param array<string, mixed>|null $body */
    private function request(
        string $path,
        ?string $method = null,
        ?array $body = null,
        bool $app = false,
    ): mixed {
        [$target, $profile] = $this->configuration();

        try {
            $input = json_encode([
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/'.$path,
                ...($app ? ['app' => true] : []),
                ...($method === null ? [] : ['method' => $method]),
                ...($body === null ? [] : ['body' => $body]),
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'The Hermes Orbit pull request review publisher could not run.',
                0,
                $exception,
            );
        }

        if ($result->failed()) {
            throw new OrbitPullRequestReviewPublicationFailed('The Hermes Orbit pull request review publisher failed.');
        }

        try {
            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitPullRequestReviewPublicationFailed(
                'The Hermes Orbit pull request review publisher returned invalid JSON.',
                0,
                $exception,
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
            throw new OrbitPullRequestReviewPublicationFailed(
                'The Hermes Orbit pull request review publisher is not configured.',
            );
        }

        return [$target, $profile];
    }
}
