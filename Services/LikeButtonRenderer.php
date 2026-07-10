<?php

namespace Acms\Plugins\DF_Like\Services;

use ACMS_Corrector;
use Template;

class LikeButtonRenderer
{
    public static function renderDefault(int $entryId, int $blogId, string $align = 'left', string $source = 'auto'): string
    {
        return self::render($entryId, $blogId, self::defaultTemplate(), $align, $source);
    }

    public static function render(int $entryId, int $blogId, string $template, string $align = 'left', string $source = 'auto'): string
    {
        if ($entryId <= 0) {
            return '';
        }
        $blogId = LikeBlogContext::entryBlogId($entryId, $blogId);
        if (!LikeBlogContext::isAppEnabled($blogId)) {
            return '';
        }

        $visitorId = LikeRepository::issueVisitorId();
        $visitorHash = hash('sha256', $visitorId);
        $objectType = 'entry';
        $objectId = (string)$entryId;
        $count = LikeRepository::count($objectType, $objectId);
        $liked = LikeRepository::liked($objectType, $objectId, $visitorHash);
        $labelLike = self::escape(self::configValue('df_like_label_like', 'いいね', $blogId));
        $labelLiked = self::escape(self::configValue('df_like_label_liked', 'いいね済み', $blogId));
        $icon = self::icon(self::configValue('df_like_icon', 'heart', $blogId));
        $countVisible = self::choice(self::configValue('df_like_count_display', 'show', $blogId), ['show', 'hide'], 'show') === 'show';
        $color = self::color(self::configValue('df_like_color', '#cf3251', $blogId));
        $radius = self::number(self::configValue('df_like_border_radius', '18', $blogId), 0, 32, 18);
        $thanksMessage = self::thanksMessage($entryId, $blogId);
        $thanksAccent = self::thanksAccent($blogId);
        $align = self::choice($align, ['left', 'center', 'right'], 'left');
        $sourceClass = self::choice($source, ['auto', 'manual'], 'auto') === 'manual'
            ? 'df-like-manual-insert'
            : 'df-like-auto-insert';
        $countClass = $countVisible ? '' : 'df-like-button--count-hidden';
        if ($countVisible && $count <= 0) {
            $countClass = 'df-like-button--count-zero';
        }

        $Tpl = new Template($template, new ACMS_Corrector());
        return $Tpl->render([
            'objectType' => $objectType,
            'objectId' => $objectId,
            'blogId' => $blogId,
            'entryId' => $entryId,
            'count' => $count,
            'liked' => $liked ? (object)[] : null,
            'notLiked' => $liked ? null : (object)[],
            'likedClass' => $liked ? 'is-liked' : '',
            'ariaPressed' => $liked ? 'true' : 'false',
            'label' => $liked ? $labelLiked : $labelLike,
            'labelLike' => $labelLike,
            'labelLiked' => $labelLiked,
            'icon' => self::escape($icon),
            'hasIcon' => $icon === '' ? null : (object)[
                'icon' => self::escape($icon),
            ],
            'showCount' => $countVisible ? (object)[
                'count' => $count,
            ] : null,
            'countDisplay' => $countVisible ? 'show' : 'hide',
            'countClass' => $countClass,
            'color' => self::escape($color),
            'radius' => $radius,
            'thanksMessage' => self::escape($thanksMessage),
            'thanksAccent' => self::escape($thanksAccent),
            'align' => self::escape($align),
            'sourceClass' => self::escape($sourceClass),
        ]);
    }

    public static function defaultTemplate(): string
    {
        return '<div class="{sourceClass} df-like-auto-insert--{align}"><button type="button" class="df-like-button js-df-like-button {countClass} {likedClass}" style="--df-like-main-color: {color}; --df-like-border-radius: {radius}px;" data-object-type="{objectType}" data-object-id="{objectId}" data-blog-id="{blogId}" data-entry-id="{entryId}" data-label-like="{labelLike}" data-label-liked="{labelLiked}" data-count-display="{countDisplay}" data-thanks-message="{thanksMessage}" data-thanks-accent="{thanksAccent}" aria-pressed="{ariaPressed}"><!-- BEGIN hasIcon --><span class="df-like-button__icon" aria-hidden="true">{icon}</span><!-- END hasIcon --><span class="df-like-button__label">{label}</span><!-- BEGIN showCount --><span class="df-like-button__count js-df-like-count">{count}</span><!-- END showCount --></button></div><script src="/extension/plugins/DF_Like/assets/df-like.js?v=0.7.54" defer></script><link rel="stylesheet" href="/extension/plugins/DF_Like/assets/df-like.css?v=0.7.54">';
    }

    private static function thanksMessage(int $entryId, int $blogId): string
    {
        $entryMessage = LikeRepository::entryFieldValue($entryId, 'df_like_thanks_message');
        if ($entryMessage !== '') {
            return self::limitText($entryMessage, 300);
        }
        return self::limitText(self::configValue('df_like_thanks_message', '', $blogId), 300);
    }

    private static function thanksAccent(int $blogId): string
    {
        $accent = self::choice(
            self::configValue('df_like_thanks_accent', 'none', $blogId),
            ['none', 'fireworks', 'confetti', 'cracker', 'star', 'heart', 'thanks_face'],
            'none'
        );
        if ($accent === 'fireworks') {
            $accent = 'confetti';
        }
        return $accent;
    }

    private static function icon(string $value): string
    {
        $icons = [
            'none' => '',
            'heart' => '♥',
            'star' => '★',
            'clap' => '👏',
            'good' => '👍',
        ];
        return $icons[$value] ?? $icons['heart'];
    }

    private static function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function color(string $value): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#cf3251';
    }

    private static function number(string $value, int $min, int $max, int $fallback): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            return $fallback;
        }
        return max($min, min($max, (int)$value));
    }

    private static function configValue(string $key, string $fallback, int $blogId): string
    {
        return LikeBlogContext::configValue($key, $fallback, $blogId);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function limitText(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
