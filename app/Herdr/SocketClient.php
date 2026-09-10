<?php

namespace App\Herdr;

/**
 * Newline-delimited JSON client for the Herdr socket API.
 *
 * Herdr answers a request and closes that connection; only a subscription keeps its connection open.
 */
final class SocketClient
{
    /**
     * @var resource|null
     */
    private $subscription = null;

    private string $buffer = '';

    private int $nextId = 1;

    public function __construct(
        private readonly string $path,
        private readonly float $timeout = 5.0,
    ) {}

    /**
     * Send one request on its own connection and return the result.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $stream = $this->open();
        $buffer = '';

        try {
            return $this->exchange($stream, $buffer, $method, $params);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Open the long-lived subscription connection; pushed events follow through readLine().
     *
     * @param  list<array<string, mixed>>  $subscriptions
     */
    public function subscribe(array $subscriptions): void
    {
        $this->close();

        $stream = $this->open();

        try {
            $result = $this->exchange($stream, $this->buffer, 'events.subscribe', ['subscriptions' => $subscriptions]);
        } catch (ConnectionClosed|RequestFailed $exception) {
            fclose($stream);

            throw $exception;
        }

        if (($result['type'] ?? null) !== 'subscription_started') {
            fclose($stream);

            throw new RequestFailed('events.subscribe was not acknowledged');
        }

        $this->subscription = $stream;
    }

    /**
     * Next pushed event, or null when nothing arrived within the timeout.
     *
     * @return array<string, mixed>|null
     */
    public function readLine(float $timeout): ?array
    {
        if ($this->subscription === null) {
            throw new ConnectionClosed('No Herdr subscription is open');
        }

        return $this->nextLine($this->subscription, $this->buffer, $timeout);
    }

    public function close(): void
    {
        if ($this->subscription !== null) {
            fclose($this->subscription);
            $this->subscription = null;
            $this->buffer = '';
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $stream = @stream_socket_client('unix://'.$this->path, $errno, $errstr, $this->timeout);

        if ($stream === false) {
            throw new ConnectionFailed(sprintf('Cannot connect to Herdr socket %s: %s', $this->path, $errstr === '' ? "error {$errno}" : $errstr));
        }

        return $stream;
    }

    /**
     * Send one request and return its result; bytes read past the response stay in the buffer.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function exchange($stream, string &$buffer, string $method, array $params): array
    {
        $id = (string) $this->nextId++;
        $line = json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR)."\n";

        if (@fwrite($stream, $line) !== strlen($line)) {
            throw new ConnectionClosed("Herdr did not accept the {$method} request");
        }

        stream_set_blocking($stream, false);

        $deadline = microtime(true) + $this->timeout;

        while (($response = $this->nextLine($stream, $buffer, $deadline - microtime(true))) !== null) {
            if (array_key_exists('error', $response)) {
                $error = Payload::assoc($response['error']);

                throw new RequestFailed(sprintf(
                    '%s failed: %s (%s)',
                    $method,
                    Payload::string($error['message'] ?? null) ?? 'unknown error',
                    Payload::string($error['code'] ?? null) ?? 'unknown',
                ));
            }

            if (($response['id'] ?? null) === $id) {
                return Payload::assoc($response['result'] ?? null);
            }
        }

        throw new RequestFailed("{$method} timed out after {$this->timeout}s");
    }

    /**
     * @param  resource  $stream
     * @return array<string, mixed>|null
     */
    private function nextLine($stream, string &$buffer, float $timeout): ?array
    {
        $deadline = microtime(true) + max(0.0, $timeout);

        while (true) {
            $newline = strpos($buffer, "\n");

            if ($newline !== false) {
                $raw = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    return Payload::assoc($decoded);
                }

                continue;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            $read = [$stream];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));

            if ($ready === false) {
                throw new ConnectionClosed('select on the Herdr socket failed');
            }

            if ($ready === 0) {
                return null;
            }

            $chunk = fread($stream, 65536);

            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new ConnectionClosed('Herdr closed the connection');
            }

            $buffer .= $chunk;
        }
    }
}
