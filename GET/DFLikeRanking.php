<?php

namespace Acms\Plugins\DF_Like\GET;

use ACMS_Corrector;
use ACMS_GET;
use Acms\Plugins\DF_Like\Services\LikeRankingQuery;
use Template;

class DFLikeRanking extends ACMS_GET
{
    /**
     * @var array<string,string>
     */
    private $rawArgs = [];

    /**
     * @var array<string,string>
     */
    private $contextArgs = [];

    /**
     * @var array<string,string>
     */
    private $optionArgs = [];

    /**
     * @var array<string,string>
     */
    private $templateArgs = [];

    public function __construct(
        $tpl,
        $acms,
        $scope,
        $axis,
        $Post,
        $mid = null,
        $mbid = null,
        $identifier = null,
        $aryMultiAcms = null,
        $showField = false
    ) {
        $this->mergeContextArgs((string)$acms);
        foreach (['ctx', 'context', 'acms'] as $scopeKey) {
            if (is_array($scope) && isset($scope[$scopeKey])) {
                $this->mergeContextArgs((string)$scope[$scopeKey]);
            }
        }
        if (is_array($aryMultiAcms)) {
            foreach ($aryMultiAcms as $key => $value) {
                $key = (string)$key;
                $value = trim((string)$value);
                if ($key === 'ctx' || $key === 'context') {
                    $this->mergeContextArgs($value);
                    continue;
                }
                $this->rawArgs[$key] = $value;
            }
        }
        parent::__construct($tpl, $acms, $scope, $axis, $Post, $mid, $mbid, $identifier, $aryMultiAcms, $showField);

        foreach (['ctx', 'context'] as $contextKey) {
            if (isset($this->{$contextKey})) {
                $this->mergeContextArgs((string)$this->{$contextKey});
            }
            if ($this->Q) {
                $this->mergeContextArgs((string)$this->Q->get($contextKey));
            }
            if ($this->Get) {
                $this->mergeContextArgs((string)$this->Get->get($contextKey));
            }
        }
        $this->optionArgs = $this->parseOptionArgs((string)$this->order);
    }

    public function get()
    {
        $blogId = defined('BID') ? (int)BID : 0;
        $template = $this->template();
        $this->templateArgs = $this->extractTemplateArgs($template);
        $rows = LikeRankingQuery::rows($blogId, $this->arg('limit'), $this->arg('period'), $this->arg('start'), $this->arg('end'));
        $Tpl = new Template($template, new ACMS_Corrector());

        $vars = [
            'ranking' => $rows,
            'amount' => count($rows),
        ];
        if (!$rows) {
            $vars['notFound'] = (object)[];
        }

        return $Tpl->render($vars);
    }

    private function arg(string $key): string
    {
        if (isset($this->templateArgs[$key]) && $this->templateArgs[$key] !== '') {
            return $this->templateArgs[$key];
        }
        if (isset($this->rawArgs[$key]) && $this->rawArgs[$key] !== '') {
            return $this->rawArgs[$key];
        }
        if (isset($this->contextArgs[$key]) && $this->contextArgs[$key] !== '') {
            return $this->contextArgs[$key];
        }
        if (isset($this->optionArgs[$key]) && $this->optionArgs[$key] !== '') {
            return $this->optionArgs[$key];
        }
        if (isset($this->{$key})) {
            $value = trim((string)$this->{$key});
            if ($value !== '') {
                return $value;
            }
        }
        if ($this->Q) {
            $value = trim((string)$this->Q->get($key));
            if ($value !== '') {
                return $value;
            }
        }
        if ($this->Field) {
            $value = trim((string)$this->Field->get($key));
            if ($value !== '') {
                return $value;
            }
        }
        if ($this->Get) {
            $value = trim((string)$this->Get->get($key));
            if ($value !== '') {
                return $value;
            }
        }
        if (isset($_GET[$key])) {
            return trim((string)$_GET[$key]);
        }

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function parseContextArgs(string $context): array
    {
        $parts = array_values(array_filter(explode('/', trim($context, '/')), static function ($part) {
            return $part !== '';
        }));
        $args = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $key = trim((string)$parts[$i]);
            if ($key === '' || !isset($parts[$i + 1])) {
                continue;
            }
            $args[$key] = trim((string)$parts[$i + 1]);
        }
        return $args;
    }

    private function mergeContextArgs(string $context): void
    {
        foreach ($this->parseContextArgs($context) as $key => $value) {
            if (!isset($this->contextArgs[$key]) || $this->contextArgs[$key] === '') {
                $this->contextArgs[$key] = $value;
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function parseOptionArgs(string $options): array
    {
        $args = [];
        foreach (preg_split('/[;,]/', trim($options)) ?: [] as $part) {
            if (strpos($part, ':') === false && strpos($part, '=') === false) {
                continue;
            }
            $separator = strpos($part, '=') !== false ? '=' : ':';
            [$key, $value] = array_map('trim', explode($separator, $part, 2));
            if ($key !== '' && $value !== '') {
                $args[$key] = $value;
            }
        }
        return $args;
    }

    /**
     * @return array<string,string>
     */
    private function extractTemplateArgs(string &$template): array
    {
        $args = [];
        $template = preg_replace_callback('/<!--\s*DFLikeRanking\s*:\s*(.*?)\s*-->/s', function ($matches) use (&$args) {
            foreach ($this->parseOptionArgs((string)$matches[1]) as $key => $value) {
                $args[$key] = $value;
            }
            return '';
        }, $template);

        return $args;
    }

    private function template(): string
    {
        $tpl = trim((string)$this->tpl);
        if ($tpl !== '') {
            return $tpl;
        }

        return '<!-- BEGIN ranking:loop -->'
            . '<p><span>{rank}</span> <a href="{entry_url}">{entry_title}</a> <span>{like_count}</span></p>'
            . '<!-- END ranking:loop -->'
            . '<!-- BEGIN notFound --><p>いいねされた記事はまだありません。</p><!-- END notFound -->';
    }
}
