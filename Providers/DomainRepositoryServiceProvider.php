<?php

namespace Partners2016\Framework\Campaigns\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Partners2016\Framework\Campaigns\Services\DomainRepository\ClientFactory;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainRepositoryClientInterface;

class DomainRepositoryServiceProvider extends ServiceProvider implements DeferrableProvider
{

    public function register(): void
    {
        $this->app->bind(
            DomainRepositoryClientInterface::class,
            fn(): DomainRepositoryClientInterface => (new ClientFactory)->create(config('campaigns.domains.repository')),
        );
    }

    public function boot()
    {

    }


    public function provides(): array
    {
        return [DomainRepositoryClientInterface::class];
    }
}
