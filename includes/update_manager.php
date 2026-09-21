<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function updates_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
        ]);
    }
}

function updates_csrf_token(): string
{
    updates_start_session();
    if (empty($_SESSION['updates_csrf_token'])) {
        $_SESSION['updates_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['updates_csrf_token'];
}

function updates_verify_csrf(string $token): bool
{
    return hash_equals(updates_csrf_token(), $token);
}

function updates_writes_enabled(): bool
{
    return filter_var(getenv('DASHBOARD_ALLOW_WRITES') ?: 'false', FILTER_VALIDATE_BOOL);
}

function updates_posts_directory(): string
{
    $root = tileimagegen_root();
    if ($root === null) {
        throw new RuntimeException('The Tile Image Generator source path is not configured.');
    }

    $directory = $root . '/posts';
    if (!is_dir($directory) || !is_readable($directory)) {
        throw new RuntimeException('The Tile Image Generator posts directory is unavailable.');
    }

    return $directory;
}

function updates_load_library(): void
{
    $library = dirname(updates_posts_directory()) . '/includes/updates.php';
    if (!is_file($library)) {
        throw new RuntimeException('The Tile Image Generator update parser is unavailable.');
    }

    require_once $library;
}

function updates_all_posts(): array
{
    updates_load_library();
    $files = glob(updates_posts_directory() . '/*.{md,markdown}', GLOB_BRACE) ?: [];
    $posts = [];

    foreach ($files as $file) {
        $post = parse_post_file($file);
        if ($post === null) {
            continue;
        }

        $timestamp = strtotime((string) $post['date']);
        $post['state'] = empty($post['published'])
            ? 'draft'
            : (($timestamp !== false && $timestamp > time()) ? 'scheduled' : 'published');
        $post['filename'] = basename($file);
        $posts[] = $post;
    }

    usort($posts, static fn(array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date']));
    return $posts;
}

function updates_find_post(string $filename): ?array
{
    if (!updates_valid_filename($filename)) {
        return null;
    }

    foreach (updates_all_posts() as $post) {
        if ($post['filename'] === $filename) {
            return $post;
        }
    }

    return null;
}

function updates_save_post(array $input): string
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Update writing is disabled. Set DASHBOARD_ALLOW_WRITES=true after administrator authentication is configured.');
    }

    $title = trim((string) ($input['title'] ?? ''));
    $author = trim((string) ($input['author'] ?? ''));
    $content = trim((string) ($input['content'] ?? ''));
    $state = (string) ($input['state'] ?? 'draft');
    $original = basename((string) ($input['original'] ?? ''));

    if ($title === '' || strlen($title) > 180) {
        throw new InvalidArgumentException('Enter a title no longer than 180 characters.');
    }
    if ($author === '' || strlen($author) > 100) {
        throw new InvalidArgumentException('Enter an author no longer than 100 characters.');
    }
    if ($content === '') {
        throw new InvalidArgumentException('Enter the update content.');
    }
    if (!in_array($state, ['draft', 'published', 'scheduled'], true)) {
        throw new InvalidArgumentException('Select a valid publication state.');
    }

    $timezone = new DateTimeZone('Europe/London');
    $publishAtInput = trim((string) ($input['publish_at'] ?? ''));
    $publishAt = $publishAtInput === '' ? new DateTimeImmutable('today', $timezone) : DateTimeImmutable::createFromFormat('!Y-m-d', $publishAtInput, $timezone);
    if (!$publishAt instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Enter a valid publication date.');
    }
    if ($state === 'scheduled' && $publishAt <= new DateTimeImmutable('today', $timezone)) {
        throw new InvalidArgumentException('Scheduled updates must use a future date.');
    }

    updates_load_library();
    $slugInput = trim((string) ($input['slug'] ?? ''));
    $slug = slugify($slugInput !== '' ? $slugInput : $title);
    $filename = $publishAt->format('Y-m-d') . '-' . $slug . '.md';
    $directory = updates_posts_directory();
    $target = $directory . '/' . $filename;
    $originalPath = updates_valid_filename($original) ? $directory . '/' . $original : null;

    if (is_file($target) && ($originalPath === null || realpath($target) !== realpath($originalPath))) {
        throw new RuntimeException('An update with that date and slug already exists.');
    }

    $cleanTitle = updates_frontmatter_value($title);
    $cleanAuthor = updates_frontmatter_value($author);
    $frontmatter = "---\n"
        . "title: {$cleanTitle}\n"
        . 'date: ' . $publishAt->format('Y-m-d') . "\n"
        . "author: {$cleanAuthor}\n"
        . "slug: {$slug}\n"
        . 'published: ' . ($state === 'draft' ? 'false' : 'true') . "\n"
        . "---\n\n";

    $temporary = tempnam($directory, '.dashboard-update-');
    if ($temporary === false || file_put_contents($temporary, $frontmatter . $content . "\n", LOCK_EX) === false) {
        throw new RuntimeException('The update could not be written.');
    }
    if (!rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('The update could not be moved into place.');
    }
    if ($originalPath !== null && $originalPath !== $target && is_file($originalPath)) {
        unlink($originalPath);
    }

    return $filename;
}

function updates_decode_submission(string $payload): array
{
    if ($payload === '' || strlen($payload) > 2_000_000 || preg_match('/^[A-Za-z0-9_-]+$/', $payload) !== 1) {
        throw new InvalidArgumentException('The update submission is invalid. Refresh and try again.');
    }

    $padding = (4 - strlen($payload) % 4) % 4;
    $decoded = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', $padding), true);
    if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
        throw new InvalidArgumentException('The update submission could not be decoded.');
    }

    $input = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('The update submission is invalid.');
    $allowed = ['original', 'title', 'slug', 'author', 'state', 'publish_at', 'content'];
    return array_intersect_key($input, array_flip($allowed));
}

function updates_delete_post(string $filename): void
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Update writing is disabled.');
    }
    if (!updates_valid_filename($filename)) {
        throw new InvalidArgumentException('Invalid update filename.');
    }

    $path = updates_posts_directory() . '/' . $filename;
    if (!is_file($path) || !unlink($path)) {
        throw new RuntimeException('The update could not be deleted.');
    }
}

function updates_valid_filename(string $filename): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.(?:md|markdown)$/', $filename) === 1;
}

function updates_frontmatter_value(string $value): string
{
    return trim(str_replace(["\r", "\n", '"', "'"], ['', ' ', '', ''], $value));
}