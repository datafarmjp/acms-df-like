#!/usr/bin/env php
<?php

$options = getopt('', [
    'product:',
    'display-name:',
    'version:',
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
$inside = false;
$date = '';
$body = [];
foreach ($lines as $line) {
    if (preg_match('/^##\s+\[?' . preg_quote($version, '/') . '\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?/', $line, $matches)) {
        $inside = true;
        $date = $matches[1] ?? '';
        continue;
    }
    if ($inside && preg_match('/^##\s+/', $line)) {
        break;
    }
    if ($inside) {
        $body[] = rtrim($line);
    }
}

$bodyMarkdown = trim(implode("\n", $body));
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
$payload = [
    'product' => (string)$options['product'],
    'display_name' => (string)$options['display-name'],
    'version' => $version,
    'tag' => $tag,
    'date' => $date,
    'title' => (string)$options['display-name'] . ' ' . $tag,
    'github_release_url' => "https://github.com/{$repo}/releases/tag/{$tag}",
    'download_url' => "https://github.com/{$repo}/releases/download/{$tag}/{$zipName}",
    'changes' => $changes,
    'body_markdown' => $bodyMarkdown,
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
