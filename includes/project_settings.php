<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/update_manager.php';

function project_definitions(): array
{
    return [
        'tileimagegen' => [
            'prefix' => 'TILEIMAGEGEN',
            'defaults' => [
                'title' => 'Tile Image Generator',
                'domain' => 'tileimagegen.uk',
                'icon' => 'grid-2x2',
                'description' => 'Manage the generator, its data and application configuration.',
            ],
        ],
        'portfolio' => [
            'prefix' => 'PORTFOLIO',
            'defaults' => [
                'title' => 'Personal Portfolio',
                'domain' => 'awarner.co.uk',
                'icon' => 'briefcase-business',
                'description' => 'Manage portfolio content, enquiries and site configuration.',
            ],
        ],
    ];
}

function project_settings(string $project): array
{
    $definition = project_definitions()[$project] ?? null;
    if ($definition === null) {
        throw new InvalidArgumentException('Unknown project.');
    }

    $prefix = $definition['prefix'];
    $value = static fn(string $key, string $fallback = ''): string => trim((string) (getenv($prefix . '_' . $key) ?: $fallback));

    return [
        'title' => $value('TITLE', $definition['defaults']['title']),
        'domain' => $value('DOMAIN', $definition['defaults']['domain']),
        'icon' => $value('ICON', $definition['defaults']['icon']),
        'description' => $value('DESCRIPTION', $definition['defaults']['description']),
        'root' => $value('ROOT'),
        'db_dsn' => $value('DB_DSN'),
        'db_name' => $value('DB_NAME'),
        'db_table_prefix' => $value('DB_TABLE_PREFIX'),
        'db_user' => $value('DB_USER'),
        'has_db_password' => $value('DB_PASSWORD') !== '',
    ];
}

function project_icon_options(): array
{
    return [
        'grid-2x2' => 'Grid',
        'briefcase-business' => 'Briefcase',
        'globe-2' => 'Globe',
        'image' => 'Image',
        'code-2' => 'Code',
        'layers-3' => 'Layers',
    ];
}

function project_settings_save(string $project, array $input): void
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Project changes are disabled until dashboard authentication is configured.');
    }

    $definitions = project_definitions();
    $definition = $definitions[$project] ?? null;
    if ($definition === null) {
        throw new InvalidArgumentException('Unknown project.');
    }

    $title = trim((string) ($input['title'] ?? ''));
    $domain = strtolower(trim((string) ($input['domain'] ?? '')));
    $icon = (string) ($input['icon'] ?? '');
    $root = trim((string) ($input['root'] ?? ''));
    $dsn = trim((string) ($input['db_dsn'] ?? ''));
    $databaseName = trim((string) ($input['db_name'] ?? ''));
    $tablePrefix = trim((string) ($input['db_table_prefix'] ?? ''));

    if ($title === '' || mb_strlen($title) > 80) throw new InvalidArgumentException('Enter a project title no longer than 80 characters.');
    if (!filter_var('https://' . $domain, FILTER_VALIDATE_URL) || str_contains($domain, '/')) throw new InvalidArgumentException('Enter a valid domain without a path.');
    if (!array_key_exists($icon, project_icon_options())) throw new InvalidArgumentException('Select a valid project icon.');
    if ($root !== '' && !is_dir($root)) throw new InvalidArgumentException('The source directory does not exist.');
    if ($dsn !== '' && !str_starts_with($dsn, 'mysql:')) throw new InvalidArgumentException('The database DSN must use MariaDB/MySQL.');
    if ($databaseName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $databaseName) !== 1) throw new InvalidArgumentException('The database name may contain only letters, numbers, and underscores.');
    if ($tablePrefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix) !== 1) throw new InvalidArgumentException('The table prefix may contain only letters, numbers, and underscores.');

    $prefix = $definition['prefix'];
    $changes = [
        $prefix . '_TITLE' => $title,
        $prefix . '_DOMAIN' => $domain,
        $prefix . '_ICON' => $icon,
        $prefix . '_DESCRIPTION' => trim((string) ($input['description'] ?? '')),
        $prefix . '_ROOT' => $root,
        $prefix . '_DB_DSN' => $dsn,
        $prefix . '_DB_NAME' => $databaseName,
        $prefix . '_DB_TABLE_PREFIX' => $tablePrefix,
        $prefix . '_DB_USER' => trim((string) ($input['db_user'] ?? '')),
    ];
    $password = (string) ($input['db_password'] ?? '');
    if ($password !== '') {
        $changes[$prefix . '_DB_PASSWORD'] = $password;
    }

    project_settings_write_environment($changes);
}

function project_settings_write_environment(array $changes): void
{
    $file = dirname(__DIR__) . '/.env';
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) throw new RuntimeException('The dashboard environment file could not be read.');

    $remaining = $changes;
    foreach ($lines as &$line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $match) !== 1 || !array_key_exists($match[1], $remaining)) continue;
        $line = $match[1] . '=' . project_settings_environment_value((string) $remaining[$match[1]]);
        unset($remaining[$match[1]]);
    }
    unset($line);

    foreach ($remaining as $key => $value) {
        $lines[] = $key . '=' . project_settings_environment_value((string) $value);
    }

    $temporary = $file . '.tmp';
    if (file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false || !rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException('The project settings could not be saved.');
    }

    foreach ($changes as $key => $value) putenv($key . '=' . $value);
}

function project_settings_environment_value(string $value): string
{
    return '"' . addcslashes($value, "\\\"") . '"';
}

function project_database_status(string $project): array
{
    $settings = project_settings($project);
    if ($settings['db_dsn'] === '') return ['state' => 'none', 'message' => 'No database connection configured.'];

    try {
        $database = project_database_connection($project);
        $database->query('SELECT 1');
        return ['state' => 'connected', 'message' => 'MariaDB connection successful.'];
    } catch (Throwable) {
        return ['state' => 'error', 'message' => 'MariaDB connection failed. Check the saved connection details.'];
    }
}

function project_database_connection(string $project): PDO
{
    $settings = project_settings($project);
    if ($settings['db_dsn'] === '') throw new RuntimeException('No database connection configured.');

    $prefix = project_definitions()[$project]['prefix'];
    $database = new PDO(
        $settings['db_dsn'],
        (string) (getenv($prefix . '_DB_USER') ?: ''),
        (string) (getenv($prefix . '_DB_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('The database connection must use MariaDB/MySQL.');

    $selectedDatabase = $database->query('SELECT DATABASE()')->fetchColumn();
    if ($selectedDatabase === null && $settings['db_name'] !== '') {
        if (preg_match('/^[A-Za-z0-9_]+$/', $settings['db_name']) !== 1) throw new RuntimeException('The configured database name is invalid.');
        $database->exec('USE `' . $settings['db_name'] . '`');
        $selectedDatabase = $settings['db_name'];
    }
    if ($selectedDatabase === null) throw new RuntimeException('No MariaDB database is selected. Add a database name in project settings.');

    return $database;
}

function project_database_table(string $project, string $baseName): string
{
    $settings = project_settings($project);
    $tableName = $settings['db_table_prefix'] . $baseName;
    if (preg_match('/^[A-Za-z0-9_]+$/', $tableName) !== 1) throw new RuntimeException('The configured database table name is invalid.');
    return '`' . $tableName . '`';
}

function render_project_settings(string $project): void
{
    $settings = project_settings($project);
    $status = project_database_status($project);
    $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="view active">
        <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>PROJECT SETTINGS</h1></span><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Visit site</a></div>
        <?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Project settings saved.</div><?php endif; ?>
        <?php if (!empty($GLOBALS['project_settings_error'])): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($GLOBALS['project_settings_error']) ?></div><?php endif; ?>
        <form class="settings-layout" method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>">
            <article class="content-card settings-card"><div class="card-heading"><div><small>IDENTITY</small><h2>PROJECT DETAILS</h2></div></div><div class="settings-fields">
                <label>Title<input name="title" maxlength="80" required value="<?= $escape($settings['title']) ?>"></label>
                <label>Domain<input name="domain" required value="<?= $escape($settings['domain']) ?>" placeholder="example.com"></label>
                <label>Icon<select name="icon"><?php foreach (project_icon_options() as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $settings['icon'] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
                <label class="wide">Description<textarea name="description" maxlength="240" rows="3"><?= $escape($settings['description']) ?></textarea></label>
                <label class="wide">Source directory<input name="root" value="<?= $escape($settings['root']) ?>" placeholder="/var/www/project"></label>
            </div></article>
            <article class="content-card settings-card"><div class="card-heading"><div><small>DATABASE</small><h2>MARIA DB CONNECTION</h2></div><span class="connection-state <?= $escape($status['state']) ?>"><i></i><?= $escape($status['state'] === 'connected' ? 'Connected' : 'Not connected') ?></span></div><div class="settings-fields">
                <p class="settings-help wide"><?= $escape($status['message']) ?></p>
                <label class="wide">DSN<input name="db_dsn" value="<?= $escape($settings['db_dsn']) ?>" placeholder="mysql:host=127.0.0.1;port=3306;charset=utf8mb4"></label>
                <label>Database name<input name="db_name" value="<?= $escape($settings['db_name']) ?>"></label>
                <label>Username<input name="db_user" autocomplete="username" value="<?= $escape($settings['db_user']) ?>"></label>
                <label class="wide">Table prefix<input name="db_table_prefix" value="<?= $escape($settings['db_table_prefix']) ?>" placeholder="Optional, for example wp_"><small>Only matching tables appear in the database browser. Leave blank to show all tables.</small></label>
                <label class="wide">Password<input type="password" name="db_password" autocomplete="new-password" placeholder="<?= $settings['has_db_password'] ? 'Saved - leave blank to keep it' : 'Enter database password' ?>"></label>
            </div></article>
            <div class="settings-actions"><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save settings</button></div>
        </form>
    </section>
    <?php
}