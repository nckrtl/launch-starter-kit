<?php

namespace Tests\Support;

use RuntimeException;

/**
 * A stand-in for the Herdr socket server: newline-delimited JSON on a Unix socket,
 * answering monitoring and orchestration methods from a scenario file
 * and pushing the scenario's events after each subscription acknowledgement.
 *
 * Like Herdr it serves many connections at once, so a request is answered while a
 * subscription stays open. The optional `later_agents` roster answers every agent.list
 * after the first, which is how a scenario changes the roster behind a lifecycle event.
 */
final class FakeHerdrServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly string $socketPath,
        private readonly string $directory,
    ) {}

    /**
     * @param  array{agents: list<array<string, mixed>>, workspaces: list<array<string, mixed>>, events: list<array<string, mixed>>, later_agents?: list<array<string, mixed>>, rpc?: array<string, array<string, mixed>>, rpc_sequences?: array<string, list<array{result?: array<string, mixed>, error?: array{code: string, message: string}}>>}  $scenario
     */
    public static function start(array $scenario): self
    {
        $directory = sys_get_temp_dir().'/herdr-fake-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);

        $socketPath = $directory.'/herdr.sock';
        file_put_contents($directory.'/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, __FILE__, $socketPath, $directory],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/stdout.log', 'a'], 2 => ['file', $directory.'/stderr.log', 'a']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Could not start the fake Herdr server.');
        }

        $deadline = microtime(true) + 3.0;

        while (! file_exists($socketPath)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The fake Herdr server did not open its socket.');
            }

            usleep(10_000);
        }

        return new self($process, $socketPath, $directory);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        $log = @file_get_contents($this->directory.'/requests.log');

        if ($log === false) {
            return [];
        }

        return array_values(array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $log), fn (string $line): bool => $line !== ''),
        ));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public static function serve(string $socketPath, string $directory): never
    {
        $scenario = json_decode((string) file_get_contents($directory.'/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
        $server = stream_socket_server('unix://'.$socketPath, $errno, $errstr);

        if ($server === false) {
            fwrite(STDERR, "fake herdr: {$errstr}\n");
            exit(1);
        }

        $peers = [];
        $buffers = [];
        $agentListCalls = 0;
        $rpcCalls = [];

        while (true) {
            $read = [$server, ...array_values($peers)];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 5) === false) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $server) {
                    $peer = @stream_socket_accept($server, 0);

                    if ($peer !== false) {
                        $peers[(int) $peer] = $peer;
                        $buffers[(int) $peer] = '';
                    }

                    continue;
                }

                $chunk = fread($stream, 65536);

                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($peers[(int) $stream], $buffers[(int) $stream]);

                    continue;
                }

                $buffers[(int) $stream] .= $chunk;

                while (($newline = strpos($buffers[(int) $stream], "\n")) !== false) {
                    $line = substr($buffers[(int) $stream], 0, $newline + 1);
                    $buffers[(int) $stream] = substr($buffers[(int) $stream], $newline + 1);

                    self::answer($stream, $line, $scenario, $directory, $agentListCalls, $rpcCalls);
                }
            }
        }
    }

    /**
     * @param  resource  $peer
     * @param  array<string, mixed>  $scenario
     * @param  array<string, int>  $rpcCalls
     */
    private static function answer($peer, string $line, array $scenario, string $directory, int &$agentListCalls, array &$rpcCalls): void
    {
        file_put_contents($directory.'/requests.log', $line, FILE_APPEND);
        $request = json_decode($line, true);
        $id = $request['id'] ?? '';
        $method = $request['method'] ?? '';

        $configured = is_array($scenario['rpc'][$method] ?? null) ? $scenario['rpc'][$method] : null;
        $sequence = is_array($scenario['rpc_sequences'][$method] ?? null) ? $scenario['rpc_sequences'][$method] : null;

        if ($sequence !== null && $sequence !== []) {
            $call = $rpcCalls[$method] ?? 0;
            $rpcCalls[$method] = $call + 1;
            $sequenced = $sequence[min($call, count($sequence) - 1)];
            $reply = ['id' => $id, ...$sequenced];
        } else {
            $reply = $configured !== null ? ['id' => $id, 'result' => $configured] : match ($method) {
                'ping' => ['id' => $id, 'result' => ['type' => 'pong', 'version' => 'fake', 'protocol' => 20]],
                'agent.list' => ['id' => $id, 'result' => [
                    'type' => 'agent_list',
                    'agents' => $agentListCalls++ === 0 ? $scenario['agents'] : ($scenario['later_agents'] ?? $scenario['agents']),
                ]],
                'workspace.list' => ['id' => $id, 'result' => ['type' => 'workspace_list', 'workspaces' => $scenario['workspaces']]],
                'events.subscribe' => ['id' => $id, 'result' => ['type' => 'subscription_started']],
                default => ['id' => $id, 'error' => ['code' => 'unknown_method', 'message' => "unknown method {$method}"]],
            };
        }

        fwrite($peer, json_encode($reply)."\n");

        if ($method === 'events.subscribe') {
            foreach ($scenario['events'] as $event) {
                fwrite($peer, json_encode($event)."\n");
            }
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0], $argv[1], $argv[2]) && realpath($argv[0]) === __FILE__) {
    FakeHerdrServer::serve($argv[1], $argv[2]);
}
