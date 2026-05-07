<?php

namespace Acms\Plugins\DF_Like\Modules\Get\V2;

use Acms\Modules\Get\V2\Base;
use Acms\Plugins\DF_Like\Services\LikeRankingQuery;
use Field_Validation;

class DFLikeRanking extends Base
{
    /**
     * @var array<string,mixed>
     */
    private $rawContext = [];

    public function __construct(
        array $context,
        array $scopes,
        array $axis,
        Field_Validation $Post,
        int $cacheLifetime = 0,
        bool $customFieldsEnabled = false,
        ?int $mid = null,
        ?int $mbid = null,
        ?string $identifier = null
    ) {
        $this->rawContext = $context;
        parent::__construct($context, $scopes, $axis, $Post, $cacheLifetime, $customFieldsEnabled, $mid, $mbid, $identifier);
    }

    public function get(): array
    {
        $blogId = $this->bid ?: (defined('BID') ? (int)BID : 0);
        $rows = LikeRankingQuery::rows(
            $blogId,
            $this->rawContext['limit'] ?? $this->limit ?? 5,
            (string)($this->rawContext['period'] ?? 'all'),
            (string)($this->rawContext['start'] ?? $this->start ?? ''),
            (string)($this->rawContext['end'] ?? $this->end ?? '')
        );

        return [
            'items' => $rows,
            'amount' => count($rows),
            'notFound' => empty($rows),
        ];
    }
}
