<?php

/**
 * Evaluate config/herdr.php with HERDR_NOTIFY_STATUSES set to the given value; null leaves it unset.
 *
 * @return array<string, mixed>
 */
function herdrConfigWithNotifyStatuses(?string $value): array
{
    $key = 'HERDR_NOTIFY_STATUSES';
    $saved = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require base_path('config/herdr.php');
    } finally {
        if ($saved['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $saved['env'];
        }

        if ($saved['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $saved['server'];
        }

        if ($saved['putenv'] === false) {
            putenv($key);
        } else {
            putenv("{$key}={$saved['putenv']}");
        }
    }
}

it('notifies idle, done, and blocked by default', function () {
    expect(herdrConfigWithNotifyStatuses(null)['notify_statuses'])->toBe(['idle', 'done', 'blocked']);
});

it('reads HERDR_NOTIFY_STATUSES as a comma-separated list, trimmed, without empty entries', function () {
    expect(herdrConfigWithNotifyStatuses(' idle, done ,,blocked, ')['notify_statuses'])->toBe(['idle', 'done', 'blocked']);
});

it('narrows the notified statuses to what HERDR_NOTIFY_STATUSES lists', function () {
    expect(herdrConfigWithNotifyStatuses('idle,done')['notify_statuses'])->toBe(['idle', 'done']);
});

it('labels a done transition as idle', function () {
    expect(config('herdr.status_labels'))->toBe(['done' => 'idle']);
});
