<?php

namespace App\Http\Controllers\Manager\VueApi\Tools\WwwDomains;

use App\CampaignVertical;
use App\Domain;
use App\EntityLogger;
use App\Exports\Campaigns\DomainsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\VueApi\Tools\WwwDomains\DomainBuyRequest;
use App\Http\Requests\VueApi\Tools\WwwDomains\DomainStoreRequest;
use App\Http\Resources\WwwDomains\DomainResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use Partners2016\Framework\Campaigns\DomainConfiguration;
use Partners2016\Framework\Campaigns\Jobs\CampaignDomains\GenerateDomainsJob;
use Partners2016\Framework\Campaigns\Services\CampaignDomainSSLService;
use Partners2016\Framework\Campaigns\Services\DomainValidator\DomainValidator;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainStatus;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainType;
use Partners2016\Framework\Contracts\Campaigns\Domains\Log\DomainLogAction;
use Partners2016\Framework\Emails\Mail\Manager\DomainBuyMail;
use Partners2016\Framework\EntityLogger\ChangeLog\DomainChangeLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Общий контроллер списка доменов
 */
class DomainController extends Controller
{
    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResource
     */
    public function index(Request $request)
    {
        $query = QueryBuilder::for($this->getDomainQuery())
            ->with([
                'campaignVertical', 'configuration', 'configuration.countries',
            ])
            ->allowedFilters([
                AllowedFilter::exact('type'),
                AllowedFilter::partial('search', 'name'),
                AllowedFilter::exact('vertical_id', 'vertical_id'),
                AllowedFilter::exact('id'),
                AllowedFilter::exact('countries', 'configuration.countries.id'),
                AllowedFilter::exact('status', 'status_id'),
            ])
            ->defaultSort('-created_at')
            ->allowedSorts($this->getSorts());

        if ($request->has('export')) {
            return Excel::download(new DomainsExport($query), 'domains.xlsx');
        }

        return DomainResource::collection($query->paginate(50));
    }

    /**
     * Сортировки
     *
     * @return array
     */
    private function getSorts(): array
    {
        return [
            'id', 'name', 'created_at', 'updated_at', 'expires_at', 'type',
            $this->getVerticalSort(),
            $this->getUserSort(),
            $this->getCountriesSort(),
            AllowedSort::field('status', 'status_id'),
            AllowedSort::field('traffic', 'traffic_last_60d'),
        ];
    }

    /**
     * Сортировка по вертикали кампании
     *
     * @return AllowedSort
     */
    private function getVerticalSort(): AllowedSort
    {
        return AllowedSort::callback('vertical_name', function ($query, $direction) {
            $query->leftJoin('campaign_verticals', 'domains.vertical_id', '=', 'campaign_verticals.id')
                ->orderBy('campaign_verticals.name', $direction ? 'ASC' : 'DESC')
                ->select('domains.*');
        });
    }

    /**
     * Сортировка по странам
     *
     * @return AllowedSort
     */
    private function getCountriesSort(): AllowedSort
    {
        return AllowedSort::callback('countries', function ($query, $direction) {
            $query->leftJoin('domain_configuration_countries as dcc', 'domains.configuration_id', '=', 'dcc.configuration_id')
                ->leftJoin('countries as c', 'c.id', '=', 'dcc.country_id')
                ->orderBy('c.name', $direction ? 'ASC' : 'DESC')
                ->select('domains.*');
        });
    }

    /**
     * Сортировка по афилейту
     *
     * @return AllowedSort
     */
    private function getUserSort(): AllowedSort
    {
        return AllowedSort::callback('user_id', function ($query, $direction) {
            $query->leftJoin('users', 'users.id', '=', 'domains.user_id')
                ->orderByRaw('users.email ' . ($direction ? 'ASC' : 'DESC'))
                ->select('domains.*');
        });
    }

    /**
     * @param DomainStoreRequest $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function store(DomainStoreRequest $request): JsonResponse
    {
        /** @var Domain $domain */
        $domain = new Domain();
        $domain->type = $request->enum('type', DomainType::class);
        $domain->fill($request->only([
            'name',
            'vertical_id',
            'auto_renewal',
            'disable_on_virus',
            'no_traffic_release',
            'configuration_id',
            'user_id',
            'comment'
        ]));

        $domainValidator = new DomainValidator();
        $domain->status_id = $domainValidator->isDomainAvailable($domain) ?
            DomainStatus::Available :
            DomainStatus::RegistrationPending;

        if (!empty($request->user_id)) {
            $domain->assigned_at = now();
        }

        $domain->save();

        $logger = new EntityLogger(new DomainChangeLog());
        $this->log($logger, $domain, DomainLogAction::Create);

        (new CampaignDomainSSLService)->addSsl($domain->id);

        if (!$request->input('already_purchased')) {
            $this->sendBuyDomainEmail($domain);
        }

        return response()->json([], 204);
    }

    /**
     * @param $value
     * @return JsonResponse
     */
    public function show($value): JsonResponse
    {
        if ($value === 'settings') {
            return response()->json([
                'configurations' => DomainConfiguration::all()->toArray(),
                'verticals' => CampaignVertical::pluck('name', 'id')->toArray(),
            ]);
        }
        $domain = $this->getDomainQuery()
            ->with(['webmaster', 'virustotalUrlReport', 'campaignVertical'])
            ->findOrFail($value);

        return (new DomainResource($domain))->response();
    }

    /**
     * @param DomainStoreRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(DomainStoreRequest $request, int $id): JsonResponse
    {
        /** @var Domain $domain */
        $domain = $this->getDomainQuery()->findOrFail($id);
        if (empty($domain->user_id) && !empty($request->user_id)) {
            $domain->assigned_at = now();
        }
        $logger = new EntityLogger(new DomainChangeLog());
        $this->logOldValues($logger, $domain);
        $domain->fill($request->only(['user_id', 'vertical_id', 'auto_renewal', 'disable_on_virus', 'no_traffic_release', 'comment']));
        $status = $request->enum('status', DomainStatus::class);

        if ($status->isAllowedManualChange($domain->status_id, $status)) {
            if ($domain->status_id !== $status) {
                $domain->status_id = $status;
            }
        }

        $domain->save();
        $this->log($logger, $domain, DomainLogAction::Update);

        return (new DomainResource($domain))->response();
    }

    /**
     * @param DomainBuyRequest $request
     * @return void
     */
    public function buyDomain(DomainBuyRequest $request)
    {
        GenerateDomainsJob::dispatch(
            configurations: $request->validated('configurations'),
            userId: $request->user()->id
        );
    }

    /**
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        /** @var Domain $domain */
        $domain = $this->getDomainQuery()->findOrFail($id);

        if ($domain->status_id <> DomainStatus::Disabled) {
            $msg = 'Only disabled domains can be deleted';
            return new JsonResponse(['message' => $msg, 'errors' => ['domain' => [$msg]]], 422);
        }

        $domain->delete();

        return new JsonResponse([], 204);
    }

    /**
     * @return Builder
     */
    private function getDomainQuery(): Builder
    {
        return Domain::query()->withoutGlobalScope('filter_by_type');
    }

    /**
     * Логирование изменений
     *
     * @param EntityLogger $logger
     * @param Domain $domain
     * @param DomainLogAction $action
     * @return void
     */
    private function log(EntityLogger $logger, Domain $domain, DomainLogAction $action): void
    {
        $logger->write($domain->id, $action->value, $logger->getCurrentValue($domain));
    }

    /**
     * Логирование старых значений
     *
     * @param EntityLogger $logger
     * @param Domain $domain
     */
    private function logOldValues(EntityLogger $logger, Domain $domain): void
    {
        $logger->setOld($logger->getCurrentValue($domain), $domain->id);
    }

    /**
     * Отправка письма о покупке
     *
     * @param Domain $domain
     * @return void
     * @throws \Exception
     */
    private function sendBuyDomainEmail(Domain $domain): void
    {
        try {
            $recipients = array_filter(explode(',', config('campaigns.domains.management-email-recipients')));
            $ccRecipients = array_filter(explode(',', config('campaigns.domains.management-email-recipients-cc')));

            if (empty($recipients)) {
                throw new \Exception('Recipients cannot be empty');
            }
            $recipients = array_map('trim', $recipients);
            $ccRecipients = array_map('trim', $ccRecipients);

            Mail::to($recipients)
                ->cc($ccRecipients)
                ->queue(new DomainBuyMail([
                    $domain->type->value => [
                        [
                            'name' => $domain->name,
                            'cname' => $domain->configuration->cname,
                            'alter_cname' => $domain->configuration->alter_cname,
                            'type' => $domain->type->value,
                            'config' => $domain->configuration->name,
                        ]
                    ]
                ]));
        } catch (\Exception $e) {
            \Log::error($e->getMessage(), [
                'method' => __METHOD__,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Скачать ответ whois сервиса
     *
     * @param int $id
     * @return \Illuminate\Http\Response|RedirectResponse
     */
    public function downloadWhoisStatus(int $id): \Illuminate\Http\Response| RedirectResponse
    {
        $domain = $this->getDomainQuery()->findOrFail($id);
        $content = $domain->whois_data;
        if (!$content) {
            return \response(null, 404);
        }

        return Response::make($content['body'], 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="status.txt"',
        ]);
    }
}
