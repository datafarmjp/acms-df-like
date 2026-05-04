<?php

/**
 * Regression test for DF_Like button rendering.
 *
 * Run:
 * /Applications/MAMP/bin/php/php8.2.26/bin/php extension/plugins/DF_Like/tests/LikeButtonRendererTest.php
 *
 * This test uses the local a-blog cms/MAMP database in read-only mode. It expects
 * blog 1 to have DF_Like enabled and df_like_icon=good.
 */

use Acms\Plugins\DF_Like\Services\LikeBlogContext;
use Acms\Plugins\DF_Like\Services\LikeButtonRenderer;
use Acms\Plugins\DF_Like\Services\LikeRepository;

require_once dirname(__DIR__, 4) . '/php/standalone.php';

function df_like_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function df_like_assert_contains(string $needle, string $haystack, string $message): void
{
    df_like_assert_true(strpos($haystack, $needle) !== false, $message);
}

function df_like_assert_not_contains(string $needle, string $haystack, string $message): void
{
    df_like_assert_true(strpos($haystack, $needle) === false, $message);
}

function df_like_run_renderer_test(): bool
{
    $blogId = 1;
    $entryId = 3;
    $thumbsUp = "\xF0\x9F\x91\x8D";

    df_like_assert_true(
        LikeBlogContext::isAppEnabled($blogId),
        'Fixture error: DF_Like must be enabled on blog 1.'
    );
    df_like_assert_true(
        LikeBlogContext::entryBlogId($entryId, 0) === $blogId,
        'Fixture error: entry 3 must belong to blog 1.'
    );
    df_like_assert_true(
        LikeBlogContext::configValue('df_like_icon', '', $blogId) === 'good',
        'Fixture error: blog 1 must have df_like_icon=good.'
    );

    $html = LikeButtonRenderer::renderDefault($entryId, $blogId, 'left', 'manual');
    $count = LikeRepository::count('entry', (string)$entryId);

    df_like_assert_contains('class="df-like-button js-df-like-button', $html, 'Button class was not rendered.');
    df_like_assert_contains('data-object-type="entry"', $html, 'object type data attribute was not rendered.');
    df_like_assert_contains('data-object-id="' . $entryId . '"', $html, 'object id data attribute was not rendered.');
    df_like_assert_contains('data-blog-id="' . $blogId . '"', $html, 'blog id data attribute was not rendered.');
    df_like_assert_contains('data-label-like="', $html, 'like label data attribute was not rendered.');
    df_like_assert_contains('data-label-liked="', $html, 'liked label data attribute was not rendered.');

    df_like_assert_contains('class="df-like-button__icon"', $html, 'Icon span was not rendered.');
    df_like_assert_contains('>' . $thumbsUp . '</span>', $html, 'Icon block was rendered without the configured good icon.');
    df_like_assert_not_contains('class="df-like-button__icon" aria-hidden="true"></span>', $html, 'Icon span must not be empty.');

    df_like_assert_contains('class="df-like-button__count js-df-like-count">' . $count . '</span>', $html, 'Initial count was not rendered inside showCount block.');

    echo "OK: LikeButtonRenderer rendered icon and initial count.\n";
    return true;
}

acmsStandAloneRun('df_like_run_renderer_test');
