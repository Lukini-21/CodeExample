<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository;

use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\AlreadyAddedException;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\ClientException;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\NotExistsException;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainInterface;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainRepositoryClientInterface;

/**
 * Domain repository service
 */
class DomainRepositoryService
{
    /**
     * Delay before check commit pipeline
     */
    const CHECK_PIPELINE_DELAY = 2;

    /**
     * @param DomainRepositoryClientInterface $client
     * @param string $filePath
     */
    public function __construct(
        private readonly DomainRepositoryClientInterface $client
    )
    {
    }

    /**
     * Update domain file
     *
     * @param DomainRepositoryActions $action
     * @param DomainInterface $domain
     * @param string $message
     * @return string
     * @throws AlreadyAddedException
     * @throws NotExistsException
     */
    public function updateFile(DomainRepositoryActions $action, DomainInterface $domain, string $message): string
    {
        $filePath = "{$domain->configuration->ssl_server->value}.{$domain->type->value}-domains.txt";

        if (!$currentContent = $this->client->getFileContent($filePath)) {
            return $this->client->createFile($filePath, $domain->name, $message);
        }

        $lines = explode("\n", $currentContent);
        switch ($action) {
            case DomainRepositoryActions::Add:
                $lines = $this->addDomain($lines, $domain->name);
                break;
            case DomainRepositoryActions::Remove:
                $lines = $this->removeDomain($lines, $domain->name);
                break;
        }

        return $this->client->updateFileContent($filePath, implode("\n", $lines), $message);
    }

    /**
     * @param string $commitId
     * @return bool
     */
    public function isPipelineSuccess(string $commitId): bool
    {
        return $this->client->isTransactionSuccess($commitId);
    }

    /**
     * Add domain to array
     *
     * @param array $lines
     * @param string $domain
     * @return array
     * @throws AlreadyAddedException
     */
    private function addDomain(array $lines, string $domain): array
    {
        if (in_array($domain, $lines)) {
            throw new AlreadyAddedException();
        }
        $lines[] = $domain;

        return $lines;
    }

    /**
     * Remove domain from array
     *
     * @param array $lines
     * @param string $domain
     * @return array
     * @throws NotExistsException
     */
    private function removeDomain(array $lines, string $domain): array
    {
        if (!in_array($domain, $lines)) {
            throw new NotExistsException();
        }

        return array_filter($lines, fn($line) => trim($line) !== trim($domain));
    }

    /**
     * @param string $commitId
     * @return bool
     * @throws ClientException
     */
    public function restartPipeline(string $commitId): bool
    {
        return $this->client->restartLastPipeline($commitId);
    }
}
