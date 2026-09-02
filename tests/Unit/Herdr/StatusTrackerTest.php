<?php

use App\Herdr\Debouncer;
use App\Herdr\StatusTracker;
use App\Herdr\Transition;

beforeEach(function () {
    $this->tracker = new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(0.0));
});

it('seeds the first status of a pane silently', function () {
    expect($this->tracker->observe('w1:p1', 'done', 0.0))->toBeNull()
        ->and($this->tracker->due(10.0))->toBe([]);
});

it('queues a transition into a notify status', function (string $status) {
    $this->tracker->observe('w1:p1', 'working', 0.0);

    $transition = $this->tracker->observe('w1:p1', $status, 1.0);

    expect($transition)->toBeInstanceOf(Transition::class)
        ->and($transition->paneId)->toBe('w1:p1')
        ->and($transition->from)->toBe('working')
        ->and($transition->to)->toBe($status)
        ->and($this->tracker->due(1.0))->toEqual([new Transition('w1:p1', 'working', $status)]);
})->with(['idle', 'done', 'blocked']);

it('ignores transitions into working and unknown', function (string $status) {
    $this->tracker->observe('w1:p1', 'idle', 0.0);

    $transition = $this->tracker->observe('w1:p1', $status, 1.0);

    expect($transition)->toEqual(new Transition('w1:p1', 'idle', $status))
        ->and($this->tracker->due(1.0))->toBe([]);
})->with(['working', 'unknown']);

it('ignores an unchanged status', function () {
    $this->tracker->observe('w1:p1', 'working', 0.0);
    $this->tracker->observe('w1:p1', 'idle', 1.0);
    $this->tracker->due(1.0);

    expect($this->tracker->observe('w1:p1', 'idle', 2.0))->toBeNull()
        ->and($this->tracker->due(2.0))->toBe([]);
});

it('cancels a pending notification when the pane goes back to work inside the debounce window', function () {
    $tracker = new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(5.0));
    $tracker->observe('w1:p1', 'working', 0.0);
    $tracker->observe('w1:p1', 'idle', 1.0);
    $tracker->observe('w1:p1', 'working', 3.0);

    expect($tracker->due(10.0))->toBe([]);
});

it('notifies a status that stays put for the debounce window exactly once', function () {
    $tracker = new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(5.0));
    $tracker->observe('w1:p1', 'working', 0.0);
    $tracker->observe('w1:p1', 'idle', 1.0);

    expect($tracker->due(5.9))->toBe([])
        ->and($tracker->due(6.0))->toEqual([new Transition('w1:p1', 'working', 'idle')])
        ->and($tracker->due(60.0))->toBe([]);
});

it('tracks panes independently', function () {
    $this->tracker->observe('w1:p1', 'working', 0.0);
    $this->tracker->observe('w1:p2', 'working', 0.0);
    $this->tracker->observe('w1:p1', 'done', 1.0);
    $this->tracker->observe('w1:p2', 'working', 1.0);

    expect($this->tracker->due(1.0))->toEqual([new Transition('w1:p1', 'working', 'done')])
        ->and($this->tracker->knownPanes())->toBe(['w1:p1', 'w1:p2']);
});

it('forgets a pane so its next status seeds silently', function () {
    $this->tracker->observe('w1:p1', 'working', 0.0);
    $this->tracker->forget('w1:p1');

    expect($this->tracker->knownPanes())->toBe([])
        ->and($this->tracker->observe('w1:p1', 'idle', 1.0))->toBeNull()
        ->and($this->tracker->due(1.0))->toBe([]);
});
