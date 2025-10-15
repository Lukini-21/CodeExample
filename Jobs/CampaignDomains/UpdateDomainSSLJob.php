<?php

namespace Partners2016\Framework\Campaigns\Jobs\CampaignDomains;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Partners2016\Framework\Campaigns\Domain;
use Partners2016\Framework\Campaigns\Services\DomainRepository\DomainRepositoryActions;
use Partners2016\Framework\Campaigns\Services\DomainRepository\DomainRepositoryService;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\AlreadyAddedException;
use Partners2016\Framework\Campaigns\Services\DomainRepository\Exceptions\NotExistsException;
use Partners2016\Framework\Contracts\Campaigns\Domains\Log\DomainLogAction;
use Partners2016\Framework\EntityLogger\ChangeLog\DomainChangeLog;
use Partners2016\Framework\EntityLogger\EntityLogger;

/**
 * Update domain list is GIT repo
 */
class UpdateDomainSSLJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public int $tries = 3;

    /**
     * @var int
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly DomainRepositoryActions $action, private readonly int $domainId)
    {
        if ($queue = env('APP_QUEUE_BACKGROUND_TASKS')) {
            $this->onQueue($queue);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(DomainRepositoryService $service): void
    {
        /** @var Domain $domain */
        $domain = Domain::query()->withoutGlobalScopes()->withTrashed()->findOrFail($this->domainId);
        $server = $domain->configuration->ssl_server;
        if (empty($server)) {
            return;
        }
        $logger = new EntityLogger(new DomainChangeLog());
        $logger->setOld($logger->getCurrentValue($domain), $this->domainId);

        try {
            $message = "{$this->action->value} $domain->name {$domain->type->value} domain {$server->value}";
            $commitId = $service->updateFile($this->action, $domain, $message);
            if ($this->action === DomainRepositoryActions::Add) {
                $this->runCheckDomainSSLJob($commitId, $domain->id);
                $domain->commit_id = $commitId;
            } else {
                $domain->ssl = 0;
                $domain->commit_id = null;
            }
            $domain->save();
        } catch (AlreadyAddedException) {
            // Domain already present
            if (!$domain->ssl && $domain->commit_id) {
                // Recheck if no SSL
               $this->runCheckDomainSSLJob($domain->commit_id, $domain->id);
            }
        } catch (NotExistsException) {
            // Domain not present in file when delete
            $domain->ssl = 0;
            $domain->save();
        } catch (\Throwable $exception) {
            report($exception);
        } finally {
            $logger->write($this->domainId, DomainLogAction::Update->value, $domain->toArray());
        }
    }

    /**
     * Run Job
     *
     * @param string $commitId
     * @param string $domainId
     * @return void
     */
    private function runCheckDomainSSLJob(string $commitId, string $domainId): void
    {
        CheckDomainSSLJob::dispatch($commitId, $domainId)->delay(now()
            ->addMinutes(DomainRepositoryService::CHECK_PIPELINE_DELAY));
    }
}
