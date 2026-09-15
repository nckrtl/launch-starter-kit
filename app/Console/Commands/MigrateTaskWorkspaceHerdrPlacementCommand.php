<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\MigrateTaskWorkspaceHerdrPlacement;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:workspace:migrate-herdr-placement {project} {source} {--expected=} {--exclusive : Attest that no task mutation or placement migration is running}')]
#[Description('Migrate legacy task workspaces to one verified Orbit Herdr placement')]
final class MigrateTaskWorkspaceHerdrPlacementCommand extends Command
{
    public function handle(MigrateTaskWorkspaceHerdrPlacement $migrate): int
    {
        try {
            $source = filter_var($this->argument('source'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $expected = filter_var($this->option('expected'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! is_int($source) || ! is_int($expected)) {
                throw new LogicException('Use a positive source workspace ID and a non-negative expected count.');
            }

            $result = $migrate->handle(
                (string) $this->argument('project'),
                $source,
                $expected,
                (bool) $this->option('exclusive'),
            );
            $this->line(TaskLandingData::json($result));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
