<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function evolution_rate_limit_defaults(): array
{
    return [
        'window_seconds' => 30,
        'max_requests' => 5,
        'multi_user_ip_threshold' => 2,
        'block_ladder' => [1800, 7200, 86400],
    ];
}

function evolution_rate_limit_settings_path(?string $root = null): string
{
    $root ??= project_settings('evolutioncdn')['root'];
    if ($root === '' || !is_dir($root)) throw new RuntimeException('The Evolution X CDN source directory is unavailable.');
    return rtrim($root, '/') . '/modules/setup/rate_limit_settings.json';
}

function evolution_rate_limit_settings(?string $root = null): array
{
    $defaults = evolution_rate_limit_defaults();
    $path = evolution_rate_limit_settings_path($root);
    if (!is_readable($path)) return $defaults;

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) return $defaults;

    return [
        'window_seconds' => (int) ($decoded['window_seconds'] ?? $defaults['window_seconds']),
        'max_requests' => (int) ($decoded['max_requests'] ?? $defaults['max_requests']),
        'multi_user_ip_threshold' => (int) ($decoded['multi_user_ip_threshold'] ?? $defaults['multi_user_ip_threshold']),
        'block_ladder' => array_map('intval', is_array($decoded['block_ladder'] ?? null) ? $decoded['block_ladder'] : $defaults['block_ladder']),
    ];
}

function evolution_rate_limit_input_integer(array $input, string $key, int $minimum, int $maximum): int
{
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
    if ($value === false) throw new InvalidArgumentException('Enter a valid value for every rate limiter setting.');
    return $value;
}

function evolution_rate_limit_save(array $input, ?string $root = null): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Rate limiter changes are disabled.');

    $settings = [
        'window_seconds' => evolution_rate_limit_input_integer($input, 'window_seconds', 1, 3600),
        'max_requests' => evolution_rate_limit_input_integer($input, 'max_requests', 1, 1000),
        'multi_user_ip_threshold' => evolution_rate_limit_input_integer($input, 'multi_user_ip_threshold', 1, 100),
        'block_ladder' => [
            evolution_rate_limit_input_integer($input, 'block_first_minutes', 1, 43200) * 60,
            evolution_rate_limit_input_integer($input, 'block_second_minutes', 1, 43200) * 60,
            evolution_rate_limit_input_integer($input, 'block_third_minutes', 1, 43200) * 60,
        ],
    ];
    if (!($settings['block_ladder'][0] < $settings['block_ladder'][1] && $settings['block_ladder'][1] < $settings['block_ladder'][2])) {
        throw new InvalidArgumentException('Escalation durations must increase at every step.');
    }

    $path = evolution_rate_limit_settings_path($root);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The rate limiter settings directory could not be created.');
    }
    $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $payload, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('The rate limiter settings could not be saved.');
    }
}
