<?php

namespace App\Console\Commands;

use App\Herdr\Debouncer;
use App\Herdr\Listener;
use App\Herdr\SocketClient;
use App\Herdr\StatusTracker;
use App\Herdr\TransitionRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class HerdrListen extends Command
{
    protected $signature = 'herdr:listen
        {--dry-run : Log transitions instead of recording and posting them}
        {--timeout= : Stop after this many seconds}';

    protected $description = 'Follow the Herdr session and post agent-status changes to Slack';

    public function handle(TransitionRecorder $recorder): int
    {
        $socket = config('herdr.socket');
        $channel = config('herdr.slack_channel');
        $dryRun = (bool) $this->option('dry-run');
        $timeout = $this->option('timeout');
        $statuses = config('herdr.notify_statuses');
        $debounce = config('herdr.debounce_seconds');

        if (! is_string($socket) || $socket === '') {
            $this->error('HERDR_SOCKET_PATH is not configured.');

            return self::FAILURE;
        }

        if (! $dryRun && (! is_string($channel) || $channel === '')) {
            $this->error('HERDR_SLACK_CHANNEL is not configured.');

            return self::FAILURE;
        }

        $log = function (string $message): void {
            $this->line(now()->format('Y-m-d H:i:s').' '.$message);
            Log::info('herdr: '.$message);
        };

        $listener = new Listener(
            client: fn (): SocketClient => new SocketClient($socket),
            tracker: new StatusTracker(
                array_values(array_filter(is_array($statuses) ? $statuses : [], 'is_string')),
                new Debouncer(is_numeric($debounce) ? (float) $debounce : 5.0),
            ),
            recorder: $recorder,
            log: $log,
            dryRun: $dryRun,
        );

        $log(sprintf('listening on %s%s', $socket, $dryRun ? ' (dry run)' : ''));

        $listener->run(is_numeric($timeout) ? (float) $timeout : null);

        return self::SUCCESS;
    }
}
