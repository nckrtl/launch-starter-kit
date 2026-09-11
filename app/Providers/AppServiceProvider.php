<?php

namespace App\Providers;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use App\Delivery\IssueProviders\SshOrbitIssueTransitioner;
use App\Delivery\PullRequests\SshOrbitPullRequestPublisher;
use App\Delivery\Repositories\ProcessOrbitRepository;
use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(OrbitRepository::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitImplementationRepository::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitActiveIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitIssueTransitioner::class, SshOrbitIssueTransitioner::class);
        $this->app->bind(OrbitReviewIssueTransitioner::class, SshOrbitIssueTransitioner::class);
        $this->app->bind(OrbitPullRequestInspector::class, SshOrbitPullRequestPublisher::class);
        $this->app->bind(OrbitPullRequestPublisher::class, SshOrbitPullRequestPublisher::class);

        $this->app->bind(HerdrRuntime::class, function (): SocketHerdrRuntime {
            $path = config('herdr.socket');

            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The Herdr socket is not configured.');
            }

            return new SocketHerdrRuntime(new SocketClient($path));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes();
        Model::unguard();

        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/testing/vite.hot'));
        }

        if ($this->app->isProduction()) {
            Vite::prefetch();
        }
    }
}
