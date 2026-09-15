<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\HomeController::class, 'show'])->name('HomeController.show');
Route::get('/agents', [\App\Http\Controllers\AgentController::class, 'index'])->name('AgentController.index');
Route::get('/conventions.md', [\App\Http\Controllers\AgentDocsController::class, 'conventions'])->name('AgentDocsController.conventions');
Route::get('/create.md', [\App\Http\Controllers\AgentDocsController::class, 'create'])->name('AgentDocsController.create');
Route::get('/herd.md', [\App\Http\Controllers\AgentDocsController::class, 'herd'])->name('AgentDocsController.herd');
Route::get('/herdr', [\App\Http\Controllers\HerdrController::class, 'index'])->name('HerdrController.index');
Route::get('/llms.txt', [\App\Http\Controllers\AgentDocsController::class, 'index'])->name('AgentDocsController.index');
Route::get('/orbit.md', [\App\Http\Controllers\AgentDocsController::class, 'orbit'])->name('AgentDocsController.orbit');
Route::get('/projects', [\App\Http\Controllers\ProjectController::class, 'index'])->name('ProjectController.index');
Route::post('/projects', [\App\Http\Controllers\ProjectController::class, 'store'])->name('ProjectController.store');
Route::get('/solo.md', [\App\Http\Controllers\AgentDocsController::class, 'solo'])->name('AgentDocsController.solo');
Route::get('/projects/{id}', [\App\Http\Controllers\ProjectController::class, 'show'])->name('ProjectController.show');
Route::put('/projects/{id}', [\App\Http\Controllers\ProjectController::class, 'update'])->name('ProjectController.update');
Route::get('/projects/{projectId}/tasks', [\App\Http\Controllers\TaskController::class, 'index'])->name('TaskController.index');
Route::post('/projects/{projectId}/tasks', [\App\Http\Controllers\TaskController::class, 'store'])->name('TaskController.store');
Route::get('/projects/{projectId}/tasks/{taskId}', [\App\Http\Controllers\TaskController::class, 'show'])->name('TaskController.show');
Route::put('/projects/{projectId}/tasks/{taskId}', [\App\Http\Controllers\TaskController::class, 'update'])->name('TaskController.update');
Route::put('/projects/{projectId}/tasks/{taskId}/order', [\App\Http\Controllers\TaskController::class, 'reorder'])->name('TaskController.reorder');
Route::post('/projects/{projectId}/tasks/{taskId}/terminal/{role}/observation-grant', [\App\Http\Controllers\TaskController::class, 'observationGrant'])->name('TaskController.observationGrant')->middleware('throttle:120,1');
