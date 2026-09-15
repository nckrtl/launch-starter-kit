<?php

declare(strict_types=1);

namespace App\Providers;

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use App\Tasks\Closeout\NativeTaskCloseoutGitHub;
use App\Tasks\Closeout\NativeTaskCloseoutRepository;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\Completion\NativeTaskCompletionIssue;
use App\Tasks\Completion\TaskCompletionIssue;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\NativeTaskLandingRepository;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Landing\TaskLandingReviewer;
use App\Tasks\Preparation\NativeOrbitTaskWorktreePreparation;
use App\Tasks\Preparation\TaskWorktreePreparation;
use App\Tasks\Runtime\HerdrTaskAgents;
use App\Tasks\Runtime\HerdrTaskSessionObserver;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskSessionObserver;
use Illuminate\Support\ServiceProvider;

final class TaskRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaskWorktreePreparation::class, NativeOrbitTaskWorktreePreparation::class);
        $this->app->bind(OrbitIssueReader::class, SshOrbitIssueProvider::class);
        $this->app->bind(TaskCloseoutGitHub::class, NativeTaskCloseoutGitHub::class);
        $this->app->bind(TaskCloseoutRepository::class, NativeTaskCloseoutRepository::class);
        $this->app->bind(TaskCompletionIssue::class, NativeTaskCompletionIssue::class);
        $this->app->bind(TaskLandingRepository::class, NativeTaskLandingRepository::class);
        $this->app->bind(TaskLandingReviewer::class, HerdrTaskLandingReviewer::class);
        $this->app->bind(TaskAgents::class, HerdrTaskAgents::class);
        $this->app->bind(TaskSessionObserver::class, HerdrTaskSessionObserver::class);
        config()->set('queue.connections.task-runtime', [
            'driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'tasks',
            'retry_after' => 3800, 'after_commit' => true,
        ]);
    }
}
