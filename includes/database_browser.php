<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function database_browser_tables(PDO $database, string $prefix, string $search = ''): array
{
    $tables = $database->query(
        'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
         ORDER BY TABLE_NAME'
    )->fetchAll();

    return array_values(array_filter($tables, static function (array $table) use ($prefix, $search): bool {
        $name = (string) $table['TABLE_NAME'];
        return ($prefix === '' || str_starts_with($name, $prefix))
            && ($search === '' || stripos($name, $search) !== false);
    }));
}

function database_browser_table(PDO $database, string $tableName, string $prefix): ?array
{
    $tables = database_browser_tables($database, $prefix);
    foreach ($tables as $table) {
        if ($table['TABLE_NAME'] !== $tableName) continue;

        $columns = $database->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION'
        );
        $columns->execute([':table' => $tableName]);
        $table['columns'] = $columns->fetchAll();

        $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
        $primaryColumns = array_values(array_filter(
            $table['columns'],
            static fn(array $column): bool => $column['COLUMN_KEY'] === 'PRI'
        ));
        $orderBy = $primaryColumns === [] ? '' : ' ORDER BY ' . implode(', ', array_map(
            static fn(array $column): string => '`' . str_replace('`', '``', (string) $column['COLUMN_NAME']) . '`',
            $primaryColumns
        ));
        $table['rows'] = $database->query("SELECT * FROM {$quotedTable}{$orderBy} LIMIT 100")->fetchAll();
        return $table;
    }

    return null;
}

function database_browser_size(int|string|null $bytes): string
{
    $size = max(0, (int) $bytes);
    if ($size < 1024) return $size . ' B';
    if ($size < 1048576) return number_format($size / 1024, 1) . ' KB';
    return number_format($size / 1048576, 1) . ' MB';
}

function database_browser_cell(mixed $value): string
{
    if ($value === null) return 'NULL';
    $text = (string) $value;
    return mb_strlen($text) > 180 ? mb_substr($text, 0, 180) . '...' : $text;
}

function render_database_browser(string $project): void
{
    $settings = project_settings($project);
    $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $search = trim((string) ($_GET['q'] ?? ''));
    $selectedName = trim((string) ($_GET['table'] ?? ''));
    $tables = [];
    $selectedTable = null;
    $error = null;
    $databaseName = $settings['db_name'];

    try {
        $database = project_database_connection($project);
        $databaseName = (string) $database->query('SELECT DATABASE()')->fetchColumn();
        $tables = database_browser_tables($database, $settings['db_table_prefix'], $search);
        if ($selectedName !== '') {
            $selectedTable = database_browser_table($database, $selectedName, $settings['db_table_prefix']);
            if ($selectedTable === null) $error = 'The selected table does not exist or does not match the saved prefix.';
        }
    } catch (Throwable) {
        $error = 'The MariaDB database could not be reached. Check the project settings.';
    }
    ?>
    <section class="view active">
        <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>DATABASE</h1></span><a class="secondary-button" href="/<?= $escape($project === 'tileimagegen' ? 'tileimagegen' : 'portfolio') ?>/settings/"><i data-lucide="settings"></i>Database settings</a></div>
        <?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
        <div class="database-summary">
            <span><small>DATABASE</small><strong><?= $escape($databaseName !== '' ? $databaseName : 'Not selected') ?></strong></span>
            <span><small>VISIBLE TABLES</small><strong><?= count($tables) ?></strong></span>
            <span><small>SAVED PREFIX</small><strong><?= $escape($settings['db_table_prefix'] !== '' ? $settings['db_table_prefix'] : 'All tables') ?></strong></span>
            <span><small>ACCESS</small><strong>Read only</strong></span>
        </div>
        <div class="database-layout">
            <aside class="content-card database-tables">
                <div class="card-heading"><div><small>SCHEMA</small><h2>TABLES</h2></div></div>
                <form class="database-search" method="get"><label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Search table names"></label><button class="icon-button" type="submit" aria-label="Search tables" title="Search tables"><i data-lucide="arrow-right"></i></button></form>
                <div class="database-table-list">
                    <?php if ($tables === []): ?><div class="list-message">No tables match<?= $settings['db_table_prefix'] !== '' ? ' the prefix ' . $escape($settings['db_table_prefix']) : '' ?><?= $search !== '' ? ' and current search' : '' ?>. Update the prefix in Database settings or clear the search.</div><?php endif; ?>
                    <?php foreach ($tables as $table): ?><a class="database-table-link<?= $selectedName === $table['TABLE_NAME'] ? ' active' : '' ?>" href="?table=<?= rawurlencode((string) $table['TABLE_NAME']) ?><?= $search !== '' ? '&amp;q=' . rawurlencode($search) : '' ?>"><span><strong><?= $escape($table['TABLE_NAME']) ?></strong><small><?= $escape(number_format((int) $table['TABLE_ROWS'])) ?> estimated rows</small></span><i data-lucide="chevron-right"></i></a><?php endforeach; ?>
                </div>
            </aside>
            <div class="database-detail">
                <?php if ($selectedTable === null && $selectedName === ''): ?><article class="content-card empty-state database-empty"><span class="empty-icon"><i data-lucide="table-2"></i></span><small>READ-ONLY BROWSER</small><h2>SELECT A TABLE</h2><p>Inspect its columns and preview up to 100 rows.</p></article><?php endif; ?>
                <?php if ($selectedTable !== null): ?>
                    <article class="content-card database-panel"><div class="card-heading"><div><small><?= $escape($selectedTable['ENGINE']) ?> · <?= $escape(database_browser_size((int) $selectedTable['DATA_LENGTH'] + (int) $selectedTable['INDEX_LENGTH'])) ?></small><h2><?= $escape($selectedTable['TABLE_NAME']) ?></h2></div><span class="database-count"><?= count($selectedTable['rows']) ?> rows shown</span></div>
                        <div class="database-scroll"><table><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody><?php foreach ($selectedTable['columns'] as $column): ?><tr><td><strong><?= $escape($column['COLUMN_NAME']) ?></strong></td><td><?= $escape($column['COLUMN_TYPE']) ?></td><td><?= $escape($column['IS_NULLABLE']) ?></td><td><?= $escape($column['COLUMN_KEY'] ?: '-') ?></td><td><?= $escape($column['COLUMN_DEFAULT'] ?? 'NULL') ?></td><td><?= $escape($column['EXTRA'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div>
                    </article>
                    <article class="content-card database-panel"><div class="card-heading"><div><small>FIRST 100 ROWS</small><h2>DATA PREVIEW</h2></div></div>
                        <div class="database-scroll"><table><thead><tr><?php foreach ($selectedTable['columns'] as $column): ?><th><?= $escape($column['COLUMN_NAME']) ?></th><?php endforeach; ?></tr></thead><tbody><?php if ($selectedTable['rows'] === []): ?><tr><td colspan="<?= count($selectedTable['columns']) ?>">This table is empty.</td></tr><?php endif; ?><?php foreach ($selectedTable['rows'] as $row): ?><tr><?php foreach ($selectedTable['columns'] as $column): $value = $row[$column['COLUMN_NAME']] ?? null; ?><td class="<?= $value === null ? 'database-null' : '' ?>"><?= $escape(database_browser_cell($value)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
                    </article>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}