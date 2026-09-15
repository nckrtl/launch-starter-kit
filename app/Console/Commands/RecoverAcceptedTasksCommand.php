<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Recovery\RecoverAcceptedTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('tasks:recover-accepted {project} {task} {--database=} {--backup=} {--evidence=} {--exclusive} {--apply}')]
#[Description('Validate or record prior accepted children in an explicit offline quarantine database without resuming work')]
final class RecoverAcceptedTasksCommand extends Command
{
    public function handle(RecoverAcceptedTasks $recovery): int
    {
        $taskId = filter_var($this->argument('task'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($taskId)) {
            throw new InvalidArgumentException('A positive root task identifier is required.');
        }
        $result = $recovery->handle((string) $this->argument('project'), $taskId,
            (string) $this->option('database'), (string) $this->option('backup'), (string) $this->option('evidence'),
            (bool) $this->option('exclusive'), (bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
