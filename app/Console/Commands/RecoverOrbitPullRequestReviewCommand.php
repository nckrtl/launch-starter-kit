<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\DispatchOrbitPullRequestReview;
use App\Delivery\Actions\RecoverOrbitPullRequestReviewTransition;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\DispatchOrbitImplementation as DispatchOrbitImplementationJob;
use App\Jobs\DispatchOrbitPullRequestReview as DispatchOrbitPullRequestReviewJob;
use App\Models\Delivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('delivery:recover-orbit-pr-review
    {delivery : Blocked Orbit delivery ID}')]
#[Description('Resume a safely recoverable Orbit pull request review after verified read-back')]
final class RecoverOrbitPullRequestReviewCommand extends Command
{
    public function handle(
        RecoverOrbitPullRequestReviewTransition $recover,
        DispatchOrbitPullRequestReview $dispatch,
    ): int {
        $deliveryId = $this->positiveIntegerArgument('delivery');

        if ($deliveryId === null) {
            $this->error('The delivery ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $failure = Delivery::query()->find($deliveryId)?->failure_details;

            if (is_array($failure) && ($failure['code'] ?? null) === 'pr_review_dispatch_interrupted') {
                $recovered = $dispatch->recoverInterrupted($deliveryId);
                $this->info(
                    "Orbit pull request review delivery {$deliveryId} resumed on dispatch {$recovered->id}.",
                );

                return self::SUCCESS;
            }

            $phase = $recover->handle($deliveryId);
        } catch (OrbitPullRequestReviewDispatchFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($phase->phase_name === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE) {
            DispatchOrbitImplementationJob::dispatch($deliveryId);
        } else {
            DispatchOrbitPullRequestReviewJob::dispatch($deliveryId, $phase->id);
        }
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
