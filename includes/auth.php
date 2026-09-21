<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;

const DASHBOARD_AUTH_SESSION_SECONDS = 43200;
const DASHBOARD_AUTH_MAX_ATTEMPTS = 5;

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('dashboard_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $secure,
        'cookie_samesite' => 'Strict',
        'cookie_lifetime' => 0,
        'gc_maxlifetime' => DASHBOARD_AUTH_SESSION_SECONDS,
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

function auth_database(): PDO
{
    return project_database_connection('tileimagegen');
}

function auth_ensure_tables(?PDO $database = null): void
{
    $database ??= auth_database();
    $database->exec('CREATE TABLE IF NOT EXISTS dashboard_users (
        id CHAR(64) PRIMARY KEY,
        email VARCHAR(254) NOT NULL UNIQUE,
        display_name VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT "administrator",
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $database->exec('CREATE TABLE IF NOT EXISTS dashboard_passkeys (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id CHAR(64) NOT NULL,
        credential_id_hash CHAR(64) NOT NULL UNIQUE,
        credential_id BLOB NOT NULL,
        public_key TEXT NOT NULL,
        signature_counter BIGINT UNSIGNED NULL,
        label VARCHAR(100) NOT NULL,
        transports VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        INDEX dashboard_passkeys_user_id (user_id),
        CONSTRAINT dashboard_passkeys_user FOREIGN KEY (user_id) REFERENCES dashboard_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $database->exec('CREATE TABLE IF NOT EXISTS dashboard_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        successful TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL,
        INDEX dashboard_attempt_email_time (email_hash, attempted_at),
        INDEX dashboard_attempt_ip_time (ip_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function auth_has_users(): bool
{
    $database = auth_database();
    auth_ensure_tables($database);
    return (int) $database->query('SELECT COUNT(*) FROM dashboard_users')->fetchColumn() > 0;
}

function auth_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function auth_validate_account(string $displayName, string $email, string $password): void
{
    if (trim($displayName) === '' || mb_strlen(trim($displayName)) > 100) throw new InvalidArgumentException('Enter a name no longer than 100 characters.');
    if (!filter_var(auth_normalize_email($email), FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (strlen($password) < 12) throw new InvalidArgumentException('Use a password containing at least 12 characters.');
    if (strlen($password) > 1024) throw new InvalidArgumentException('The password is too long.');
}

function auth_insert_user(PDO $database, string $displayName, string $email, string $password): array
{
    auth_validate_account($displayName, $email, $password);
    $user = [
        'id' => bin2hex(random_bytes(32)),
        'email' => auth_normalize_email($email),
        'display_name' => trim($displayName),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
    try {
        $statement = $database->prepare('INSERT INTO dashboard_users (id, email, display_name, password_hash, created_at, updated_at) VALUES (:id, :email, :display_name, :password_hash, :created_at, :updated_at)');
        $statement->execute($user + [':updated_at' => $user['created_at']]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') throw new InvalidArgumentException('An administrator with that email already exists.', 0, $error);
        throw $error;
    }
    unset($user['password_hash']);
    $user['role'] = 'administrator';
    $user['active'] = 1;
    return $user;
}

function auth_create_user(string $displayName, string $email, string $password): array
{
    $database = auth_database();
    auth_ensure_tables($database);
    return auth_insert_user($database, $displayName, $email, $password);
}

function auth_create_initial_user(string $displayName, string $email, string $password): array
{
    $database = auth_database();
    auth_ensure_tables($database);
    if ((int) $database->query("SELECT GET_LOCK('dashboard_auth_bootstrap', 5)")->fetchColumn() !== 1) throw new RuntimeException('Administrator setup is busy. Try again.');
    try {
        if ((int) $database->query('SELECT COUNT(*) FROM dashboard_users')->fetchColumn() > 0) throw new RuntimeException('Administrator setup has already been completed.');
        return auth_insert_user($database, $displayName, $email, $password);
    } finally {
        $database->query("SELECT RELEASE_LOCK('dashboard_auth_bootstrap')");
    }
}

function auth_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function auth_rate_limited(PDO $database, string $email): bool
{
    $emailHash = hash('sha256', auth_normalize_email($email));
    $ipHash = hash('sha256', auth_client_ip());
    $statement = $database->prepare('SELECT COUNT(*) FROM dashboard_login_attempts WHERE successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (email_hash = :email_hash OR ip_hash = :ip_hash)');
    $statement->execute([':email_hash' => $emailHash, ':ip_hash' => $ipHash]);
    return (int) $statement->fetchColumn() >= DASHBOARD_AUTH_MAX_ATTEMPTS;
}

function auth_record_attempt(PDO $database, string $email, bool $successful): void
{
    $statement = $database->prepare('INSERT INTO dashboard_login_attempts (email_hash, ip_hash, successful, attempted_at) VALUES (:email_hash, :ip_hash, :successful, NOW())');
    $statement->execute([
        ':email_hash' => hash('sha256', auth_normalize_email($email)),
        ':ip_hash' => hash('sha256', auth_client_ip()),
        ':successful' => $successful ? 1 : 0,
    ]);
    if (random_int(1, 100) === 1) $database->exec('DELETE FROM dashboard_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
}

function auth_attempt_password(string $email, string $password): bool
{
    $database = auth_database();
    auth_ensure_tables($database);
    if (auth_rate_limited($database, $email)) throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.');

    $statement = $database->prepare('SELECT * FROM dashboard_users WHERE email = :email AND active = 1 LIMIT 1');
    $statement->execute([':email' => auth_normalize_email($email)]);
    $user = $statement->fetch();
    $valid = is_array($user) && password_verify($password, (string) $user['password_hash']);
    auth_record_attempt($database, $email, $valid);
    if (!$valid) return false;

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $database->prepare('UPDATE dashboard_users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
        $rehash->execute([':password_hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }
    auth_complete_login($user);
    return true;
}

function auth_complete_login(array $user): void
{
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['dashboard_user_id'] = (string) $user['id'];
    $_SESSION['dashboard_authenticated_at'] = time();
    unset($_SESSION['dashboard_user_cache']);
    $statement = auth_database()->prepare('UPDATE dashboard_users SET last_login_at = NOW() WHERE id = :id');
    $statement->execute([':id' => $user['id']]);
}

function auth_current_user(): ?array
{
    auth_start_session();
    $userId = (string) ($_SESSION['dashboard_user_id'] ?? '');
    $authenticatedAt = (int) ($_SESSION['dashboard_authenticated_at'] ?? 0);
    if ($userId === '' || $authenticatedAt < time() - DASHBOARD_AUTH_SESSION_SECONDS) return null;
    if (isset($_SESSION['dashboard_user_cache']) && is_array($_SESSION['dashboard_user_cache'])) return $_SESSION['dashboard_user_cache'];

    $database = auth_database();
    auth_ensure_tables($database);
    $statement = $database->prepare('SELECT id, email, display_name, role, active, created_at, last_login_at FROM dashboard_users WHERE id = :id AND active = 1 LIMIT 1');
    $statement->execute([':id' => $userId]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        auth_logout(false);
        return null;
    }
    $_SESSION['dashboard_user_cache'] = $user;
    return $user;
}

function auth_logout(bool $redirect = true): void
{
    auth_start_session();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    if ($redirect) {
        header('Location: /auth/login.php');
        exit;
    }
}

function auth_csrf_token(): string
{
    auth_start_session();
    if (empty($_SESSION['dashboard_auth_csrf'])) $_SESSION['dashboard_auth_csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['dashboard_auth_csrf'];
}

function auth_verify_csrf(string $token): bool
{
    return $token !== '' && hash_equals(auth_csrf_token(), $token);
}

function auth_list_users(): array
{
    $database = auth_database();
    auth_ensure_tables($database);
    return $database->query('SELECT id, email, display_name, role, active, created_at, last_login_at FROM dashboard_users ORDER BY display_name, email')->fetchAll();
}

function auth_change_password(string $userId, string $currentPassword, string $newPassword): void
{
    if (strlen($newPassword) < 12 || strlen($newPassword) > 1024) throw new InvalidArgumentException('Use a new password containing at least 12 characters.');
    $database = auth_database();
    $statement = $database->prepare('SELECT password_hash FROM dashboard_users WHERE id = :id AND active = 1 LIMIT 1');
    $statement->execute([':id' => $userId]);
    $hash = $statement->fetchColumn();
    if (!is_string($hash) || !password_verify($currentPassword, $hash)) throw new InvalidArgumentException('The current password is incorrect.');
    $update = $database->prepare('UPDATE dashboard_users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $update->execute([':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
}

function auth_rp_id(): string
{
    $rpId = strtolower(trim((string) (getenv('DASHBOARD_WEBAUTHN_RP_ID') ?: 'dash.awarner.co.uk')));
    if (preg_match('/^[a-z0-9.-]+$/', $rpId) !== 1) throw new RuntimeException('The WebAuthn relying-party ID is invalid.');
    return $rpId;
}

function auth_webauthn(): WebAuthn
{
    return new WebAuthn('Aidan Warner Dashboard', auth_rp_id(), ['none'], true);
}

function auth_base64url_decode(string $value): string
{
    return ByteBuffer::fromBase64Url($value)->getBinaryString();
}

function auth_passkeys_for_user(string $userId): array
{
    $statement = auth_database()->prepare('SELECT id, label, transports, created_at, last_used_at FROM dashboard_passkeys WHERE user_id = :user_id ORDER BY created_at DESC');
    $statement->execute([':user_id' => $userId]);
    return $statement->fetchAll();
}

function auth_passkey_registration_options(array $user): object
{
    auth_start_session();
    $database = auth_database();
    $statement = $database->prepare('SELECT credential_id FROM dashboard_passkeys WHERE user_id = :user_id');
    $statement->execute([':user_id' => $user['id']]);
    $credentialIds = array_map(static fn(array $row): string => (string) $row['credential_id'], $statement->fetchAll());
    $webauthn = auth_webauthn();
    $args = $webauthn->getCreateArgs(hex2bin((string) $user['id']), (string) $user['email'], (string) $user['display_name'], 60, true, true, null, $credentialIds);
    $_SESSION['dashboard_passkey_registration_challenge'] = base64_encode($args->publicKey->challenge->getBinaryString());
    $_SESSION['dashboard_passkey_registration_user'] = (string) $user['id'];
    return $args;
}

function auth_register_passkey(array $user, array $response, string $label): void
{
    auth_start_session();
    $challenge = base64_decode((string) ($_SESSION['dashboard_passkey_registration_challenge'] ?? ''), true);
    $challengeUser = (string) ($_SESSION['dashboard_passkey_registration_user'] ?? '');
    unset($_SESSION['dashboard_passkey_registration_challenge'], $_SESSION['dashboard_passkey_registration_user']);
    if ($challenge === false || $challenge === '' || !hash_equals((string) $user['id'], $challengeUser)) throw new RuntimeException('The passkey registration request expired.');
    if (trim($label) === '' || mb_strlen(trim($label)) > 100) throw new InvalidArgumentException('Enter a passkey name no longer than 100 characters.');

    $clientData = auth_base64url_decode((string) ($response['clientDataJSON'] ?? ''));
    $attestation = auth_base64url_decode((string) ($response['attestationObject'] ?? ''));
    $webauthn = auth_webauthn();
    $data = $webauthn->processCreate($clientData, $attestation, $challenge, true, true, false, false);
    $credentialId = (string) $data->credentialId;
    $transports = array_values(array_filter((array) ($response['transports'] ?? []), 'is_string'));
    $statement = auth_database()->prepare('INSERT INTO dashboard_passkeys (user_id, credential_id_hash, credential_id, public_key, signature_counter, label, transports, created_at) VALUES (:user_id, :credential_id_hash, :credential_id, :public_key, :signature_counter, :label, :transports, NOW())');
    $statement->bindValue(':user_id', $user['id']);
    $statement->bindValue(':credential_id_hash', hash('sha256', $credentialId));
    $statement->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
    $statement->bindValue(':public_key', (string) $data->credentialPublicKey);
    $statement->bindValue(':signature_counter', $data->signatureCounter, $data->signatureCounter === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue(':label', trim($label));
    $statement->bindValue(':transports', json_encode($transports, JSON_THROW_ON_ERROR));
    $statement->execute();
}

function auth_passkey_login_options(): object
{
    auth_start_session();
    $args = auth_webauthn()->getGetArgs([], 60, true, true, true, true, true, true);
    $_SESSION['dashboard_passkey_login_challenge'] = base64_encode($args->publicKey->challenge->getBinaryString());
    return $args;
}

function auth_attempt_passkey(array $response): bool
{
    auth_start_session();
    $challenge = base64_decode((string) ($_SESSION['dashboard_passkey_login_challenge'] ?? ''), true);
    unset($_SESSION['dashboard_passkey_login_challenge']);
    if ($challenge === false || $challenge === '') throw new RuntimeException('The passkey sign-in request expired.');

    $credentialId = auth_base64url_decode((string) ($response['rawId'] ?? ''));
    $database = auth_database();
    $statement = $database->prepare('SELECT passkey.id AS passkey_id, passkey.user_id, passkey.credential_id, passkey.public_key, passkey.signature_counter, user.id, user.email, user.display_name, user.role, user.active FROM dashboard_passkeys passkey INNER JOIN dashboard_users user ON user.id = passkey.user_id WHERE passkey.credential_id_hash = :credential_id_hash AND user.active = 1 LIMIT 1');
    $statement->execute([':credential_id_hash' => hash('sha256', $credentialId)]);
    $passkey = $statement->fetch();
    if (!is_array($passkey) || !hash_equals((string) $passkey['credential_id'], $credentialId)) return false;

    $userHandle = (string) ($response['userHandle'] ?? '');
    if ($userHandle !== '' && !hash_equals(hex2bin((string) $passkey['user_id']), auth_base64url_decode($userHandle))) return false;
    $webauthn = auth_webauthn();
    $valid = $webauthn->processGet(
        auth_base64url_decode((string) ($response['clientDataJSON'] ?? '')),
        auth_base64url_decode((string) ($response['authenticatorData'] ?? '')),
        auth_base64url_decode((string) ($response['signature'] ?? '')),
        (string) $passkey['public_key'],
        $challenge,
        $passkey['signature_counter'] === null ? null : (int) $passkey['signature_counter'],
        true,
        true
    );
    if (!$valid) return false;
    $update = $database->prepare('UPDATE dashboard_passkeys SET signature_counter = :signature_counter, last_used_at = NOW() WHERE id = :id');
    $counter = $webauthn->getSignatureCounter();
    $update->bindValue(':signature_counter', $counter, $counter === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $update->bindValue(':id', $passkey['passkey_id'], PDO::PARAM_INT);
    $update->execute();
    auth_complete_login($passkey);
    return true;
}

function auth_delete_passkey(string $userId, int $passkeyId): void
{
    $statement = auth_database()->prepare('DELETE FROM dashboard_passkeys WHERE id = :id AND user_id = :user_id');
    $statement->execute([':id' => $passkeyId, ':user_id' => $userId]);
}

function auth_safe_return_path(string $path): string
{
    return str_starts_with($path, '/') && !str_starts_with($path, '//') && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1 ? $path : '/';
}

function auth_send_security_headers(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

function auth_require(): void
{
    auth_send_security_headers();
    if (!auth_has_users()) {
        header('Location: /auth/setup.php');
        exit;
    }
    if (auth_current_user() !== null) return;
    $requestUri = auth_safe_return_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    header('Location: /auth/login.php?return=' . rawurlencode($requestUri));
    exit;
}