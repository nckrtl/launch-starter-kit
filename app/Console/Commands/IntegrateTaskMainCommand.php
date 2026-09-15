<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Runtime\ContinueTaskFinal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:integrate-main {workspace} {--dispatch=} {--head=} {--manifest=} {--main=} {--reason=} {--evidence=} {--exclusive} {--apply}')]
#[Description('Preview or append an independently reviewed main integration at an exact held proof boundary')]
final class IntegrateTaskMainCommand extends Command
{
    public function handle(ContinueTaskFinal $continue): int
    {
        try {
            $workspace = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $dispatch = filter_var($this->option('dispatch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($workspace) || ! is_int($dispatch)) {
                throw new LogicException('Use positive workspace and held final dispatch identifiers.');
            }
            $request = ['mode' => 'integrate_main', 'main_sha' => (string) $this->option('main'),
                'reason' => (string) $this->option('reason'), 'evidence' => (string) $this->option('evidence')];
            $result = $continue->handle($workspace, $dispatch, (string) $this->option('head'),
                (string) $this->option('manifest'), $request, (bool) $this->option('exclusive'), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
