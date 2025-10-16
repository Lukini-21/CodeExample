<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository;

use Partners2016\Framework\Campaigns\Services\DomainRepository\Clients\GitlabClient;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainRepositoryClientInterface;

/**
 * GIT repository client factory
 */
class ClientFactory
{
    /**
     * Factory method
     *
     * @param string $repositoryName
     * @return DomainRepositoryClientInterface
     * @throws \Exception
     */
    public function create(string $repositoryName): DomainRepositoryClientInterface
    {
        return match ($repositoryName) {
            'gitlab' => new GitlabClient($repositoryName),
            default => new \Exception("Repository name '$repositoryName' not found")
        };
    }
}
