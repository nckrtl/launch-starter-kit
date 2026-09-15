<?php

declare(strict_types=1);

namespace App\Tasks\Preparation;

use Closure;

/** @phpstan-type PreparationObservation array{repository: string, root: string, source: string, worktree: string, primary: string, remote_main: string, inputs: array<string, string>} */
interface TaskWorktreePreparation
{
    /** @return PreparationObservation */
    public function inspect(string $repository, string $root, string $source): array;

    public function assertUnowned(string $socket, string $worktree): void;

    /** @param PreparationObservation $before
     * @param  array<string, string|false>  $environment
     * @param  Closure(string, string):void  $output
     * @return array<string, mixed>
     */
    public function run(array $before, string $directory, array $environment, int $timeout, Closure $output): array;
}
