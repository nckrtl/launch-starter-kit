<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\StartOrbitDeliveryCleanup;
use App\Delivery\Exceptions\OrbitDeliveryCleanupFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Validator;

#[Signature('delivery:abort-orbit
    {delivery : Commander delivery ID}
    {--force : Run without confirmation in production}')]
#[Description('Safely clean up and terminalize one blocked pre-merge Orbit delivery')]
final class AbortOrbitDeliveryCommand extends Command
{
    use ConfirmableTrait;

    public function handle(StartOrbitDeliveryCleanup $cleanup): int
    {
        if (! $this->confirmToProceed('This will clean up a blocked Orbit delivery.')) {
            return self::FAILURE;
        }

        $validator = Validator::make(['delivery' => $this->argument('delivery')], [
            'delivery' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        /** @var array{delivery: int|string} $input */
        $input = $validator->validated();
        $deliveryId = (int) $input['delivery'];

        try {
            $phase = $cleanup->handle($deliveryId);
        } catch (OrbitDeliveryCleanupFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Orbit delivery {$deliveryId} cleanup queued as phase {$phase->id}.");

        return self::SUCCESS;
    }
}
