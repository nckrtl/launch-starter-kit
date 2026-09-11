<?php

declare(strict_types=1);

namespace App\Delivery\PullRequests;

use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Exceptions\OrbitPullRequestPublicationFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;

final readonly class SshOrbitPullRequestPublisher implements OrbitPullRequestPublisher
{
    public function publish(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        $this->assertInput($issueKey, $issueTitle, $candidateSha, $pullRequestBody);
        $branch = strtolower($issueKey);
        $matches = $this->matchingPullRequests($branch);

        if (count($matches) > 1) {
            throw new OrbitPullRequestPublicationFailed('Multiple pull requests match the Orbit issue branch.');
        }

        if ($matches === []) {
            $number = $this->createOrRecover(
                $issueKey,
                $issueTitle,
                $branch,
                $candidateSha,
                $pullRequestBody,
            );
        } else {
            $number = $this->number($matches[0]);

            if (($matches[0]['body'] ?? null) !== $pullRequestBody) {
                try {
                    $this->request(
                        "pulls/{$number}",
                        'PATCH',
                        ['body' => $pullRequestBody],
                    );
                } catch (OrbitPullRequestPublicationFailed) {
                    // The authoritative read-back below resolves a lost update response.
                }
            }
        }

        $pullRequest = $this->pullRequest($number);
        $this->assertReadBack($pullRequest, $number, $branch, $candidateSha, $pullRequestBody);

        for ($attempt = 0; $attempt < 4 && ($pullRequest['mergeable'] ?? null) === null; $attempt++) {
            Sleep::usleep(250_000);
            $pullRequest = $this->pullRequest($number);
            $this->assertReadBack($pullRequest, $number, $branch, $candidateSha, $pullRequestBody);
        }

        $mergeable = $pullRequest['mergeable'] ?? null;

        if (! is_bool($mergeable) && $mergeable !== null) {
            throw new OrbitPullRequestPublicationFailed('GitHub returned invalid Orbit mergeability metadata.');
        }

        $url = $pullRequest['html_url'] ?? null;

        if (! is_string($url)) {
            throw new OrbitPullRequestPublicationFailed('GitHub returned an invalid Orbit pull request URL.');
        }

        return new PublishedOrbitPullRequest(
            number: $number,
            url: $url,
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            mergeable: $mergeable,
        );
    }

    private function assertInput(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): void {
        [$target, $profile] = $this->configuration();

        if ($target === '' || $profile === ''
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || trim($issueTitle) === ''
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || trim($pullRequestBody) === '') {
            throw new OrbitPullRequestPublicationFailed('The Orbit pull request publication input is invalid.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function matchingPullRequests(string $branch): array
    {
        $response = $this->request("pulls?state=open&head=nckrtl:{$branch}&base=main");

        if (! is_array($response) || ! array_is_list($response)
            || collect($response)->contains(static fn (mixed $item): bool => ! is_array($item) || array_is_list($item))) {
            throw new OrbitPullRequestPublicationFailed('GitHub returned an invalid Orbit pull request list.');
        }

        /** @var list<array<string, mixed>> $response */
        return $response;
    }

    private function createOrRecover(
        string $issueKey,
        string $issueTitle,
        string $branch,
        string $candidateSha,
        string $pullRequestBody,
    ): int {
        $failure = null;

        try {
            $created = $this->request('pulls', 'POST', [
                'title' => "{$issueKey}: {$issueTitle}",
                'head' => $branch,
                'base' => 'main',
                'body' => $pullRequestBody,
                'draft' => false,
            ]);

            return $this->number($created);
        } catch (OrbitPullRequestPublicationFailed $exception) {
            $failure = $exception;
        }

        try {
            $matches = $this->matchingPullRequests($branch);

            if (count($matches) === 1) {
                $number = $this->number($matches[0]);
                $readBack = $this->pullRequest($number);
                $this->assertReadBack($readBack, $number, $branch, $candidateSha, $pullRequestBody);

                return $number;
            }
        } catch (OrbitPullRequestPublicationFailed) {
            // Preserve the original uncertain creation result below.
        }

        throw new OrbitPullRequestPublicationFailed(
            'The Orbit pull request creation outcome is unresolved.',
            previous: $failure,
        );
    }

    /** @return array<string, mixed> */
    private function pullRequest(int $number): array
    {
        $response = $this->request("pulls/{$number}");

        if (! is_array($response) || array_is_list($response)) {
            throw new OrbitPullRequestPublicationFailed('GitHub returned invalid Orbit pull request details.');
        }

        $details = [];

        foreach ($response as $key => $value) {
            if (! is_string($key)) {
                throw new OrbitPullRequestPublicationFailed('GitHub returned invalid Orbit pull request details.');
            }

            $details[$key] = $value;
        }

        return $details;
    }

    /** @param array<string, mixed> $pullRequest */
    private function assertReadBack(
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
            throw new OrbitPullRequestPublicationFailed(
                'Published Orbit pull request read-back differs from the implementation handoff.',
            );
        }
    }

    private function number(mixed $pullRequest): int
    {
        $number = is_array($pullRequest) && ! array_is_list($pullRequest)
            ? ($pullRequest['number'] ?? null)
            : null;

        if (! is_int($number) || $number < 1) {
            throw new OrbitPullRequestPublicationFailed('GitHub returned an invalid Orbit pull request number.');
        }

        return $number;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $path, ?string $method = null, ?array $body = null): mixed
    {
        [$target, $profile] = $this->configuration();

        try {
            $input = json_encode([
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/'.$path,
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
            throw new OrbitPullRequestPublicationFailed(
                'The Hermes Orbit pull request publisher could not run.',
                0,
                $exception,
            );
        }

        if ($result->failed()) {
            throw new OrbitPullRequestPublicationFailed('The Hermes Orbit pull request publisher failed.');
        }

        try {
            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitPullRequestPublicationFailed(
                'The Hermes Orbit pull request publisher returned invalid JSON.',
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
            throw new OrbitPullRequestPublicationFailed('The Hermes Orbit pull request publisher is not configured.');
        }

        return [$target, $profile];
    }
}
