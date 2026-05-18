#!/usr/bin/env php
<?php

$options = getopt('', [
    'product:',
    'display-name:',
    'version:',
    'previous-version:',
    'repo:',
    'zip-name:',
    'output:',
]);

foreach (['product', 'display-name', 'version', 'repo', 'zip-name', 'output'] as $key) {
    if (empty($options[$key])) {
        fwrite(STDERR, "Missing --{$key}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$version = (string)$options['version'];
$tag = 'v' . $version;
$changelog = $root . '/CHANGELOG.md';
if (!is_file($changelog)) {
    fwrite(STDERR, "CHANGELOG.md was not found.\n");
    exit(1);
}

$lines = file($changelog, FILE_IGNORE_NEW_LINES);

function findChangelogSection(array $lines, string $version): array
{
    $inside = false;
    $date = '';
    $body = [];
    foreach ($lines as $line) {
        if (preg_match('/^##\s+\[?' . preg_quote($version, '/') . '\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?/', $line, $matches)) {
            $inside = true;
            $date = $matches[1] ?? '';
            continue;
        }
        if ($inside && (preg_match('/^<a\s+id="/', $line) || preg_match('/^##\s+/', $line))) {
            break;
        }
        if ($inside) {
            $body[] = rtrim($line);
        }
    }

    return [$date, trim(implode("\n", $body)), $body];
}

function findChangelogSectionsBetween(array $lines, string $version, string $previousVersion): string
{
    $inside = false;
    $sections = [];
    $current = [];
    foreach ($lines as $line) {
        if (preg_match('/^##\s+\[?([0-9]+\.[0-9]+\.[0-9]+)\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?/', $line, $matches)) {
            $foundVersion = $matches[1];
            if ($foundVersion === $previousVersion) {
                if ($current) {
                    $sections[] = trim(implode("\n", $current));
                    $current = [];
                }
                break;
            }
            if ($foundVersion === $version) {
                $inside = true;
            }
            if ($inside) {
                if ($current) {
                    $sections[] = trim(implode("\n", $current));
                    $current = [];
                }
                $date = isset($matches[2]) ? ' - ' . $matches[2] : '';
                $current[] = '### ' . $foundVersion . $date;
            }
            continue;
        }
        if (!$inside) {
            continue;
        }
        if (preg_match('/^<a\s+id="/', $line)) {
            continue;
        }
        if (preg_match('/^###\s+/', $line)) {
            $line = '#' . $line;
        }
        $current[] = rtrim($line);
    }
    if ($current) {
        $sections[] = trim(implode("\n", $current));
    }

    return trim(implode("\n\n", array_filter($sections)));
}

[$date, $bodyMarkdown, $body] = findChangelogSection($lines, $version);

if ($bodyMarkdown === '') {
    fwrite(STDERR, "CHANGELOG.md does not contain notes for {$version}.\n");
    exit(1);
}

$changes = [];
foreach ($body as $line) {
    if (preg_match('/^\s*-\s+(.+)$/', $line, $matches)) {
        $changes[] = trim($matches[1]);
    }
}

$repo = trim((string)$options['repo']);
$zipName = trim((string)$options['zip-name']);
$anchor = 'v' . str_replace('.', '-', $version);
$previousVersion = trim((string)($options['previous-version'] ?? ''));
$bodySincePrevious = '';
if ($previousVersion !== '' && $previousVersion !== $version) {
    $bodySincePrevious = findChangelogSectionsBetween($lines, $version, $previousVersion);
}
$payload = [
    'product' => (string)$options['product'],
    'display_name' => (string)$options['display-name'],
    'version' => $version,
    'tag' => $tag,
    'previous_version' => $previousVersion,
    'previous_tag' => $previousVersion !== '' ? 'v' . $previousVersion : '',
    'date' => $date,
    'title' => (string)$options['display-name'] . ' ' . $tag,
    'github_release_url' => "https://github.com/{$repo}/releases/tag/{$tag}",
    'changelog_url' => "https://github.com/{$repo}/blob/{$tag}/CHANGELOG.md#{$anchor}",
    'download_url' => "https://github.com/{$repo}/releases/download/{$tag}/{$zipName}",
    'changes' => $changes,
    'body_markdown' => $bodyMarkdown,
    'body_markdown_since_previous_release' => $bodySincePrevious,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "Failed to encode release JSON.\n");
    exit(1);
}

if (file_put_contents((string)$options['output'], $json . "\n") === false) {
    fwrite(STDERR, "Failed to write release JSON.\n");
    exit(1);
}
