<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Queries\HerdrShadowParity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('delivery:shadow-parity
    {--since= : Optional ISO-8601 lower bound for the observation window}')]
#[Description('Report read-only parity between legacy Herdr notifications and Commander raw capture')]
final class ReportHerdrShadowParityCommand extends Command
{
    private const string ISO_8601_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/';

    public function handle(HerdrShadowParity $parity): int
    {
        $since = $this->option('since');

        try {
            if (is_string($since) && $since !== '' && preg_match(self::ISO_8601_PATTERN, $since) !== 1) {
                throw new \InvalidArgumentException('Invalid ISO-8601 timestamp.');
            }

            $since = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;
        } catch (Throwable) {
            $this->error('The shadow parity lower bound must be a valid ISO-8601 timestamp.');

            return self::FAILURE;
        }

        $report = $parity->report($since);
        $this->line(json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
