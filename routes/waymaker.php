<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentDocsController;
use App\Http\Controllers\HerdrController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('HomeController.show');
Route::get('/agents', [AgentController::class, 'index'])->name('AgentController.index');
Route::get('/conventions.md', [AgentDocsController::class, 'conventions'])->name('AgentDocsController.conventions');
Route::get('/create.md', [AgentDocsController::class, 'create'])->name('AgentDocsController.create');
Route::get('/herd.md', [AgentDocsController::class, 'herd'])->name('AgentDocsController.herd');
Route::get('/herdr', [HerdrController::class, 'index'])->name('HerdrController.index');
Route::get('/llms.txt', [AgentDocsController::class, 'index'])->name('AgentDocsController.index');
Route::get('/orbit.md', [AgentDocsController::class, 'orbit'])->name('AgentDocsController.orbit');
Route::get('/projects', [ProjectController::class, 'index'])->name('ProjectController.index');
Route::post('/projects', [ProjectController::class, 'store'])->name('ProjectController.store');
Route::get('/solo.md', [AgentDocsController::class, 'solo'])->name('AgentDocsController.solo');
Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('ProjectController.show');
Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('ProjectController.update');
