<?php

// Isolate DB before any Laravel boot (Process/.env can leak DB_DATABASE).
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_URL=');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['DB_URL'] = '';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
$_SERVER['DB_URL'] = '';

use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        config(['inertia.ssr.enabled' => false]);

        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Browser');

expect()->extend('toBeOne', fn () => $this->toBe(1));
