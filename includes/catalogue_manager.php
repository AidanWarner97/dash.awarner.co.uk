<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function catalogue_project_root(): string
{
    $configuredRoot = project_settings('tileimagegen')['root'];
    $root = $configuredRoot !== '' ? realpath($configuredRoot) : false;
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('The Tile Image Generator source directory is unavailable.');
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function catalogue_file_path(): string
{
    return catalogue_project_root() . '/catalogue/tiles.json';
}

function catalogue_load(?string $file = null): array
{
    $path = $file ?? catalogue_file_path();
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        throw new RuntimeException('The tile catalogue could not be read.');
    }

    try {
        $catalogue = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The tile catalogue contains invalid JSON.', 0, $exception);
    }

    if (!is_array($catalogue) || !isset($catalogue['brands']) || !is_array($catalogue['brands'])) {
        throw new RuntimeException('The tile catalogue must contain a brands array.');
    }

    catalogue_sort($catalogue);
    return $catalogue;
}

function catalogue_sort(array &$catalogue): void
{
    $sortByName = static fn(array $left, array $right): int => strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    usort($catalogue['brands'], $sortByName);
    foreach ($catalogue['brands'] as &$brand) {
        $brand['ranges'] = is_array($brand['ranges'] ?? null) ? $brand['ranges'] : [];
        usort($brand['ranges'], $sortByName);
        foreach ($brand['ranges'] as &$range) {
            $range['versions'] = is_array($range['versions'] ?? null) ? $range['versions'] : [];
            usort($range['versions'], $sortByName);
            foreach ($range['versions'] as &$version) {
                $version['sizes'] = is_array($version['sizes'] ?? null) ? $version['sizes'] : [];
                usort($version['sizes'], $sortByName);
            }
        }
    }
    unset($brand, $range, $version);
}

function catalogue_summary(array $catalogue): array
{
    $summary = ['brands' => 0, 'ranges' => 0, 'versions' => 0, 'sizes' => 0, 'images' => 0];
    foreach ($catalogue['brands'] as $brand) {
        $summary['brands']++;
        foreach (($brand['ranges'] ?? []) as $range) {
            $summary['ranges']++;
            foreach (($range['versions'] ?? []) as $version) {
                $summary['versions']++;
                foreach (($version['sizes'] ?? []) as $size) {
                    $summary['sizes']++;
                    $summary['images'] += count($size['images'] ?? []);
                }
            }
        }
    }

    return $summary;
}

function catalogue_slug(string $value): string
{
    $value = strtolower(trim($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    if ($value === '' || strlen($value) > 80) {
        throw new InvalidArgumentException('Catalogue names must contain letters or numbers and be no longer than 80 characters.');
    }

    return $value;
}

function catalogue_save(array $catalogue, ?string $file = null): void
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Catalogue writing is disabled.');
    }

    catalogue_sort($catalogue);
    $path = $file ?? catalogue_file_path();
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('The catalogue directory is not writable.');
    }

    $json = json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = tempnam($directory, '.tiles-');
    if ($temporary === false) {
        throw new RuntimeException('A temporary catalogue file could not be created.');
    }

    try {
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new RuntimeException('The tile catalogue could not be saved.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function catalogue_upsert_size(array $input, array $uploads, ?string $file = null, ?string $projectRoot = null): array
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Catalogue writing is disabled.');
    }

    $names = [];
    foreach (['brand', 'range', 'version', 'size'] as $field) {
        $names[$field] = trim((string) ($input[$field] ?? ''));
        if ($names[$field] === '' || mb_strlen($names[$field]) > 100) {
            throw new InvalidArgumentException('Enter each catalogue name using no more than 100 characters.');
        }
    }

    $width = filter_var($input['width'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
    $height = filter_var($input['height'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
    if ($width === false || $height === false) {
        throw new InvalidArgumentException('Tile dimensions must be between 1 and 10,000 pixels.');
    }

    $ids = array_map('catalogue_slug', $names);
    $catalogue = catalogue_load($file);
    $existingImages = [];
    $originalIds = [];
    foreach (['brand', 'range', 'version', 'size'] as $field) {
        $originalId = trim((string) ($input['original_' . $field] ?? ''));
        if ($originalId !== '') {
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $originalId) !== 1) {
                throw new InvalidArgumentException('The original catalogue entry is invalid.');
            }
            $originalIds[$field] = $originalId;
        }
    }
    if ($originalIds !== []) {
        if (count($originalIds) !== 4) {
            throw new InvalidArgumentException('The original catalogue entry is incomplete.');
        }
        $existingSize = catalogue_extract_size($catalogue, $originalIds);
        $existingImages = $existingSize['images'] ?? [];
    }

    $brandIndex = catalogue_find_or_add($catalogue['brands'], $ids['brand'], $names['brand'], 'ranges');
    $rangeIndex = catalogue_find_or_add($catalogue['brands'][$brandIndex]['ranges'], $ids['range'], $names['range'], 'versions');
    $versionIndex = catalogue_find_or_add($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'], $ids['version'], $names['version'], 'sizes');
    $sizes =& $catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'][$versionIndex]['sizes'];
    $sizeIndex = catalogue_find_or_add($sizes, $ids['size'], $names['size'], 'images');
    $sizes[$sizeIndex]['name'] = $names['size'];
    $sizes[$sizeIndex]['width'] = $width;
    $sizes[$sizeIndex]['height'] = $height;

    $root = $projectRoot ?? catalogue_project_root();
    $destination = $root . '/catalogue/images/' . implode('/', [$ids['brand'], $ids['range'], $ids['version'], $ids['size']]);
    $stored = catalogue_store_uploads($uploads, $destination, $root);
    $replaceImages = filter_var($input['replace_images'] ?? false, FILTER_VALIDATE_BOOL);
    if ($replaceImages && $stored === []) {
        throw new InvalidArgumentException('Choose at least one new image when replacing existing images.');
    }
    $targetImages = $replaceImages ? [] : $existingImages;
    $sizes[$sizeIndex]['images'] = array_values(array_unique(array_merge($sizes[$sizeIndex]['images'] ?? [], $targetImages, $stored)));

    try {
        catalogue_save($catalogue, $file);
    } catch (Throwable $exception) {
        foreach ($stored as $relativePath) {
            @unlink($root . '/' . $relativePath);
        }
        throw $exception;
    }
    if ($replaceImages) {
        foreach (array_diff($existingImages, $sizes[$sizeIndex]['images']) as $oldImage) {
            catalogue_delete_stored_file($root, (string) $oldImage);
        }
    }

    return ['catalogue' => $catalogue, 'uploaded' => count($stored)];
}

function catalogue_import_csv_upload(array $upload, ?string $file = null): array
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Catalogue writing is disabled.');
    }
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a CSV file to import.');
    }
    $path = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($path) || (int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('The catalogue CSV must be a valid upload no larger than 2 MB.');
    }

    return catalogue_import_csv_file($path, $file);
}

function catalogue_import_csv_file(string $csvPath, ?string $file = null): array
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Catalogue writing is disabled.');
    }
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The catalogue CSV could not be read.');
    }

    $requiredHeaders = ['brand', 'range', 'version', 'size', 'width', 'height'];
    $rows = [];
    try {
        $headers = fgetcsv($handle, null, ',', '"', '');
        if ($headers === false) {
            throw new InvalidArgumentException('The catalogue CSV is empty.');
        }
        $headers = array_map(static function (mixed $header): string {
            $value = catalogue_csv_text((string) $header);
            return strtolower(preg_replace('/^\x{FEFF}/u', '', $value) ?? $value);
        }, $headers);
        if (count(array_unique($headers)) !== count($headers) || array_diff($requiredHeaders, $headers) !== []) {
            throw new InvalidArgumentException('The CSV header must include brand, range, version, size, width, and height.');
        }
        $headerMap = array_flip($headers);

        $line = 1;
        while (($columns = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                continue;
            }
            if (count($columns) !== count($headers)) {
                throw new InvalidArgumentException("CSV row {$line} has the wrong number of columns.");
            }

            $row = [];
            foreach ($requiredHeaders as $header) {
                $row[$header] = catalogue_csv_text((string) $columns[$headerMap[$header]]);
            }
            foreach (['brand', 'range', 'version', 'size'] as $field) {
                if ($row[$field] === '' || mb_strlen($row[$field]) > 100) {
                    throw new InvalidArgumentException("CSV row {$line} has an invalid {$field} name.");
                }
                catalogue_slug($row[$field]);
            }
            foreach (['width', 'height'] as $field) {
                $value = filter_var($row[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
                if ($value === false) {
                    throw new InvalidArgumentException("CSV row {$line} has an invalid {$field}; use a whole number from 1 to 10,000.");
                }
                $row[$field] = $value;
            }
            $rows[] = $row;
            if (count($rows) > 500) {
                throw new InvalidArgumentException('Import no more than 500 catalogue rows at once.');
            }
        }
    } finally {
        fclose($handle);
    }
    if ($rows === []) {
        throw new InvalidArgumentException('The catalogue CSV contains no entries.');
    }

    $catalogue = catalogue_load($file);
    $created = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $ids = [];
        foreach (['brand', 'range', 'version', 'size'] as $field) {
            $ids[$field] = catalogue_slug((string) $row[$field]);
        }
        $brandIndex = catalogue_find_or_add($catalogue['brands'], $ids['brand'], $row['brand'], 'ranges');
        $rangeIndex = catalogue_find_or_add($catalogue['brands'][$brandIndex]['ranges'], $ids['range'], $row['range'], 'versions');
        $versionIndex = catalogue_find_or_add($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'], $ids['version'], $row['version'], 'sizes');
        $sizes =& $catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'][$versionIndex]['sizes'];
        $existing = false;
        foreach ($sizes as $size) {
            if (($size['id'] ?? '') === $ids['size']) {
                $existing = true;
                break;
            }
        }
        $sizeIndex = catalogue_find_or_add($sizes, $ids['size'], $row['size'], 'images');
        $sizes[$sizeIndex]['width'] = $row['width'];
        $sizes[$sizeIndex]['height'] = $row['height'];
        $existing ? $updated++ : $created++;
        unset($sizes);
    }

    catalogue_save($catalogue, $file);
    return ['rows' => count($rows), 'created' => $created, 'updated' => $updated];
}

function catalogue_csv_text(string $value): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException('The CSV contains text that could not be converted to UTF-8.');
    }

    return trim($value);
}

function catalogue_extract_size(array &$catalogue, array $ids): array
{
    foreach ($catalogue['brands'] as $brandIndex => $brand) {
        if (($brand['id'] ?? '') !== $ids['brand']) {
            continue;
        }
        foreach (($brand['ranges'] ?? []) as $rangeIndex => $range) {
            if (($range['id'] ?? '') !== $ids['range']) {
                continue;
            }
            foreach (($range['versions'] ?? []) as $versionIndex => $version) {
                if (($version['id'] ?? '') !== $ids['version']) {
                    continue;
                }
                foreach (($version['sizes'] ?? []) as $sizeIndex => $size) {
                    if (($size['id'] ?? '') !== $ids['size']) {
                        continue;
                    }

                    array_splice($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'][$versionIndex]['sizes'], $sizeIndex, 1);
                    if ($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'][$versionIndex]['sizes'] === []) {
                        array_splice($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'], $versionIndex, 1);
                    }
                    if ($catalogue['brands'][$brandIndex]['ranges'][$rangeIndex]['versions'] === []) {
                        array_splice($catalogue['brands'][$brandIndex]['ranges'], $rangeIndex, 1);
                    }
                    if ($catalogue['brands'][$brandIndex]['ranges'] === []) {
                        array_splice($catalogue['brands'], $brandIndex, 1);
                    }

                    return $size;
                }
            }
        }
    }

    throw new RuntimeException('The catalogue entry to edit was not found.');
}

function catalogue_find_or_add(array &$items, string $id, string $name, string $childrenKey): int
{
    foreach ($items as $index => &$item) {
        if (($item['id'] ?? '') === $id) {
            $item['name'] = $name;
            $item[$childrenKey] = is_array($item[$childrenKey] ?? null) ? $item[$childrenKey] : [];
            return $index;
        }
    }
    unset($item);

    $items[] = ['id' => $id, 'name' => $name, $childrenKey => []];
    return array_key_last($items);
}

function catalogue_store_uploads(array $uploads, string $destination, string $projectRoot): array
{
    $names = is_array($uploads['name'] ?? null) ? $uploads['name'] : [];
    $temporaryFiles = is_array($uploads['tmp_name'] ?? null) ? $uploads['tmp_name'] : [];
    $errors = is_array($uploads['error'] ?? null) ? $uploads['error'] : [];
    $sizes = is_array($uploads['size'] ?? null) ? $uploads['size'] : [];
    if (count($names) > 12) {
        throw new InvalidArgumentException('Upload no more than 12 images at once.');
    }

    $mimeExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $stored = [];
    try {
        foreach ($names as $index => $originalName) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('One of the catalogue images could not be uploaded.');
            }

            $temporaryFile = (string) ($temporaryFiles[$index] ?? '');
            if (!is_uploaded_file($temporaryFile) || (int) ($sizes[$index] ?? 0) > 15 * 1024 * 1024) {
                throw new InvalidArgumentException('Each catalogue image must be a valid upload no larger than 15 MB.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
            $imageInfo = @getimagesize($temporaryFile);
            if (!isset($mimeExtensions[$mime]) || $imageInfo === false) {
                throw new InvalidArgumentException('Catalogue images must be JPEG, PNG, or WebP files.');
            }

            if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException('The catalogue image directory could not be created.');
            }
            $baseName = catalogue_slug(pathinfo((string) $originalName, PATHINFO_FILENAME));
            $target = $destination . '/' . $baseName . '-' . bin2hex(random_bytes(4)) . '.' . $mimeExtensions[$mime];
            if (!move_uploaded_file($temporaryFile, $target)) {
                throw new RuntimeException('A catalogue image could not be stored.');
            }
            $stored[] = ltrim(str_replace('\\', '/', substr($target, strlen(rtrim($projectRoot, DIRECTORY_SEPARATOR)))), '/');
        }
    } catch (Throwable $exception) {
        foreach ($stored as $relativePath) {
            @unlink($projectRoot . '/' . $relativePath);
        }
        throw $exception;
    }

    return $stored;
}

function catalogue_delete_image(string $imagePath, ?string $file = null, ?string $projectRoot = null): void
{
    if (!updates_writes_enabled()) {
        throw new RuntimeException('Catalogue writing is disabled.');
    }
    if (!str_starts_with($imagePath, 'catalogue/images/') || str_contains($imagePath, '..')) {
        throw new InvalidArgumentException('The catalogue image path is invalid.');
    }

    $catalogue = catalogue_load($file);
    $found = false;
    foreach ($catalogue['brands'] as &$brand) {
        foreach ($brand['ranges'] as &$range) {
            foreach ($range['versions'] as &$version) {
                foreach ($version['sizes'] as &$size) {
                    $images = $size['images'] ?? [];
                    if (in_array($imagePath, $images, true)) {
                        $size['images'] = array_values(array_filter($images, static fn(string $path): bool => $path !== $imagePath));
                        $found = true;
                    }
                }
            }
        }
    }
    unset($brand, $range, $version, $size);
    if (!$found) {
        throw new RuntimeException('The catalogue image was not found.');
    }

    catalogue_save($catalogue, $file);
    $root = $projectRoot ?? catalogue_project_root();
    catalogue_delete_stored_file($root, $imagePath);
}

function catalogue_delete_stored_file(string $projectRoot, string $imagePath): void
{
    if (!str_starts_with($imagePath, 'catalogue/images/') || str_contains($imagePath, '..')) {
        throw new InvalidArgumentException('The catalogue image path is invalid.');
    }
    $absolutePath = $projectRoot . '/' . $imagePath;
    if (is_file($absolutePath) && !unlink($absolutePath)) {
        throw new RuntimeException('The catalogue was updated, but an old image file could not be deleted.');
    }
}