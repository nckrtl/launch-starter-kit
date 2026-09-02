<?php

use App\Herdr\Debouncer;
use App\Herdr\Transition;

it('releases a transition once the window has passed', function () {
    $debouncer = new Debouncer(5.0);
    $transition = new Transition('w1:p1', 'working', 'idle');

    $debouncer->push($transition, 10.0);

    expect($debouncer->due(14.9))->toBe([])
        ->and($debouncer->due(15.0))->toBe([$transition])
        ->and($debouncer->due(100.0))->toBe([]);
});

it('releases immediately with a zero window', function () {
    $debouncer = new Debouncer(0.0);
    $transition = new Transition('w1:p1', 'working', 'done');

    $debouncer->push($transition, 10.0);

    expect($debouncer->due(10.0))->toBe([$transition]);
});

it('keeps only the latest transition per pane and restarts its window', function () {
    $debouncer = new Debouncer(5.0);
    $first = new Transition('w1:p1', 'working', 'idle');
    $second = new Transition('w1:p1', 'idle', 'done');

    $debouncer->push($first, 0.0);
    $debouncer->push($second, 3.0);

    expect($debouncer->due(5.0))->toBe([])
        ->and($debouncer->due(8.0))->toBe([$second]);
});

it('drops a cancelled pane', function () {
    $debouncer = new Debouncer(5.0);
    $debouncer->push(new Transition('w1:p1', 'working', 'idle'), 0.0);

    $debouncer->cancel('w1:p1');

    expect($debouncer->due(100.0))->toBe([]);
});

it('releases panes independently', function () {
    $debouncer = new Debouncer(5.0);
    $early = new Transition('w1:p1', 'working', 'idle');
    $late = new Transition('w1:p2', 'working', 'blocked');

    $debouncer->push($early, 0.0);
    $debouncer->push($late, 2.0);

    expect($debouncer->due(5.0))->toBe([$early])
        ->and($debouncer->due(7.0))->toBe([$late]);
});
