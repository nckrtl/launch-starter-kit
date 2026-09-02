<?php

namespace App\Console\Commands;

use App\Notifications\HerdrBridgeOnline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class HerdrTestPost extends Command
{
    protected $signature = 'herdr:test-post';

    protected $description = 'Post "Commander: Herdr bridge online" to the configured Slack channel';

    public function handle(): int
    {
        $channel = config('herdr.slack_channel');

        if (! is_string($channel) || $channel === '') {
            $this->error('HERDR_SLACK_CHANNEL is not configured.');

            return self::FAILURE;
        }

        try {
            Notification::route('slack', $channel)->notify(new HerdrBridgeOnline);
        } catch (Throwable $exception) {
            $this->error(sprintf('ok: false (%s)', $exception->getMessage()));

            return self::FAILURE;
        }

        $this->info("ok: true (channel {$channel})");

        return self::SUCCESS;
    }
}
