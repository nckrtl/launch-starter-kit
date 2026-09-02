<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test-token',
        'herdr.slack_channel' => 'C0TESTCHAN',
    ]);
});

it('posts the bridge-online line to the configured channel', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(0)
        ->and(Artisan::output())->toContain('ok: true');

    Http::assertSent(fn (Request $request): bool => $request['channel'] === 'C0TESTCHAN'
        && $request['text'] === 'Commander: Herdr bridge online');
});

it('reports a Slack failure', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found'])]);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(1)
        ->and(Artisan::output())->toContain('ok: false')->toContain('channel_not_found');
});

it('refuses to post without a channel', function () {
    config(['herdr.slack_channel' => null]);
    Http::fake();

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(1);

    Http::assertNothingSent();
});
