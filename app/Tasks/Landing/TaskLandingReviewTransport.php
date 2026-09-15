<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Herdr\SocketClient;
use LogicException;

final class TaskLandingReviewTransport
{
    public const int MAX_REQUEST_BYTES = 65_536;

    /** @param array<string, mixed> $session */
    public static function line(array $session, string $prompt): string
    {
        return SocketClient::requestLine('2', 'agent.prompt', ['target' => TaskLandingData::text($session, 'agentName'), 'text' => $prompt]);
    }

    /** @param array<string, mixed> $session
     * @return array{prompt_sha256:string,prompt_bytes:int,wire_sha256:string,wire_bytes:int,budget_bytes:int}
     */
    public static function inspect(array $session, string $prompt): array
    {
        $line = self::line($session, $prompt);
        $reservedBytes = strlen((string) PHP_INT_MAX) - 1;
        if (strlen($line) + $reservedBytes > self::MAX_REQUEST_BYTES) {
            throw new LogicException('The serialized supplemental review request exceeds the 64 KiB application budget. No assignment or recovery send may be claimed.');
        }

        return ['prompt_sha256' => hash('sha256', $prompt), 'prompt_bytes' => strlen($prompt),
            'wire_sha256' => hash('sha256', $line), 'wire_bytes' => strlen($line), 'budget_bytes' => self::MAX_REQUEST_BYTES];
    }
}
