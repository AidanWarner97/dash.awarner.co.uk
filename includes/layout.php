<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once __DIR__ . '/auth.php';

auth_require();

function dashboard_header(string $title, string $active = 'dashboard', ?string $project = null): void
{
    $currentUser = auth_current_user();
    $displayName = (string) ($currentUser['display_name'] ?? 'Administrator');
    $nameParts = preg_split('/\s+/', trim($displayName)) ?: [];
    $initials = strtoupper(substr((string) ($nameParts[0] ?? 'A'), 0, 1) . substr((string) ($nameParts[count($nameParts) - 1] ?? ''), 0, 1));
    $tileOpen = $project === 'tileimagegen';
    $portfolioOpen = $project === 'portfolio';
    $tileSettings = project_settings('tileimagegen');
    $portfolioSettings = project_settings('portfolio');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#45475a">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Aidan Warner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
    <script src="/script.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <button class="icon-button menu-button" id="menu-button" type="button" aria-label="Open navigation" aria-expanded="false"><i data-lucide="menu"></i></button>
            <a class="identity" href="/"><span class="identity-mark">AW</span><span><strong>AIDAN WARNER</strong><small>Management dashboard</small></span></a>
            <div class="header-actions">
                <a class="icon-button" href="https://awarner.co.uk" target="_blank" rel="noreferrer" aria-label="Open awarner.co.uk" title="Open awarner.co.uk"><i data-lucide="external-link"></i></a>
                <a class="account-button" href="/account/"><span><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span><strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong><small>Administrator</small></span><i data-lucide="chevron-down"></i></a>
            </div>
        </div>
    </header>
    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <nav aria-label="Dashboard navigation">
                <p class="nav-heading">General</p>
                <a class="nav-item<?= $active === 'dashboard' ? ' active' : '' ?>" href="/"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
                <p class="nav-heading">Projects</p>
                <details class="project-group"<?= $tileOpen ? ' open' : '' ?>>
                    <summary><span class="project-icon tile-icon"><i data-lucide="<?= htmlspecialchars($tileSettings['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span><span><strong><?= htmlspecialchars($tileSettings['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($tileSettings['domain'], ENT_QUOTES, 'UTF-8') ?></small></span><i class="chevron" data-lucide="chevron-down"></i></summary>
                    <div class="project-links">
                        <?= dashboard_nav_link('/tileimagegen/', 'house', 'Overview', $active === 'tile-overview') ?>
                        <?= dashboard_nav_link('/tileimagegen/catalogue/', 'images', 'Catalogue', $active === 'tile-catalogue') ?>
                        <?= dashboard_nav_link('/tileimagegen/updates/', 'newspaper', 'Updates', $active === 'tile-updates') ?>
                        <?= dashboard_nav_link('/tileimagegen/feedback/', 'message-square-text', 'Feedback', $active === 'tile-feedback') ?>
                        <?= dashboard_nav_link('/tileimagegen/logs/', 'scroll-text', 'Logs', $active === 'tile-logs') ?>
                        <?= dashboard_nav_link('/tileimagegen/database/', 'database', 'Database', $active === 'tile-database') ?>
                        <?= dashboard_nav_link('/tileimagegen/settings/', 'settings', 'Settings', $active === 'tile-settings') ?>
                    </div>
                </details>
                <details class="project-group"<?= $portfolioOpen ? ' open' : '' ?>>
                    <summary><span class="project-icon portfolio-icon"><i data-lucide="<?= htmlspecialchars($portfolioSettings['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span><span><strong><?= htmlspecialchars($portfolioSettings['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($portfolioSettings['domain'], ENT_QUOTES, 'UTF-8') ?></small></span><i class="chevron" data-lucide="chevron-down"></i></summary>
                    <div class="project-links">
                        <?= dashboard_nav_link('/portfolio/', 'house', 'Overview', $active === 'portfolio-overview') ?>
                        <?= dashboard_nav_link('/portfolio/database/', 'database', 'Database', $active === 'portfolio-database') ?>
                        <?= dashboard_nav_link('/portfolio/authentication/', 'shield-check', 'Authentication', $active === 'portfolio-auth') ?>
                        <?= dashboard_nav_link('/portfolio/logs/', 'scroll-text', 'Logs', $active === 'portfolio-logs') ?>
                        <?= dashboard_nav_link('/portfolio/settings/', 'settings', 'Settings', $active === 'portfolio-settings') ?>
                    </div>
                </details>
            </nav>
            <div class="sidebar-footer">
                <div class="status-line"><span></span><div><strong>Dashboard online</strong><small>PHP application</small></div></div>
                <a href="/account/"><i data-lucide="circle-user-round"></i>Account settings</a>
                <form method="post" action="/auth/logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="sign-out"><i data-lucide="log-out"></i>Sign out</button></form>
            </div>
        </aside>
        <button class="sidebar-backdrop" id="sidebar-backdrop" type="button" aria-label="Close navigation"></button>
        <main id="main-content">
    <?php
}

function dashboard_nav_link(string $href, string $icon, string $label, bool $active): string
{
    return sprintf(
        '<a%s href="%s"><i data-lucide="%s"></i>%s</a>',
        $active ? ' class="active" aria-current="page"' : '',
        htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}

function dashboard_footer(): void
{
    ?>
        </main>
    </div>
</body>
</html>
    <?php
}

function project_placeholder(string $domain, string $projectName, string $eyebrow, string $heading, string $description, string $icon): void
{
    ?>
    <section class="view active">
        <div class="section-title project-title">
            <span><small><?= htmlspecialchars(strtoupper($domain), ENT_QUOTES, 'UTF-8') ?></small><h1><?= htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8') ?></h1></span>
            <a class="secondary-button" href="https://<?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Visit site</a>
        </div>
        <div class="content-card empty-state">
            <span class="empty-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
            <small><?= htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') ?></small>
            <h2><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </section>
    <?php
}