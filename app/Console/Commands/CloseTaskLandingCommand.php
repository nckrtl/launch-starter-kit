<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Closeout\CloseTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:closeout {landing} {--package=} {--stage=} {--exclusive} {--apply}')]
#[Description('Preview or explicitly apply one exact Tasks publication, merge, verification or native closeout stage')]
final class CloseTaskLandingCommand extends Command
{
    public function handle(CloseTaskLanding $closeout): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive landing identifier.');
            }
            $result = $closeout->handle($id, (string) $this->option('package'),
                (string) $this->option('stage'), (bool) $this->option('exclusive'), (bool) $this->option('apply'));
            $this->line(TaskLandingData::json($result));
            if ($this->option('apply') && $this->option('stage') === 'proof-closeout'
                && (TaskLandingData::object($result['closeout'] ?? null)['state'] ?? null) !== 'complete') {
                return self::FAILURE;
            }
            if ($this->option('apply') && $this->option('stage') === 'snapshot-review'
                && (($result['state'] ?? null) !== 'completed' || (TaskLandingData::object($result['review'] ?? null)['verdict'] ?? null) !== 'pass')) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
