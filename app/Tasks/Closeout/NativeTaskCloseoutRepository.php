<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Models\TaskLanding;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeTaskCloseoutRepository implements TaskCloseoutRepository
{
    public function __construct(private TaskCloseoutContext $context) {}

    public function branch(TaskLanding $landing): ?array
    {
        $workspace = $this->context->guard($landing);
        $ref = 'refs/heads/'.strtolower($workspace->source_key);
        $result = $this->run($workspace->repository, ['git', '--no-replace-objects', 'ls-remote', '--refs', 'origin', $ref]);
        $this->success($result);
        $output = trim($result->output());
        if ($output === '') {
            return null;
        }
        if ($output !== $landing->candidate_sha."\t".$ref) {
            throw new LogicException('The remote feature branch differs; do not overwrite another candidate.');
        }

        return ['ref' => $ref, 'candidate_sha' => $landing->candidate_sha];
    }

    public function push(TaskLanding $landing): void
    {
        $workspace = $this->context->observe($landing);
        if ($this->branch($landing) !== null) {
            return;
        }
        $ref = 'refs/heads/'.strtolower($workspace->source_key);
        // An empty expected value is create-only, including when the ref appears after our read.
        $this->success($this->run($workspace->worktree, ['git', '--no-replace-objects',
            'push', '--porcelain', '--force-with-lease='.$ref.':', 'origin', $landing->candidate_sha.':'.$ref], 120));
    }

    public function branchRevision(TaskLanding $landing, array $request): array
    {
        $workspace = $this->context->guard($landing);
        $request = TaskPublicationRevisionData::request($landing, $request);
        $old = TaskLandingData::text($request, 'head_sha');
        if (! $this->contains($this->context->configuration($workspace), $old, $landing->candidate_sha)) {
            throw new LogicException('Publication revision only permits a fast-forward descendant of the pinned old head.');
        }
        $ref = 'refs/heads/'.TaskLandingData::text($request, 'head_ref');
        $result = $this->run($workspace->repository, ['git', '--no-replace-objects', 'ls-remote', '--refs', 'origin', $ref]);
        $this->success($result);
        $output = trim($result->output());
        if (! in_array($output, [$old."\t".$ref, $landing->candidate_sha."\t".$ref], true)) {
            throw new LogicException('The revision remote branch is missing or changed from both pinned heads.');
        }

        return ['state' => $output === $old."\t".$ref ? 'before' : 'after', 'ref' => $ref,
            'candidate_sha' => $output === $old."\t".$ref ? $old : $landing->candidate_sha];
    }

    public function reviseBranch(TaskLanding $landing, array $request): void
    {
        $this->context->approved($landing->id, (string) $landing->package_hash);
        $workspace = $this->context->observe($landing);
        $observation = $this->branchRevision($landing, $request);
        if ($observation['state'] !== 'before') {
            throw new LogicException('The branch revision write requires the exact old remote head.');
        }
        $ref = TaskLandingData::text($observation, 'ref');
        $this->success($this->run($workspace->worktree, ['git', '--no-replace-objects', 'push', '--porcelain',
            '--force-with-lease='.$ref.':'.TaskLandingData::text($request, 'head_sha'),
            'origin', $landing->candidate_sha.':'.$ref], 120));
    }

    public function main(OrbitProjectConfig $configuration): OrbitMainCorrectness
    {
        $script = $this->script($configuration, 'tia-cache');
        $result = $this->run($configuration->repository, [$script, 'status', '--json', '--remote'], 120);
        $this->success($result);
        $data = TaskLandingData::object(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));
        $main = TaskLandingData::text($data, 'main');
        GitObjectId::validate($main);
        $failures = $data['correctness_failures'] ?? null;
        if (($data['schema'] ?? null) !== 1 || ! is_array($failures)) {
            throw new LogicException('Native main correctness status is incomplete.');
        }
        foreach ($failures as $project => $failure) {
            if (! is_string($project) || trim($project) === '') {
                throw new LogicException('Native main correctness failure identities are incomplete.');
            }
        }

        return new OrbitMainCorrectness($main, $failures);
    }

    public function verify(TaskLanding $landing, array $merge): array
    {
        $workspace = $this->context->guard($landing);
        $configuration = $this->context->configuration($workspace);
        $mergeSha = TaskLandingData::text($merge, 'merge_sha');
        GitObjectId::validate($mergeSha);
        $script = $this->script($configuration, 'loop-flow');
        $this->success($this->run($workspace->repository, ['git', '--no-replace-objects', 'fetch', 'origin', 'main'], 120));
        $main = $this->main($configuration);
        if (! $this->contains($configuration, $mergeSha, $main->mainSha)) {
            throw new LogicException('The authoritative main does not contain the exact reviewed merge.');
        }
        $result = $this->run($workspace->repository, [$script, 'verify-merge', '--worktree='.$workspace->worktree,
            '--candidate='.$landing->candidate_sha, '--merge='.$mergeSha], 60);
        $this->success($result);
        $lineage = TaskLandingData::object(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));
        if (count($lineage) !== 4 || ($lineage['flow'] ?? null) !== (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? 'discovery')
            || ($lineage['candidate'] ?? null) !== $landing->candidate_sha || ($lineage['merge'] ?? null) !== $mergeSha) {
            throw new LogicException('Native merge lineage does not match this reviewed package and admitted flow.');
        }
        GitObjectId::validate(TaskLandingData::text($lineage, 'tree'));

        return ['lineage' => $lineage, 'main_sha' => $main->mainSha, 'native_failures' => $main->failures,
            'native_failures_hash' => TaskLandingData::hash($main->failures)];
    }

    public function contains(OrbitProjectConfig $configuration, string $ancestor, string $main): bool
    {
        GitObjectId::validate($ancestor);
        GitObjectId::validate($main);
        $result = $this->run($configuration->repository, ['git', '--no-replace-objects', 'merge-base', '--is-ancestor', $ancestor, $main]);
        if (! in_array($result->exitCode(), [0, 1], true)) {
            throw new LogicException('The exact main ancestry cannot be inspected without fetching missing objects.');
        }

        return $result->successful();
    }

    private function script(OrbitProjectConfig $configuration, string $name): string
    {
        $path = $configuration->repository.'/bin/'.$name;
        if (realpath($configuration->repository) !== $configuration->repository
            || realpath($path) !== $path || is_link($path) || ! is_executable($path)) {
            throw new LogicException('The canonical native Orbit closeout script is unavailable.');
        }

        return $path;
    }

    private function success(ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new LogicException('The native Orbit closeout operation failed or has an uncertain response.');
        }
    }

    /** @param list<string> $command */
    private function run(string $directory, array $command, int $timeout = 30): ProcessResult
    {
        return Process::path($directory)->env([...TaskProcessEnvironment::isolated(), 'GIT_NO_LAZY_FETCH' => '1',
            'GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0'])->timeout($timeout)->run($command);
    }
}
