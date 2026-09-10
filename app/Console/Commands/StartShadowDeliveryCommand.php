<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
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

#[Signature('delivery:start-shadow
    {project : Configured project slug}
    {issue-id : Stable external issue ID}
    {issue-key : Human-readable issue key, for example ORB-234}
    {--force : Run without confirmation in production}')]
#[Description('Start one harmless delivery for Herdr shadow verification')]
final class StartShadowDeliveryCommand extends Command
{
    use ConfirmableTrait;

    public function handle(ProjectConfigRegistry $configs, OrbitRepository $repository, StartShadowDelivery $start): int
    {
        if (! config('herdr.orchestration.enabled', false)) {
            $this->error('Herdr orchestration shadow mode is disabled.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('This will start a real Herdr shadow agent.')) {
            return self::FAILURE;
        }

        $validator = Validator::make([
            'project' => $this->argument('project'),
            'issue_id' => $this->argument('issue-id'),
            'issue_key' => $this->argument('issue-key'),
        ], [
            'project' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'issue_id' => ['required', 'string', 'max:100'],
            'issue_key' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9]*-[0-9]+$/'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        /** @var array{project: string, issue_id: string, issue_key: string} $input */
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
            $this->error('This command currently supports Orbit projects only.');

            return self::FAILURE;
        }

        if (Delivery::query()
            ->whereBelongsTo($project)
            ->where('external_issue_provider', 'linear')
            ->where('external_issue_id', $input['issue_id'])
            ->active()
            ->exists()) {
            $this->error("An active delivery already exists for [{$input['issue_key']}].");

            return self::FAILURE;
        }

        try {
            $worktree = $repository->prepareWorktree($config, $input['issue_key']);
        } catch (OrbitRepositoryFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $delivery = $start->handle($project, $input['issue_id'], $input['issue_key'], $worktree->path, $worktree->headSha);

        AdvanceDelivery::dispatch($delivery->id)->afterCommit();

        $this->info("Shadow delivery {$delivery->id} queued for {$input['issue_key']} in project {$input['project']}.");

        return self::SUCCESS;
    }
}
