<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Migrations extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'status',
        'source',
        'resources',
        'statusCounters',
        'resourceData',
        'errors'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('migrations', self::ALLOWED_ATTRIBUTES);
    }
}
