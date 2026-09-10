<?php

namespace App\Console\Commands;

use App\Herdr\TomWebhookClient;
use App\Models\HerdrEvent;
use Illuminate\Console\Command;
use Throwable;

final class HerdrTestPost extends Command
{
    protected $signature = 'herdr:test-post';

    protected $description = 'Send the latest recorded Herdr event directly to Tom';

    public function handle(TomWebhookClient $webhook): int
    {
        $event = HerdrEvent::query()->latest('id')->first();

        if ($event === null) {
            $this->error('No recorded Herdr event is available to send.');

            return self::FAILURE;
        }

        try {
            $webhook->send($event);

            if ($event->notified_at === null) {
                $event->notified_at = now();
                $event->save();
            }
        } catch (Throwable $exception) {
            $this->error(sprintf('ok: false (%s)', $exception->getMessage()));

            return self::FAILURE;
        }

        $this->info("ok: true (event {$event->id})");

        return self::SUCCESS;
    }
}
