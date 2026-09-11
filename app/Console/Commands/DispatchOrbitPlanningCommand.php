<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\DispatchOrbitPlanning;
use Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('delivery:dispatch-orbit-planning
    {delivery : Live Orbit delivery ID}')]
#[Description('Verify and dispatch the planning worker for a live Orbit delivery')]
final class DispatchOrbitPlanningCommand extends Command
{
    public function handle(DispatchOrbitPlanning $dispatch): int
    {
        $deliveryId = $this->positiveIntegerArgument('delivery');

        if ($deliveryId === null) {
            $this->error('The delivery ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $agent = $dispatch->handle($deliveryId);
        } catch (Exception $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Orbit planning dispatch {$agent->id} is {$agent->status->value}.");

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
