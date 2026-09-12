<?php

namespace App\Providers;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitAbandonedWorktreeCleaner;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Contracts\OrbitDeliveryLoopStarter;
use App\Delivery\Contracts\OrbitEligibleIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueCompletionTransitioner;
use App\Delivery\Contracts\OrbitIssueOwnershipClaimer;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueResolver;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitProofTopologyCloser;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Contracts\OrbitPullRequestReviewPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitResolutionPublisher;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\Contracts\OrbitStaleWorktreeRetirer;
use App\Delivery\Contracts\OrbitWorktreeCleaner;
use App\Delivery\IssueProviders\SshOrbitIssueOwnershipClaimer;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use App\Delivery\IssueProviders\SshOrbitIssueTransitioner;
use App\Delivery\IssueProviders\SshOrbitResolutionPublisher;
use App\Delivery\PullRequests\SshOrbitPullRequestLandingGateway;
use App\Delivery\PullRequests\SshOrbitPullRequestPublisher;
use App\Delivery\PullRequests\SshOrbitPullRequestReviewPublisher;
use App\Delivery\Repositories\ProcessOrbitDeliveryLoopStarter;
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
        $this->app->bind(OrbitStaleWorktreeRetirer::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitAbandonedWorktreeCleaner::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitImplementationRepository::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitMainCacheRefreshRequester::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitIssueResolver::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitActiveIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitCloseoutIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitEligibleIssueProvider::class, SshOrbitIssueProvider::class);
        $this->app->bind(OrbitIssueOwnershipClaimer::class, SshOrbitIssueOwnershipClaimer::class);
        $this->app->bind(OrbitDeliveryLoopStarter::class, ProcessOrbitDeliveryLoopStarter::class);
        $this->app->bind(OrbitIssueCompletionTransitioner::class, SshOrbitIssueTransitioner::class);
        $this->app->bind(OrbitIssueTransitioner::class, SshOrbitIssueTransitioner::class);
        $this->app->bind(OrbitMainCorrectnessInspector::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitMergeLineageVerifier::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitPrimaryCheckoutReconciler::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitProofTopologyCloser::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitWorktreeCleaner::class, ProcessOrbitRepository::class);
        $this->app->bind(OrbitReviewIssueTransitioner::class, SshOrbitIssueTransitioner::class);
        $this->app->bind(OrbitPullRequestInspector::class, SshOrbitPullRequestPublisher::class);
        $this->app->bind(OrbitPullRequestLandingGateway::class, SshOrbitPullRequestLandingGateway::class);
        $this->app->bind(OrbitPullRequestPublisher::class, SshOrbitPullRequestPublisher::class);
        $this->app->bind(OrbitPullRequestReviewPublisher::class, SshOrbitPullRequestReviewPublisher::class);
        $this->app->bind(OrbitResolutionPublisher::class, SshOrbitResolutionPublisher::class);

        $this->app->bind(HerdrRuntime::class, function (): SocketHerdrRuntime {
            $path = config('herdr.socket');

            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The Herdr socket is not configured.');
            }

            return new SocketHerdrRuntime(new SocketClient($path));
        });
        $this->app->bind(HerdrWorkspaceRuntime::class, function (): SocketHerdrRuntime {
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
