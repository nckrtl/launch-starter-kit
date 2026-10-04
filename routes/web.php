<?php

declare(strict_types=1);

use App\Http\Controllers\AgentDocsController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('home');

Route::get('/llms.txt', [AgentDocsController::class, 'index'])->name('docs.index');
Route::get('/create.md', [AgentDocsController::class, 'create'])->name('docs.create');
Route::get('/conventions.md', [AgentDocsController::class, 'conventions'])->name('docs.conventions');
Route::get('/herd.md', [AgentDocsController::class, 'herd'])->name('docs.herd');
Route::get('/orbit.md', [AgentDocsController::class, 'orbit'])->name('docs.orbit');
Route::get('/solo.md', [AgentDocsController::class, 'solo'])->name('docs.solo');
