<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Contracts\OrbitResolutionPublisher;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PublishedOrbitResolution;
use App\Delivery\Exceptions\OrbitResolutionPublicationFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitResolutionPublisher implements OrbitResolutionPublisher
{
    private const string QUERY = <<<'GRAPHQL'
query LoopResolutionComments($id: String!) {
  viewer { id }
  issue(id: $id) {
    id identifier
    state { name type }
    assignee { id }
    delegate { id }
    comments(first: 100) {
      nodes { id body user { id } }
      pageInfo { hasNextPage }
    }
  }
}
GRAPHQL;

    private const string MUTATION = <<<'GRAPHQL'
mutation LoopResolutionComment($input: CommentCreateInput!) {
  commentCreate(input: $input) { success }
}
GRAPHQL;

    public function publish(
        OrbitIssueSnapshot $issue,
        int $dispatchId,
        string $handoff,
        bool $adopted,
        string $resumePhase,
    ): PublishedOrbitResolution {
        [$target, $profile, $viewerId] = $this->configuration($issue, $dispatchId, $handoff);
        $marker = "ORBIT-LOOP-RESOLUTION:{$dispatchId}";
        $routing = $adopted
            ? "Adopted for {$resumePhase}; verification remains required."
            : 'Needs an explicit decision or recovery action.';
        $body = $marker."\n\n".$handoff."\n\nCommander routing: ".$routing;
        $matches = $this->matchingComments(
            $this->request($target, $profile, self::QUERY, ['id' => $issue->issueId]),
            $issue,
            $viewerId,
            $marker,
            $body,
        );

        if ($matches === []) {
            $mutationFailure = null;

            try {
                $response = $this->request($target, $profile, self::MUTATION, [
                    'input' => ['issueId' => $issue->issueId, 'body' => $body],
                ]);
                $data = $response['data'] ?? null;
                $created = is_array($data) ? ($data['commentCreate'] ?? null) : null;

                if (! empty($response['errors']) || ! is_array($created) || ($created['success'] ?? null) !== true) {
                    throw new OrbitResolutionPublicationFailed('Linear did not confirm the resolution publication.');
                }
            } catch (OrbitResolutionPublicationFailed $exception) {
                $mutationFailure = $exception;
            }

            try {
                $matches = $this->matchingComments(
                    $this->request($target, $profile, self::QUERY, ['id' => $issue->issueId]),
                    $issue,
                    $viewerId,
                    $marker,
                    $body,
                );
            } catch (OrbitResolutionPublicationFailed $exception) {
                throw new OrbitResolutionPublicationFailed(
                    'The resolution publication could not be verified by Linear read-back.',
                    0,
                    $mutationFailure ?? $exception,
                );
            }
        }

        if (count($matches) !== 1) {
            throw new OrbitResolutionPublicationFailed(
                'Linear read-back did not contain exactly one Tom-authored resolution publication.',
            );
        }

        return new PublishedOrbitResolution(
            commentId: $matches[0],
            marker: $marker,
            bodyHash: hash('sha256', $body),
        );
    }

    /** @return array{string, string, string} */
    private function configuration(OrbitIssueSnapshot $issue, int $dispatchId, string $handoff): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $payload = $issue->payload;
        $state = $payload['state'] ?? null;
        $delegate = $payload['delegate'] ?? null;

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! $this->isUuid($issue->issueId)
            || preg_match('/^ORB-[0-9]+$/', $issue->issueKey) !== 1
            || $dispatchId < 1 || trim($handoff) === ''
            || ($payload['id'] ?? null) !== $issue->issueId
            || ($payload['identifier'] ?? null) !== $issue->issueKey
            || ! is_array($state) || ($state['name'] ?? null) !== 'In Review' || ($state['type'] ?? null) !== 'started'
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ($payload['assignee'] ?? null) !== null) {
            throw new OrbitResolutionPublicationFailed('The resolution publication input or Hermes configuration is invalid.');
        }

        return [$target, $profile, $viewerId];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function matchingComments(
        array $response,
        OrbitIssueSnapshot $expected,
        string $viewerId,
        string $marker,
        string $body,
    ): array {
        $data = $response['data'] ?? null;
        $viewer = is_array($data) ? ($data['viewer'] ?? null) : null;
        $issue = is_array($data) ? ($data['issue'] ?? null) : null;
        $comments = is_array($issue) ? ($issue['comments'] ?? null) : null;
        $nodes = is_array($comments) ? ($comments['nodes'] ?? null) : null;
        $page = is_array($comments) ? ($comments['pageInfo'] ?? null) : null;
        $state = is_array($issue) ? ($issue['state'] ?? null) : null;
        $delegate = is_array($issue) ? ($issue['delegate'] ?? null) : null;

        if (! empty($response['errors']) || ! is_array($viewer) || ($viewer['id'] ?? null) !== $viewerId
            || ! is_array($issue) || ($issue['id'] ?? null) !== $expected->issueId
            || ($issue['identifier'] ?? null) !== $expected->issueKey
            || ! is_array($state) || ($state['name'] ?? null) !== 'In Review' || ($state['type'] ?? null) !== 'started'
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ($issue['assignee'] ?? null) !== null
            || ! is_array($nodes) || ! array_is_list($nodes)
            || ! is_array($page) || ($page['hasNextPage'] ?? null) !== false) {
            throw new OrbitResolutionPublicationFailed('The Linear resolution publication read-back is invalid.');
        }

        $matches = [];

        foreach ($nodes as $comment) {
            if (! is_array($comment)) {
                continue;
            }

            $commentBody = $comment['body'] ?? null;

            if (! is_string($commentBody)
                || ($commentBody !== $marker && ! str_starts_with($commentBody, $marker."\n"))) {
                continue;
            }

            $user = $comment['user'] ?? null;
            $id = $comment['id'] ?? null;

            if (! is_array($user) || ($user['id'] ?? null) !== $viewerId || ! is_string($id) || ! $this->isUuid($id)) {
                throw new OrbitResolutionPublicationFailed('A marked resolution publication has invalid authorship or identity.');
            }

            if ($commentBody !== $body) {
                throw new OrbitResolutionPublicationFailed(
                    'A resolution publication already exists for this dispatch with different content.',
                );
            }

            $matches[] = $id;
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<mixed, mixed>
     */
    private function request(string $target, string $profile, string $document, array $variables): array
    {
        try {
            $input = json_encode([
                'service' => 'linear',
                'document' => $document,
                'variables' => $variables,
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);

            if ($result->failed()) {
                throw new RuntimeException('The Hermes Linear RPC process failed.');
            }

            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitResolutionPublicationFailed('The Hermes resolution publisher could not run.', 0, $exception);
        }

        if (! is_array($response)) {
            throw new OrbitResolutionPublicationFailed('The Hermes resolution publisher returned invalid JSON.');
        }

        return $response;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
