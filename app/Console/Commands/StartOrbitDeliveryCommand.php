<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
    {--force : Run without confirmation in production}')]
#[Description('Start one live Orbit feature delivery through Commander')]
final class StartOrbitDeliveryCommand extends Command
{
    use ConfirmableTrait;

    public function handle(
        ProjectConfigRegistry $configs,
        OrbitIssueResolver $resolver,
        OrbitIssueProvider $issues,
        OrbitRepository $repository,
        VerifyOrbitIssueSnapshot $verifyIssue,
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

        if ($this->hasActiveDeliveryForKey($project, $input['issue_key'])) {
            $this->error("An active delivery already exists for [{$input['issue_key']}].");

            return self::FAILURE;
        }

        $reservation = null;

        try {
            $reservation = $repository->reserveDelivery($config, $input['issue_key']);

            if ($this->hasActiveDeliveryForKey($project, $input['issue_key'])) {
                $this->error("An active delivery already exists for [{$input['issue_key']}].");

                return self::FAILURE;
            }

            $issue = $resolver->resolve($input['issue_key']);

            if ($this->hasActiveDeliveryForId($project, $issue->issueId)) {
                $this->error("An active delivery already exists for [{$input['issue_key']}].");

                return self::FAILURE;
            }

            $worktree = $repository->prepareWorktree($config, $input['issue_key']);
            $candidateCheck = $repository->checkCandidate($config, $worktree);
            $issueSnapshot = $repository->writeIssueSnapshot($config, $worktree, $issue);
            $currentIssue = $issues->fetch($issue->issueId, $input['issue_key']);
            $verifiedIssue = $verifyIssue->handle($config, $worktree, $issueSnapshot, $currentIssue);
            $delivery = $start->handle($project, $verifiedIssue, $worktree->path, $candidateCheck);
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
    private function hasActiveDeliveryForKey(ProjectOrchestration $project, string $issueKey): bool
    {
        return Delivery::query()
            ->whereBelongsTo($project)
            ->where('external_issue_provider', 'linear')
            ->where('external_issue_key', $issueKey)
            ->active()
            ->exists();
    }

    /** @phpstan-impure */
    private function hasActiveDeliveryForId(ProjectOrchestration $project, string $issueId): bool
    {
        return Delivery::query()
            ->whereBelongsTo($project)
            ->where('external_issue_provider', 'linear')
            ->where('external_issue_id', $issueId)
            ->active()
            ->exists();
    }
}
