<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskLanding;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeTaskCloseoutGitHub implements TaskCloseoutGitHub
{
    public function __construct(private TaskPublicationRevisionHistory $revisions, private TaskCloseoutContext $context) {}

    public function identityHash(): string
    {
        return TaskLandingData::hash([...$this->configuration(), 'author' => 'nckrtl', 'reviewer' => 'tom-nckrtl[bot]']);
    }

    public function publication(TaskLanding $landing): ?array
    {
        $branch = strtolower($this->issue($landing));
        $matches = $this->list($this->github("pulls?state=all&head=nckrtl:{$branch}&base=main&per_page=100"));
        if (count($matches) > 1) {
            throw new LogicException('Multiple pull requests match this Tasks issue; reconcile ownership.');
        }
        if ($matches === []) {
            return null;
        }
        $number = $matches[0]['number'] ?? null;
        if (! is_int($number) || $number < 1) {
            throw new LogicException('The Tasks pull request number is invalid.');
        }
        $pr = $this->pullRequest($landing, $number);

        return ['number' => $number, 'url' => $pr['html_url'], 'candidate_sha' => $landing->candidate_sha,
            'title_hash' => hash('sha256', TaskLandingData::text($pr, 'title')),
            'body_hash' => hash('sha256', TaskLandingData::text($pr, 'body'))];
    }

    public function publish(TaskLanding $landing): void
    {
        if ($this->publication($landing) !== null) {
            return;
        }
        $this->github('pulls', 'POST', ['title' => $this->text($landing, 'title'), 'body' => $this->text($landing, 'body'),
            'head' => strtolower($this->issue($landing)), 'base' => 'main', 'draft' => false]);
    }

    public function publicationRevision(TaskLanding $landing, array $request): array
    {
        $request = TaskPublicationRevisionData::request($landing, $request);
        $number = $this->number($request);
        $branch = $this->issue($landing);
        $matches = $this->list($this->github('pulls?state=all&head=nckrtl:'.strtolower($branch).'&base=main&per_page=100'));
        if (count($matches) !== 1 || ($matches[0]['number'] ?? null) !== $number) {
            throw new LogicException('The exact existing revision PR must be the only PR for this issue branch.');
        }
        $pr = TaskLandingData::object($this->github('pulls/'.$number));
        $head = TaskLandingData::object($pr['head'] ?? null);
        $base = TaskLandingData::object($pr['base'] ?? null);
        $author = TaskLandingData::object($pr['user'] ?? null);
        if (($pr['number'] ?? null) !== $number || ($pr['html_url'] ?? null) !== $request['url']
            || ($pr['title'] ?? null) !== $request['title'] || ($pr['state'] ?? null) !== 'open'
            || ($pr['draft'] ?? null) !== false || ($pr['merged'] ?? null) !== false
            || ($head['ref'] ?? null) !== $request['head_ref'] || ($base['ref'] ?? null) !== $request['base_ref']
            || (TaskLandingData::object($head['repo'] ?? null)['full_name'] ?? null) !== $request['repository']
            || (TaskLandingData::object($base['repo'] ?? null)['full_name'] ?? null) !== $request['repository']
            || ($author['login'] ?? null) !== $request['author_login'] || ($author['type'] ?? null) !== $request['author_type']) {
            throw new LogicException('The revision PR identity, title or open state changed.');
        }
        $state = match (true) {
            ($head['sha'] ?? null) === $request['head_sha'] && ($pr['body'] ?? null) === $request['body'] => 'before',
            ($head['sha'] ?? null) === $landing->candidate_sha && ($pr['body'] ?? null) === $request['body'] => 'branch_changed',
            ($head['sha'] ?? null) === $landing->candidate_sha && ($pr['body'] ?? null) === $this->text($landing, 'body') => 'after',
            default => throw new LogicException('The revision PR head or body differs from the pinned before/after states.'),
        };
        $reviews = $this->list($this->github('pulls/'.$number.'/reviews?per_page=100'));
        $this->assertRevisionReviews($landing, $request, $reviews);

        return ['state' => $state, 'number' => $number, 'url' => $request['url'], 'head_sha' => $head['sha'],
            'title_sha256' => hash('sha256', TaskLandingData::text($request, 'title')), 'body_sha256' => hash('sha256', TaskLandingData::text($pr, 'body')),
            'reviews' => $this->reviewSnapshot($reviews)];
    }

    public function revisePublication(TaskLanding $landing, array $request): void
    {
        $this->context->approved($landing->id, (string) $landing->package_hash);
        $this->context->observe($landing);
        if ($this->publicationRevision($landing, $request)['state'] !== 'branch_changed') {
            throw new LogicException('A PR body revision requires the advanced head and exact old body immediately before PATCH.');
        }
        // GitHub PATCH has no server-side compare-and-swap. Exclusive ownership and readback remain necessary.
        $this->github('pulls/'.$this->number($request), 'PATCH', ['title' => $this->text($landing, 'title'), 'body' => $this->text($landing, 'body')]);
    }

    public function approval(TaskLanding $landing, array $pr, string $marker, bool $forMerge = false): ?array
    {
        $number = $this->number($pr);
        $current = $this->pullRequest($landing, $number);
        $this->assertOpen($current);
        $reviews = $this->list($this->github("pulls/{$number}/reviews?per_page=100"));
        $revision = $this->revisions->authorized($landing, $this->identityHash());
        if ($revision !== null) {
            $this->assertRevisionReviews($landing, $revision, $reviews);
        }
        $matches = [];
        foreach ($reviews as $review) {
            $user = TaskLandingData::object($review['user'] ?? null);
            if (! is_int($review['id'] ?? null) || $review['id'] < 1
                || ! is_string($review['commit_id'] ?? null) || ! is_string($review['state'] ?? null)
                || ! is_string($review['body'] ?? null) || ! is_string($user['login'] ?? null)) {
                throw new LogicException('The GitHub review history is incomplete.');
            }
            if ($review['body'] === $marker) {
                if ($user['login'] !== 'tom-nckrtl[bot]' || ($user['type'] ?? null) !== 'Bot'
                    || $review['commit_id'] !== $landing->candidate_sha || $review['state'] !== 'APPROVED') {
                    throw new LogicException('The exact Tasks package approval has changed or is not independently authorized.');
                }
                $matches[] = $review;
            }
        }
        if (count($matches) > 1) {
            throw new LogicException('The Tasks package has conflicting duplicate approval records.');
        }
        $match = $matches[0] ?? null;
        $latest = [];
        foreach ($reviews as $review) {
            $login = TaskLandingData::text(TaskLandingData::object($review['user']), 'login');
            if (in_array($review['state'], ['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'], true)
                && $review['id'] > ($latest[$login]['id'] ?? 0)) {
                $latest[$login] = $review;
            }
        }
        foreach ($latest as $review) {
            if ($review['state'] === 'CHANGES_REQUESTED') {
                $user = TaskLandingData::object($review['user']);
                if ($revision !== null && ! $forMerge && $match === null
                    && $user['login'] === 'tom-nckrtl[bot]' && ($user['type'] ?? null) === 'Bot') {
                    continue;
                }
                throw new LogicException('Actionable GitHub changes are requested on this exact candidate.');
            }
        }
        if ($match === null) {
            return null;
        }
        if ($revision !== null && ($latest['tom-nckrtl[bot]']['id'] ?? null) !== $match['id']) {
            throw new LogicException('The exact package approval is no longer the reviewer principal\'s latest verdict.');
        }
        $rules = $this->list($this->github('rules/branches/main'));
        if (array_diff(['deletion', 'non_fast_forward'], array_map(fn (array $rule): string => TaskLandingData::text($rule, 'type'), $rules)) !== []) {
            throw new LogicException('Orbit main branch protection differs from the merge contract.');
        }
        $mergeable = $current['mergeable'] ?? null;
        if (! is_bool($mergeable) && $mergeable !== null) {
            throw new LogicException('GitHub mergeability is malformed.');
        }
        if ($forMerge && $mergeable !== true) {
            throw new LogicException('GitHub has not confirmed that this exact reviewed package is mergeable.');
        }

        return ['review_id' => $match['id'], 'reviewer' => 'tom-nckrtl[bot]', 'candidate_sha' => $landing->candidate_sha,
            'review_body_hash' => hash('sha256', $marker), 'body_hash' => $pr['body_hash'],
            'title_hash' => $pr['title_hash']];
    }

    public function approve(TaskLanding $landing, array $pr, string $marker): void
    {
        if ($this->approval($landing, $pr, $marker) !== null) {
            return;
        }
        $this->github('pulls/'.$this->number($pr).'/reviews', 'POST',
            ['commit_id' => $landing->candidate_sha, 'body' => $marker, 'event' => 'APPROVE'], true);
    }

    public function reservation(TaskLanding $landing, array $pr): ?array
    {
        $status = TaskLandingData::object($this->rpc(['service' => 'reservation', 'action' => 'status']));
        if (($status['version'] ?? null) !== 1
            || array_diff(['status', 'issue_id', 'pr_url', 'reserved_at'], array_keys($status)) !== []) {
            throw new LogicException('Orbit reservation state is malformed.');
        }
        if (($status['status'] ?? null) === 'idle' && ($status['issue_id'] ?? null) === null
            && ($status['pr_url'] ?? null) === null && ($status['reserved_at'] ?? null) === null) {
            return null;
        }
        if (($status['status'] ?? null) !== 'active' || ($status['issue_id'] ?? null) !== $landing->issue_id
            || ($status['pr_url'] ?? null) !== ($pr['url'] ?? null)) {
            throw new LogicException('The Orbit merge reservation belongs to another coordinator.');
        }

        return ['issue_id' => $landing->issue_id, 'url' => $pr['url'], 'reserved_at' => TaskLandingData::text($status, 'reserved_at')];
    }

    public function reserve(TaskLanding $landing, array $pr): void
    {
        if ($this->reservation($landing, $pr) === null) {
            $this->rpc(['service' => 'reservation', 'action' => 'reserve', 'issue' => $landing->issue_id,
                'pr' => TaskLandingData::text($pr, 'url')]);
        }
    }

    public function merged(TaskLanding $landing, array $pr): ?array
    {
        $number = $this->number($pr);
        $current = $this->pullRequest($landing, $number);
        if (($current['state'] ?? null) === 'open') {
            return null;
        }
        $merge = TaskLandingData::text($current, 'merge_commit_sha');
        GitObjectId::validate($merge);

        return ['number' => $number, 'url' => $current['html_url'], 'candidate_sha' => $landing->candidate_sha,
            'merge_sha' => $merge, 'body_hash' => $pr['body_hash'], 'title_hash' => $pr['title_hash']];
    }

    public function merge(TaskLanding $landing, array $pr): void
    {
        $this->github('pulls/'.$this->number($pr).'/merge', 'PUT', ['sha' => $landing->candidate_sha, 'merge_method' => 'merge']);
    }

    public function release(TaskLanding $landing, array $pr): void
    {
        if ($this->reservation($landing, $pr) !== null) {
            $this->rpc(['service' => 'reservation', 'action' => 'release', 'issue' => $landing->issue_id,
                'pr' => TaskLandingData::text($pr, 'url')]);
        }
    }

    /** @return array<string,mixed> */
    private function pullRequest(TaskLanding $landing, int $number): array
    {
        $pr = TaskLandingData::object($this->github("pulls/{$number}"));
        $head = TaskLandingData::object($pr['head'] ?? null);
        $base = TaskLandingData::object($pr['base'] ?? null);
        $author = TaskLandingData::object($pr['user'] ?? null);
        if (($pr['number'] ?? null) !== $number || ($pr['html_url'] ?? null) !== "https://github.com/nckrtl/orbit/pull/{$number}"
            || ($pr['title'] ?? null) !== $this->text($landing, 'title') || ($pr['body'] ?? null) !== $this->text($landing, 'body')
            || ($pr['draft'] ?? null) !== false || ($head['sha'] ?? null) !== $landing->candidate_sha
            || ($head['ref'] ?? null) !== strtolower($this->issue($landing)) || ($base['ref'] ?? null) !== 'main'
            || (TaskLandingData::object($head['repo'] ?? null)['full_name'] ?? null) !== 'nckrtl/orbit'
            || (TaskLandingData::object($base['repo'] ?? null)['full_name'] ?? null) !== 'nckrtl/orbit'
            || ($author['login'] ?? null) !== 'nckrtl' || ($author['type'] ?? null) !== 'User'
            || ! in_array($pr['state'] ?? null, ['open', 'closed'], true)
            || ($pr['state'] === 'closed' && ($pr['merged'] ?? null) !== true)) {
            throw new LogicException('The published PR title, body, head, identity or state differs from the exact approved Tasks package.');
        }

        return $pr;
    }

    /** @param array<string,mixed> $pr */
    private function assertOpen(array $pr): void
    {
        if (($pr['state'] ?? null) !== 'open' || ($pr['merged'] ?? false) !== false) {
            throw new LogicException('Only the exact open Tasks pull request can acquire new approval.');
        }
    }

    /** @param array<string,mixed> $pr */
    private function number(array $pr): int
    {
        $number = $pr['number'] ?? null;
        if (! is_int($number) || $number < 1) {
            throw new LogicException('Missing exact Tasks PR number.');
        }

        return $number;
    }

    private function issue(TaskLanding $landing): string
    {
        return TaskLandingData::text(TaskLandingData::object($landing->inputs['issue'] ?? null), 'identifier');
    }

    /** @param list<array<string,mixed>> $reviews
     * @return list<array<string,mixed>>
     */
    private function reviewSnapshot(array $reviews): array
    {
        $snapshot = [];
        foreach ($reviews as $review) {
            $user = TaskLandingData::object($review['user'] ?? null);
            if (! is_int($review['id'] ?? null) || $review['id'] < 1 || ! is_string($review['body'] ?? null)) {
                throw new LogicException('The revision review history is incomplete.');
            }
            $snapshot[] = ['id' => $review['id'], 'commit_id' => TaskLandingData::text($review, 'commit_id', 40),
                'state' => TaskLandingData::text($review, 'state', 40), 'author_login' => TaskLandingData::text($user, 'login', 255),
                'author_type' => TaskLandingData::text($user, 'type', 40), 'body_sha256' => hash('sha256', $review['body'])];
        }
        usort($snapshot, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $snapshot;
    }

    /** @param array<string,mixed> $request
     * @param  list<array<string,mixed>>  $reviews
     */
    private function assertRevisionReviews(TaskLanding $landing, array $request, array $reviews): void
    {
        $snapshot = $this->reviewSnapshot($reviews);
        $marker = $this->context->approvalMarker($landing);
        $retained = [];
        $new = 0;
        $latest = [];
        foreach ($snapshot as $review) {
            if (in_array($review['state'], ['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'], true)) {
                $latest[TaskLandingData::text($review, 'author_login')] = $review;
            }
            if ($review['author_login'] === 'tom-nckrtl[bot]' && $review['author_type'] === 'Bot'
                && $review['commit_id'] === $landing->candidate_sha && $review['state'] === 'APPROVED'
                && $review['body_sha256'] === hash('sha256', $marker)) {
                $new++;
            } else {
                $retained[] = $review;
            }
        }
        if ($new > 1 || $retained !== $request['reviews']) {
            throw new LogicException('The revision review snapshot changed; reconcile new, edited or dismissed reviews.');
        }
        foreach ($latest as $review) {
            if ($review['state'] === 'CHANGES_REQUESTED'
                && ($review['author_login'] !== 'tom-nckrtl[bot]' || $review['author_type'] !== 'Bot')) {
                throw new LogicException('Another reviewer has unresolved changes; revision cannot override that hold.');
            }
        }
    }

    private function text(TaskLanding $landing, string $key): string
    {
        return TaskLandingData::text($landing->package ?? [], $key);
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) >= 100) {
            throw new LogicException('The GitHub list is incomplete; reconcile pagination explicitly.');
        }

        return array_map(TaskLandingData::object(...), $value);
    }

    /** @param array<string,mixed>|null $body */
    private function github(string $path, ?string $method = null, ?array $body = null, bool $app = false): mixed
    {
        return $this->rpc(['service' => 'github', 'path' => 'repos/nckrtl/orbit/'.$path,
            ...($method === null ? [] : ['method' => $method]), ...($body === null ? [] : ['body' => $body]),
            ...($app ? ['app' => true] : [])]);
    }

    /** @param array<string,mixed> $payload */
    private function rpc(array $payload): mixed
    {
        [$target, $profile] = $this->configuration();
        $result = Process::env(TaskProcessEnvironment::isolated())->input(TaskLandingData::json($payload))->timeout(30)->run([
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
            escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
        ]);
        if ($result->failed()) {
            throw new LogicException('The authenticated Orbit RPC refused the operation or returned an uncertain outcome.');
        }

        return json_decode($result->output(), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array{string,string} */
    private function configuration(): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        if (! is_string($target) || preg_match('/\A[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+\z/', $target) !== 1
            || ! is_string($profile) || preg_match('#\A/[A-Za-z0-9._/-]+\z#', $profile) !== 1) {
            throw new LogicException('The existing identity-checking Orbit GitHub RPC is not configured.');
        }

        return [$target, $profile];
    }
}
