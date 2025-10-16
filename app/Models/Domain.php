<?php

namespace App;

use App\Models\Scopes\DomainsByManagerWebmasters;
use Partners2016\Framework\Campaigns\DomainConfiguration;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainStatus;
use Partners2016\Framework\Contracts\Campaigns\Domains\DomainType;


/**
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property DomainStatus $status_id
 * @property int $vertical_id
 * @property DomainConfiguration $configuration
 * @property int $traffic_prior_59d
 * @property int $traffic_today
 * @property int $traffic_last_60d
 */
class Domain extends \Partners2016\Framework\Campaigns\Domain
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'name',
        'status_id',
        'vertical_id',
        'type',
        'disable_on_virus',
        'no_traffic_release',
        'auto_renewal',
        'configuration_id',
        'comment',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
        'status_id' => DomainStatus::class,
        'type' => DomainType::class,
        'whois_data' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('role_access', new DomainsByManagerWebmasters());
    }
}
