<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function tileimagegen_overview_period(int $days): int
{
    return in_array($days, [7, 30, 90], true) ? $days : 7;
}

function tileimagegen_overview_data(int $requestedDays = 7): array
{
    $days = tileimagegen_overview_period($requestedDays);
    $response = [
        'period' => ['days' => $days],
        'usage' => ['available' => false, 'requests' => 0, 'downloads' => 0, 'previous_downloads' => 0, 'current' => [], 'previous' => [], 'recent' => [], 'error' => null],
        'feedback' => ['available' => false, 'new' => 0, 'open' => 0, 'recent' => [], 'error' => null],
        'updates' => ['available' => false, 'published' => 0, 'recent' => [], 'error' => null],
    ];

    try {
        $database = project_database_connection('tileimagegen');
        $logTable = project_database_table('tileimagegen', 'generate_log');
        $today = new DateTimeImmutable('today');
        $currentStart = $today->modify('-' . ($days - 1) . ' days');
        $currentEnd = $today->modify('+1 day');
        $previousStart = $currentStart->modify('-' . $days . ' days');

        $downloadStatement = $database->prepare(
            "SELECT DATE(downloaded_at) AS download_date, COUNT(*) AS total
             FROM {$logTable}
             WHERE downloaded = 1 AND downloaded_at >= :start AND downloaded_at < :end
             GROUP BY DATE(downloaded_at)"
        );
        $downloadStatement->execute([
            ':start' => $previousStart->format('Y-m-d H:i:s'),
            ':end' => $currentEnd->format('Y-m-d H:i:s'),
        ]);
        $downloadsByDate = [];
        foreach ($downloadStatement->fetchAll() as $row) {
            $downloadsByDate[(string) $row['download_date']] = (int) $row['total'];
        }

        for ($offset = 0; $offset < $days; $offset++) {
            $currentDate = $currentStart->modify('+' . $offset . ' days');
            $previousDate = $previousStart->modify('+' . $offset . ' days');
            $response['usage']['current'][] = [
                'date' => $currentDate->format('Y-m-d'),
                'label' => $currentDate->format($days <= 7 ? 'D' : 'j M'),
                'downloads' => $downloadsByDate[$currentDate->format('Y-m-d')] ?? 0,
            ];
            $response['usage']['previous'][] = [
                'date' => $previousDate->format('Y-m-d'),
                'label' => $previousDate->format($days <= 7 ? 'D' : 'j M'),
                'downloads' => $downloadsByDate[$previousDate->format('Y-m-d')] ?? 0,
            ];
        }

        $requestCountStatement = $database->prepare(
            "SELECT COUNT(*) FROM {$logTable} WHERE created_at >= :start AND created_at < :end"
        );
        $requestCountStatement->execute([
            ':start' => $currentStart->format('Y-m-d H:i:s'),
            ':end' => $currentEnd->format('Y-m-d H:i:s'),
        ]);

        $recentRequestStatement = $database->prepare(
            "SELECT id, request_id, tile_name, layout, status, downloaded, created_at
             FROM {$logTable}
             WHERE created_at >= :start AND created_at < :end
             ORDER BY created_at DESC, id DESC
             LIMIT 5"
        );
        $recentRequestStatement->execute([
            ':start' => $currentStart->format('Y-m-d H:i:s'),
            ':end' => $currentEnd->format('Y-m-d H:i:s'),
        ]);

        $response['usage']['available'] = true;
        $response['usage']['requests'] = (int) $requestCountStatement->fetchColumn();
        $response['usage']['downloads'] = array_sum(array_column($response['usage']['current'], 'downloads'));
        $response['usage']['previous_downloads'] = array_sum(array_column($response['usage']['previous'], 'downloads'));
        $response['usage']['recent'] = $recentRequestStatement->fetchAll();
    } catch (Throwable) {
        $response['usage']['error'] = 'Generation analytics could not be loaded.';
    }

    try {
        $root = tileimagegen_root();
        $updatesLibrary = $root === null ? null : $root . '/includes/updates.php';
        if ($updatesLibrary === null || !is_file($updatesLibrary)) {
            $response['updates']['error'] = 'Set TILEIMAGEGEN_ROOT to load update posts.';
        } else {
            require_once $updatesLibrary;
            $posts = get_all_posts($root . '/posts');
            $response['updates']['available'] = true;
            $response['updates']['published'] = count($posts);
            $response['updates']['recent'] = array_map(
                static fn(array $post): array => [
                    'slug' => (string) $post['slug'],
                    'title' => (string) $post['title'],
                    'date' => (string) $post['date'],
                    'author' => (string) $post['author'],
                ],
                array_slice($posts, 0, 3)
            );
        }
    } catch (Throwable) {
        $response['updates']['error'] = 'Update posts could not be loaded.';
    }

    try {
        $database = project_database_connection('tileimagegen');
        $feedbackTable = project_database_table('tileimagegen', 'feedback');
        if ($database !== null) {
            $statusRows = $database->query("SELECT status, COUNT(*) AS total FROM {$feedbackTable} GROUP BY status")->fetchAll();
            $statusCounts = [];
            foreach ($statusRows as $row) {
                $statusCounts[(string) $row['status']] = (int) $row['total'];
            }

            $recent = $database->query(
                "SELECT public_id, subject, feedback_type, status, created_at FROM {$feedbackTable} ORDER BY created_at DESC LIMIT 3"
            )->fetchAll();

            $response['feedback']['available'] = true;
            $response['feedback']['new'] = $statusCounts['new'] ?? 0;
            $response['feedback']['open'] = array_sum(array_intersect_key($statusCounts, array_flip(['open', 'investigating', 'building'])));
            $response['feedback']['recent'] = array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['public_id'],
                    'subject' => (string) $row['subject'],
                    'category' => (string) $row['feedback_type'],
                    'status' => (string) $row['status'],
                    'created_at' => date('j M Y', strtotime((string) $row['created_at'])),
                ],
                $recent
            );
        }
    } catch (Throwable) {
        $response['feedback']['error'] = 'Feedback data could not be loaded.';
    }

    return $response;
}