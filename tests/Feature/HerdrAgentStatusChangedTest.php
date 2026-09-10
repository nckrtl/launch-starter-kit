<?php

use App\Models\HerdrEvent;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\HerdrAgentStatusChanged;
use Illuminate\Notifications\AnonymousNotifiable;

it('posts a done transition as idle and keeps the raw status on the event', function () {
    $event = new HerdrEvent([
        'occurred_at' => now(),
        'workspace_id' => 'w7',
        'workspace_label' => 'ORB-15',
        'pane_id' => 'w7:p1',
        'agent' => 'codex',
        'agent_name' => 'orb15-impl',
        'from_status' => 'working',
        'to_status' => 'done',
    ]);

    $message = (new HerdrAgentStatusChanged($event))->toSlack(new AnonymousNotifiable);

    expect($message->text)->toBe('Herdr: orb15-impl just went idle')
        ->and($message->toArray())->toBe(['text' => 'Herdr: orb15-impl just went idle'])
        ->and($event->to_status)->toBe('done');
});

it('posts a status without a label verbatim', function () {
    $event = new HerdrEvent([
        'occurred_at' => now(),
        'workspace_id' => 'w7',
        'workspace_label' => 'ORB-15',
        'pane_id' => 'w7:p1',
        'agent' => 'codex',
        'agent_name' => 'orb15-impl',
        'from_status' => 'working',
        'to_status' => 'idle',
    ]);

    expect((new HerdrAgentStatusChanged($event))->toSlack(new AnonymousNotifiable)->text)
        ->toBe('Herdr: orb15-impl just went idle');
});

it('falls back to the pane id', function () {
    $event = new HerdrEvent([
        'occurred_at' => now(),
        'workspace_id' => 'w7',
        'workspace_label' => null,
        'pane_id' => 'w7:p1',
        'agent' => null,
        'agent_name' => null,
        'from_status' => 'working',
        'to_status' => 'blocked',
    ]);

    expect((new HerdrAgentStatusChanged($event))->toSlack(new AnonymousNotifiable)->text)
        ->toBe('Herdr: w7:p1 just went blocked');
});

it('is delivered through the Slack channel', function () {
    $event = new HerdrEvent(['occurred_at' => now(), 'workspace_id' => 'w7', 'pane_id' => 'w7:p1', 'to_status' => 'idle']);

    expect((new HerdrAgentStatusChanged($event))->via(new AnonymousNotifiable))->toBe([SlackChannel::class]);
});
