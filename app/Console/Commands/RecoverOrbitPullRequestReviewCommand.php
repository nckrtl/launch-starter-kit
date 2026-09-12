<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\RecoverOrbitPullRequestReviewTransition;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Jobs\DispatchOrbitPullRequestReview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('delivery:recover-orbit-pr-review
    {delivery : Blocked Orbit delivery ID}')]
#[Description('Resume an ambiguous Linear pull request review transition after verified read-back')]
final class RecoverOrbitPullRequestReviewCommand extends Command
{
    public function handle(RecoverOrbitPullRequestReviewTransition $recover): int
    {
        $deliveryId = $this->positiveIntegerArgument('delivery');

        if ($deliveryId === null) {
            $this->error('The delivery ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $phase = $recover->handle($deliveryId);
        } catch (OrbitPullRequestReviewDispatchFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        DispatchOrbitPullRequestReview::dispatch($deliveryId, $phase->id);
        $this->info("Orbit pull request review delivery {$deliveryId} recovered on phase {$phase->id}.");

        return self::SUCCESS;
    }

    private function positiveIntegerArgument(string $name): ?int
    {
        $value = $this->argument($name);

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
