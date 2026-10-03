<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDocsController;
use App\Http\Controllers\HerdrController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('home');

Route::get('/llms.txt', [AgentDocsController::class, 'index'])->name('docs.index');
Route::get('/create.md', [AgentDocsController::class, 'create'])->name('docs.create');
Route::get('/conventions.md', [AgentDocsController::class, 'conventions'])->name('docs.conventions');
Route::get('/herd.md', [AgentDocsController::class, 'herd'])->name('docs.herd');
Route::get('/orbit.md', [AgentDocsController::class, 'orbit'])->name('docs.orbit');
Route::get('/solo.md', [AgentDocsController::class, 'solo'])->name('docs.solo');

Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
Route::get('/herdr', [HerdrController::class, 'index'])->name('herdr.index');

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');

Route::get('/projects/{projectId}/tasks', [TaskController::class, 'index'])->name('projects.tasks.index');
Route::post('/projects/{projectId}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
Route::get('/projects/{projectId}/tasks/{taskId}', [TaskController::class, 'show'])->name('projects.tasks.show');
Route::put('/projects/{projectId}/tasks/{taskId}', [TaskController::class, 'update'])->name('projects.tasks.update');
Route::put('/projects/{projectId}/tasks/{taskId}/order', [TaskController::class, 'reorder'])->name('projects.tasks.reorder');
Route::post('/projects/{projectId}/tasks/{taskId}/terminal/{role}/observation-grant', [TaskController::class, 'observationGrant'])
    ->middleware('throttle:120,1')
    ->name('projects.tasks.observation-grant');
