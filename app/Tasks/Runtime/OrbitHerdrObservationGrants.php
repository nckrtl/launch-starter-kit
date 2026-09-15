<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Support\NetworkOrigin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class OrbitHerdrObservationGrants
{
    public function issue(TaskTerminalTarget $target, int $columns, int $rows): string
    {
        $targetOrigin = NetworkOrigin::canonical($target->orbitHerdrObserverOrigin, 'wss');
        if ($target->orbitNodeId < 1
            || $target->orbitHerdrSessionId < 1
            || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $target->orbitHerdrSession) !== 1
            || $targetOrigin === null) {
            throw new OrbitHerdrObservationUnavailable('The task has no complete Orbit Herdr placement.');
        }
        $allowedOrigins = config('commander.orbit.herdr_observer_origins', []);
        if (! is_array($allowedOrigins)
            || ! collect($allowedOrigins)->contains(
                fn (mixed $origin): bool => ($canonical = NetworkOrigin::canonical($origin, 'wss')) !== null
                    && hash_equals($canonical, $targetOrigin),
            )) {
            throw new OrbitHerdrObservationUnavailable('The task Herdr observer origin is not allowed.');
        }

        $gateway = config('commander.orbit.url');
        $ca = config('commander.orbit.ca');
        $browserOrigin = $this->browserOrigin();
        $gatewayOrigin = NetworkOrigin::canonical($gateway, 'https');
        if ($gatewayOrigin === null || ! is_string($ca)) {
            throw new OrbitHerdrObservationUnavailable('The Orbit Gateway is not configured.');
        }

        try {
            $response = Http::baseUrl($gatewayOrigin)
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->withOptions(['verify' => $ca !== '' ? $ca : true, 'allow_redirects' => false])
                ->post('/api/v1/herdr/sessions/'.$target->orbitHerdrSessionId.'/observation-grants', [
                    'pane' => $target->paneId,
                    'terminal' => $target->terminalId,
                    'cols' => $columns,
                    'rows' => $rows,
                    'origin' => $browserOrigin,
                ])
                ->throw();
        } catch (ConnectionException|RequestException) {
            throw new OrbitHerdrObservationUnavailable('Orbit could not issue a Herdr observation grant.');
        }

        try {
            $data = $response->json('data');
        } catch (Throwable) {
            throw new OrbitHerdrObservationUnavailable('Orbit returned an invalid Herdr observation grant.');
        }

        if (! is_array($data)
            || ($data['scope'] ?? null) !== 'terminal.observe'
            || ($data['pane'] ?? null) !== $target->paneId
            || ($data['terminal'] ?? null) !== $target->terminalId
            || ($data['cols'] ?? null) !== $columns
            || ($data['rows'] ?? null) !== $rows
            || ! is_string($data['expires_at'] ?? null) || strlen($data['expires_at']) > 64
            || ($expiresAt = strtotime($data['expires_at'])) === false
            || $expiresAt <= time() || $expiresAt > time() + 120
            || ! is_string($data['nonce'] ?? null)
            || preg_match('/\A[a-z0-9]{1,128}\z/D', $data['nonce']) !== 1) {
            throw new OrbitHerdrObservationUnavailable('Orbit returned an invalid Herdr observation grant.');
        }

        $observerUrl = $data['observer_url'] ?? null;
        if (! is_string($observerUrl) || ! $this->validObserverUrl($observerUrl, $targetOrigin)) {
            throw new OrbitHerdrObservationUnavailable('Orbit returned an invalid Herdr observer URL.');
        }

        return $observerUrl;
    }

    private function browserOrigin(): string
    {
        $configured = config('app.url');
        $origin = NetworkOrigin::canonical($configured, 'https');
        if ($origin === null) {
            throw new OrbitHerdrObservationUnavailable('Commander has no safe HTTPS browser origin.');
        }

        return $origin;
    }

    private function validObserverUrl(#[SensitiveParameter] string $url, string $expectedOrigin): bool
    {
        if (strlen($url) > 5000) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'wss'
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $origin = NetworkOrigin::canonical(
            'wss://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'wss',
        );
        $expected = NetworkOrigin::canonical($expectedOrigin, 'wss');
        if ($origin === null || $expected === null || ! hash_equals($expected, $origin)) {
            return false;
        }

        $query = $parts['query'] ?? null;

        return (($parts['path'] ?? '') === '' || $parts['path'] === '/')
            && is_string($query)
            && preg_match('/\Aaccess_token=[A-Za-z0-9._~-]{1,4096}\z/D', $query) === 1;
    }
}
