<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
$tileSettings = project_settings('tileimagegen');
$portfolioSettings = project_settings('portfolio');
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
dashboard_header('Dashboard');
?>
<section class="view active">
    <div class="section-title"><span><small>DASHBOARD</small><h1>YOUR PROJECTS</h1></span><p>Select a project to begin managing its services.</p></div>
    <div class="project-cards">
        <article class="project-card">
            <div class="project-card-heading"><span class="project-icon tile-icon"><i data-lucide="<?= $escape($tileSettings['icon']) ?>"></i></span><span class="project-state"><i></i>Ready</span></div>
            <div><h2><?= $escape($tileSettings['title']) ?></h2><a href="https://<?= $escape($tileSettings['domain']) ?>" target="_blank" rel="noreferrer"><?= $escape($tileSettings['domain']) ?> <i data-lucide="external-link"></i></a><p><?= $escape($tileSettings['description']) ?></p></div>
            <a class="primary-button" href="/tileimagegen/">Manage project <i data-lucide="arrow-right"></i></a>
        </article>
        <article class="project-card">
            <div class="project-card-heading"><span class="project-icon portfolio-icon"><i data-lucide="<?= $escape($portfolioSettings['icon']) ?>"></i></span><span class="project-state"><i></i>Ready</span></div>
            <div><h2><?= $escape($portfolioSettings['title']) ?></h2><a href="https://<?= $escape($portfolioSettings['domain']) ?>" target="_blank" rel="noreferrer"><?= $escape($portfolioSettings['domain']) ?> <i data-lucide="external-link"></i></a><p><?= $escape($portfolioSettings['description']) ?></p></div>
            <a class="primary-button" href="/portfolio/">Manage project <i data-lucide="arrow-right"></i></a>
        </article>
    </div>
</section>
<?php dashboard_footer(); ?>