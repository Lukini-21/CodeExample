<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\ClientException;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\NotFoundException;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainRepositoryClientInterface;

/**
 * Gitlab client
 */
class GitlabClient implements DomainRepositoryClientInterface
{
    /**
     * Repository base url
     *
     * @var string
     */
    protected string $baseUrl;

    /**
     * Access token
     *
     * @var string
     */
    protected string $token;

    /**
     * Project ID
     *
     * @var string
     */
    protected string $projectId;

    /**
     * @var string
     */
    protected string $branch;

    public function __construct(string $repositoryName)
    {
        $gitLabConfig = config('campaigns.domains.repositories.' . $repositoryName);

        $baseUrl = rtrim($gitLabConfig['url'], '/');

        if (!($baseUrl && $gitLabConfig['token'] && $gitLabConfig['domain_project_id'])) {
            throw new \Exception('Set up domains config ENV variables!');
        }

        $this->baseUrl = $baseUrl;
        $this->token = $gitLabConfig['token'];
        $this->projectId = urlencode($gitLabConfig['domain_project_id']);
        $this->setBranch($gitLabConfig['branch']);
    }

    /**
     * Отправка запроса
     *
     * @param string $endpoint
     * @param string $method
     * @param array|null $data
     * @return array|false
     * @throws ClientException|NotFoundException
     */
    protected function sendRequest(string $endpoint, string $method, ?array $data = []): array|false
    {
        try {
            /** @var Response $response */
            $response = Http::withHeader('PRIVATE-TOKEN', $this->token)
                ->{strtolower($method)}($this->baseUrl . "/" . trim($endpoint, '/'), $data);

            if ($response->successful()) {
                return $response->json();
            } elseif ($response->status() === 404) {
                throw new NotFoundException();
            } else {
                Log::error('Gitlab client error', compact('response'));
                return false;
            }
        } catch (NotFoundException $exception) {
            throw $exception;
        } catch (\Exception) {
            throw new ClientException();
        }
    }

    /**
     * Создаёт коммит в репозитории
     *
     * @param string $commitMessage
     * @param array $actions
     * @return array|false
     * @throws ClientException
     * @throws NotFoundException
     */
    protected function createCommit(string $commitMessage, array $actions): array|false
    {
        return $this->sendRequest("/projects/{$this->projectId}/repository/commits", "post", [
            'branch' => $this->getBranch(),
            'commit_message' => $commitMessage,
            'actions' => $actions,
        ]);
    }

    /**
     * Возвращает коммит из репозитория
     *
     * @param string $commitId
     * @return array|false
     * @throws ClientException
     * @throws NotFoundException
     */
    private function getCommit(string $commitId): array|false
    {
        return $this->sendRequest("/projects/{$this->projectId}/repository/commits/{$commitId}", "get", [
            'ref_name' => $this->getBranch(),
        ]);
    }

    /**
     * Отдаёт контент файла
     *
     * @param string $filePath
     * @return string|null
     * @throws ClientException
     */
    public function getFileContent(string $filePath): string|null
    {
        $encodedFilePath = urlencode($filePath);

        try {
            $data = $this->sendRequest("/projects/{$this->projectId}/repository/files/{$encodedFilePath}", "get", [
                'ref' => $this->getBranch(),
            ]);

            return $data !== false ? base64_decode($data['content']) : null;
        } catch (NotFoundException) {
            return false;
        }
    }

    /**
     * Обновляет файл в репозитории
     *
     * @param string $filePath
     * @param string $content
     * @param string $message
     * @return string
     * @throws ClientException
     */
    public function updateFileContent(string $filePath, string $content, string $message): string
    {
        $commit = $this->createCommit($message, [
            [
                "action" => "update",
                "file_path" => $filePath,
                "content" => $content
            ]
        ]);

        return $commit['id'];
    }

    /**
     * Создаёт файл в репозитории
     *
     * @param string $filePath
     * @param string $fileContent
     * @param string $message
     * @param string $branch
     * @return string
     * @throws ClientException
     */
    public function createFile(string $filePath, string $fileContent, string $message = 'file created'): string
    {
        $commit = $this->createCommit($message, [
            [
                "action" => "create",
                "file_path" => $filePath,
                "content" => $fileContent
            ]
        ]);

        return $commit['id'];
    }

    /**
     * Возвращает статус последнего коммита
     *
     * @param string $transactionId
     * @return bool
     * @throws ClientException
     * @throws NotFoundException
     */
    public function isTransactionSuccess(string $transactionId): bool
    {
        return $this->isPipelineSuccess($transactionId);
    }

    /**
     * Возвращает статус последнего пайплайна
     *
     * @param string $commitId
     * @return bool
     * @throws ClientException
     * @throws NotFoundException
     */
    protected function isPipelineSuccess(string $commitId): bool
    {
        $commit = $this->getCommit($commitId);
        $pipeline = $commit['last_pipeline'];

        return $pipeline && $pipeline['status'] === 'success';
    }

    /**
     * @return string
     */
    public function getBranch(): string
    {
        return $this->branch;
    }

    /**
     * @param string $branch
     * @return $this
     */
    public function setBranch(string $branch): static
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Перезапускает последние коммит по commit ID
     * !!! Требуется роль Maintainer и выше, иначе 403 ошибка !!!
     *
     * @param string $commitId
     * @return bool
     * @throws ClientException
     */
    public function restartLastPipeline(string $commitId): bool
    {
        try {
            $pipelines = $this->getCommitPipelines($commitId);
            $lastPipeline = $pipelines[0] ?? throw new NotFoundException();
            // Если пайплайн прошёл успешно не нужно перезапускать
            if ($lastPipeline['status'] === 'success') {
                return true;
            }
            return $this->restartPipeLine($lastPipeline['id']) !== false;
        } catch (NotFoundException) {
            return false;
        }
    }

    /**
     * Возвращает пайплайны коммита
     *
     * @param string $commitId
     * @return array|false
     * @throws ClientException
     * @throws NotFoundException
     */
    private function getCommitPipelines(string $commitId): array|false
    {
        return $this->sendRequest("/projects/{$this->projectId}/pipelines", "get", [
            'sha' => $commitId
        ]);
    }

    /**
     * Перезапуск пайплайна по ID
     *
     * @param string $pipelineID
     * @return int|false
     * @throws ClientException
     * @throws NotFoundException
     */
    private function restartPipeLine(string $pipelineID): int|false
    {
        $response = $this->sendRequest("/projects/{$this->projectId}/pipelines/{$pipelineID}/retry", "post", [
            "ref" => $this->getBranch()
        ]);

        return $response['id'] ? (int)$response['id'] : false;
    }
}
