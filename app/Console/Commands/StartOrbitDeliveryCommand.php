<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\RetireStaleOrbitWorktree;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Actions\VerifyOrbitIssueSnapshot;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueResolver;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

#[Signature('delivery:start-orbit
    {project : Configured project slug}
    {issue-key : Human-readable issue key, for example ORB-234}
    {--idempotent : Treat an existing active delivery as success}
    {--force : Run without confirmation in production}')]
#[Description('Start one live Orbit feature delivery through Commander')]
final class StartOrbitDeliveryCommand extends Command
{
    use ConfirmableTrait;

    private const string OWNERSHIP_LABEL = 'controller:commander';

    private const string MAINTENANCE_LABEL = 'maintenance:monorepo';

    public function handle(
        ProjectConfigRegistry $configs,
        OrbitIssueResolver $resolver,
        OrbitIssueProvider $issues,
        OrbitRepository $repository,
        VerifyOrbitIssueSnapshot $verifyIssue,
        RetireStaleOrbitWorktree $retireStaleWorktree,
        StartOrbitDelivery $start,
    ): int {
        if (! config('herdr.orchestration.enabled', false)) {
            $this->error('Herdr orchestration is disabled.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('This will start a live Orbit feature delivery.')) {
            return self::FAILURE;
        }

        $validator = Validator::make([
            'project' => $this->argument('project'),
            'issue_key' => $this->argument('issue-key'),
        ], [
            'project' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'issue_key' => ['required', 'string', 'max:100', 'regex:/^ORB-[0-9]+$/'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        /** @var array{project: string, issue_key: string} $input */
        $input = $validator->validated();
        $project = ProjectOrchestration::query()->where('manifest_project_id', $input['project'])->first();

        if ($project === null || $project->state !== ProjectOrchestrationState::Enabled) {
            $this->error("Project [{$input['project']}] is not configured and enabled.");

            return self::FAILURE;
        }

        try {
            $config = $configs->hydrate($project->getAttribute('config'));
        } catch (InvalidArgumentException|ValidationException $exception) {
            $this->error('The project has invalid orchestration config: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $config instanceof OrbitProjectConfig) {
            $this->error('This command supports Orbit projects only.');

            return self::FAILURE;
        }

        $active = $this->activeDeliveryForKey($project, $input['issue_key']);

        if ($active !== null) {
            return $this->existingDelivery($active, $input['project'], $input['issue_key']);
        }

        $reservation = null;

        try {
            $reservation = $repository->reserveDelivery($config, $input['issue_key']);

            $active = $this->activeDeliveryForKey($project, $input['issue_key']);

            if ($active !== null) {
                return $this->existingDelivery($active, $input['project'], $input['issue_key']);
            }

            $issue = $resolver->resolve($input['issue_key']);

            if (($ownershipFailure = $this->ownershipFailure($issue->payload, $input['issue_key'])) !== null) {
                $this->error($ownershipFailure);

                return self::FAILURE;
            }

            $active = $this->activeDeliveryForId($project, $issue->issueId);

            if ($active !== null) {
                return $this->existingDelivery($active, $input['project'], $input['issue_key']);
            }

            if ($this->activeDeliveryCount($project) >= $config->concurrency) {
                $this->error("Project [{$input['project']}] has no available delivery capacity.");

                return self::FAILURE;
            }

            $retiredWorktree = $retireStaleWorktree->handle($config, $issue);
            $worktree = $repository->prepareWorktree($config, $input['issue_key']);
            $candidateCheck = $repository->checkCandidate($config, $worktree);
            $issueSnapshot = $repository->writeIssueSnapshot($config, $worktree, $issue);
            $currentIssue = $issues->fetch($issue->issueId, $input['issue_key']);

            if (($ownershipFailure = $this->ownershipFailure($currentIssue->payload, $input['issue_key'])) !== null) {
                $this->error($ownershipFailure);

                return self::FAILURE;
            }

            $verifiedIssue = $verifyIssue->handle($config, $worktree, $issueSnapshot, $currentIssue);
            $delivery = $start->handle(
                $project,
                $verifiedIssue,
                $worktree->path,
                $candidateCheck,
                $retiredWorktree,
            );
        } catch (InvalidArgumentException|OrbitIssueContractChanged|OrbitIssueProviderFailed|OrbitRepositoryFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $reservation?->release();
        }

        AdvanceDelivery::dispatch($delivery->id)->afterCommit();
        $this->info("Orbit delivery {$delivery->id} queued for {$input['issue_key']} in project {$input['project']}.");

        return self::SUCCESS;
    }

    /** @phpstan-impure */
    private function activeDeliveryForKey(ProjectOrchestration $project, string $issueKey): ?Delivery
    {
        return Delivery::query()
            ->whereBelongsTo($project)
            ->where('external_issue_provider', 'linear')
            ->where('external_issue_key', $issueKey)
            ->active()
            ->first();
    }

    /** @phpstan-impure */
    private function activeDeliveryForId(ProjectOrchestration $project, string $issueId): ?Delivery
    {
        return Delivery::query()
            ->whereBelongsTo($project)
            ->where('external_issue_provider', 'linear')
            ->where('external_issue_id', $issueId)
            ->active()
            ->first();
    }

    /** @phpstan-impure */
    private function activeDeliveryCount(ProjectOrchestration $project): int
    {
        return Delivery::query()
            ->whereBelongsTo($project)
            ->occupiesCapacity()
            ->count();
    }

    private function existingDelivery(Delivery $delivery, string $project, string $issueKey): int
    {
        if ($this->option('idempotent') !== true
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
            $this->error("An active delivery already exists for [{$issueKey}].");

            return self::FAILURE;
        }

        $this->info("Orbit delivery {$delivery->id} is already active for {$issueKey} in project {$project}.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function ownershipFailure(array $payload, string $issueKey): ?string
    {
        $labels = $payload['labels'] ?? null;
        $nodes = is_array($labels) ? ($labels['nodes'] ?? null) : null;
        $pageInfo = is_array($labels) ? ($labels['pageInfo'] ?? null) : null;

        if (! is_array($nodes)
            || ! array_is_list($nodes)
            || ! is_array($pageInfo)
            || ($pageInfo['hasNextPage'] ?? null) !== false) {
            return "Orbit issue [{$issueKey}] has incomplete or invalid label data.";
        }

        $names = [];

        foreach ($nodes as $label) {
            $name = is_array($label) ? ($label['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                return "Orbit issue [{$issueKey}] has incomplete or invalid label data.";
            }

            $names[] = $name;
        }

        if (in_array(self::OWNERSHIP_LABEL, $names, true)
            && in_array(self::MAINTENANCE_LABEL, $names, true)) {
            return "Orbit issue [{$issueKey}] has conflicting [".self::OWNERSHIP_LABEL.'] and ['.self::MAINTENANCE_LABEL.'] labels.';
        }

        if (! in_array(self::OWNERSHIP_LABEL, $names, true)) {
            return "Orbit issue [{$issueKey}] is not labeled [".self::OWNERSHIP_LABEL.'].';
        }

        return null;
    }
}
