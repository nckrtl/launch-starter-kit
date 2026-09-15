<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Recovery\ResumeRecoveredTaskWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('tasks:resume-recovered {workspace} {--database=} {--recovery=} {--head=} {--exclusive} {--apply} {--advance}')]
#[Description('Preview or explicitly resume an accepted-child recovery with its verified retained reviewer')]
final class ResumeRecoveredTaskWorkspaceCommand extends Command
{
    public function handle(ResumeRecoveredTaskWorkspace $resume): int
    {
        $workspaceId = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($workspaceId)) {
            throw new InvalidArgumentException('A positive workspace identifier is required.');
        }
        $result = $resume->handle($workspaceId, (string) $this->option('database'), (string) $this->option('recovery'),
            (string) $this->option('head'), (bool) $this->option('exclusive'), (bool) $this->option('apply'), (bool) $this->option('advance'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return isset($result['enqueue_error']) ? self::FAILURE : self::SUCCESS;
    }
}
