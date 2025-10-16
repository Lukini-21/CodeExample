<?php

namespace Partners2016\Framework\Campaigns\Services\DomainRepository;

enum DomainRepositoryActions: string
{
    case Add = 'add';
    case Remove = 'remove';
}