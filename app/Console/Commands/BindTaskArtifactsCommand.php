<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Runtime\TaskArtifactReviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:bind-artifacts {workspace} {--run=} {--dispatch=} {--round=} {--manifest=} {--reason=} {--exclusive} {--drained} {--binding=} {--apply}')]
#[Description('Preview or append an immutable artifact-only review binding at an acknowledged implementation handoff')]
final class BindTaskArtifactsCommand extends Command
{
    public function handle(TaskArtifactReviews $artifacts): int
    {
        try {
            $ids = [];
            foreach (['workspace', 'run', 'dispatch', 'round'] as $key) {
                $id = filter_var($key === 'workspace' ? $this->argument($key) : $this->option($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (! is_int($id)) {
                    throw new LogicException('Use positive workspace, run, dispatch and review-round identifiers.');
                }
                $ids[$key] = $id;
            }
            $result = $artifacts->capture($ids['workspace'], $ids['run'], $ids['dispatch'], $ids['round'],
                (string) $this->option('manifest'), (string) $this->option('reason'), (bool) $this->option('exclusive'),
                (bool) $this->option('drained'), $this->option('binding') === null ? null : (string) $this->option('binding'), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
