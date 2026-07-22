<?php
/**
 * SnackQuest — authentication service: register, verify, login, password reset,
 * account export and deletion. Enumeration-safe messages, hashed one-time tokens,
 * Argon2id (bcrypt fallback if the host lacks Argon2).
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Auth;

use SnackQuest\Config;
use SnackQuest\Database;
use SnackQuest\Support\Logger;

final class AuthService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
        private readonly Mailer $mailer,
    ) {
    }

    // ---------- Registration ----------

    /**
     * @return array{ok:bool, error:?string}
     * On success a verification mail is sent. The caller shows the same neutral
     * success message whether or not the address already existed (no enumeration).
     */
    public function register(string $email, string $password, string $displayName): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return ['ok' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse an.'];
        }
        $pwError = $this->passwordPolicyError($password);
        if ($pwError !== null) {
            return ['ok' => false, 'error' => $pwError];
        }
        $displayName = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($displayName));
        $displayName = trim((string)preg_replace('/\s+/u', ' ', $displayName));
        $displayName = mb_substr($displayName, 0, 80);
        if ($displayName === '') {
            $displayName = ucfirst(explode('@', $email)[0]);
        }

        $pdo = Database::pdo();
        $users = Database::table('users');

        $stmt = $pdo->prepare("SELECT id, email_verified_at FROM {$users} WHERE email = :e");
        $stmt->execute(['e' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Do not reveal that the account exists. If unverified, re-send the mail.
            if ($existing['email_verified_at'] === null) {
                $this->sendVerification((int)$existing['id'], $email);
            }
            $this->log->info('Register: address already known, neutral response returned');
            return ['ok' => true, 'error' => null];
        }

        $now = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            "INSERT INTO {$users} (email, password_hash, display_name, locale, timezone, country, created_at, updated_at)
             VALUES (:e, :p, :d, 'de', 'Europe/Berlin', 'DE', :c, :u)"
        );
        $ins->execute([
            'e' => $email,
            'p' => $this->hashPassword($password),
            'd' => $displayName,
            'c' => $now,
            'u' => $now,
        ]);
        $userId = (int)$pdo->lastInsertId();
        $this->log->info('User registered', ['user_id' => $userId]);
        $this->sendVerification($userId, $email);
        return ['ok' => true, 'error' => null];
    }

    public function sendVerification(int $userId, string $email): void
    {
        $token = $this->createToken($userId, 'verify', 3600 * (int)$this->config->get('auth.verification_ttl_hours', 48));
        $url = rtrim((string)$this->config->get('app_base_url'), '/') . '/verify?token=' . $token;
        $mail = MailTemplate::verification($url, (int)$this->config->get('auth.verification_ttl_hours', 48));
        $this->mailer->send(
            $email,
            'SnackQuest — E-Mail-Adresse bestätigen',
            $mail['text'],
            $mail['html']
        );
    }

    /** @return array{ok:bool, error:?string} */
    public function verifyEmail(string $token): array
    {
        $row = $this->consumeToken($token, 'verify');
        if ($row === null) {
            return ['ok' => false, 'error' => 'Dieser Bestätigungslink ist ungültig oder abgelaufen.'];
        }
        $users = Database::table('users');
        // Native MySQL/MariaDB prepares require a distinct name for every
        // placeholder occurrence, even when both columns get the same value.
        $stmt = Database::pdo()->prepare(
            "UPDATE {$users} SET email_verified_at = :verified_at, updated_at = :updated_at WHERE id = :id"
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute(['verified_at' => $now, 'updated_at' => $now, 'id' => $row['user_id']]);
        $this->log->info('E-mail verified', ['user_id' => $row['user_id']]);

        // Welcome mail (best effort)
        $u = $this->findUserById((int)$row['user_id']);
        if ($u) {
            $appUrl = rtrim((string)$this->config->get('app_base_url'), '/') . '/app';
            $mail = MailTemplate::welcome((string)$u['display_name'], $appUrl);
            $this->mailer->send(
                $u['email'],
                'Willkommen bei SnackQuest',
                $mail['text'],
                $mail['html']
            );
        }
        return ['ok' => true, 'error' => null];
    }

    // ---------- Login ----------

    /** @return array{ok:bool, user_id:?int, error:?string} */
    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $neutral = 'E-Mail-Adresse oder Passwort ist nicht korrekt.';
        $users = Database::table('users');
        $stmt = Database::pdo()->prepare(
            "SELECT id, password_hash, email_verified_at, is_active FROM {$users} WHERE email = :e"
        );
        $stmt->execute(['e' => $email]);
        $user = $stmt->fetch();

        if (!$user || $user['password_hash'] === null) {
            // burn comparable time to keep timing similar
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ['ok' => false, 'user_id' => null, 'error' => $neutral];
        }
        if (!password_verify($password, (string)$user['password_hash'])) {
            return ['ok' => false, 'user_id' => null, 'error' => $neutral];
        }
        if ((int)$user['is_active'] !== 1) {
            return ['ok' => false, 'user_id' => null, 'error' => 'Dieses Konto ist deaktiviert.'];
        }
        if ($user['email_verified_at'] === null) {
            $this->sendVerification((int)$user['id'], $email);
            return ['ok' => false, 'user_id' => null, 'error' => 'Bitte bestätige zuerst deine E-Mail-Adresse. Wir haben dir den Link gerade erneut geschickt.'];
        }
        if ($this->needsRehash((string)$user['password_hash'])) {
            $up = Database::pdo()->prepare("UPDATE {$users} SET password_hash = :p WHERE id = :id");
            $up->execute(['p' => $this->hashPassword($password), 'id' => $user['id']]);
        }
        return ['ok' => true, 'user_id' => (int)$user['id'], 'error' => null];
    }

    // ---------- Password reset ----------

    public function requestPasswordReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $users = Database::table('users');
        $stmt = Database::pdo()->prepare("SELECT id FROM {$users} WHERE email = :e AND is_active = 1");
        $stmt->execute(['e' => $email]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->log->info('Password reset requested for unknown address (neutral response)');
            return; // same outward behavior either way
        }
        $token = $this->createToken((int)$user['id'], 'reset', 60 * (int)$this->config->get('auth.reset_ttl_minutes', 60));
        $url = rtrim((string)$this->config->get('app_base_url'), '/') . '/reset-password?token=' . $token;
        $mail = MailTemplate::passwordReset($url, (int)$this->config->get('auth.reset_ttl_minutes', 60));
        $this->mailer->send(
            $email,
            'SnackQuest — Passwort zurücksetzen',
            $mail['text'],
            $mail['html']
        );
    }

    /** @return array{ok:bool, error:?string} */
    public function resetPassword(string $token, string $newPassword): array
    {
        $pwError = $this->passwordPolicyError($newPassword);
        if ($pwError !== null) {
            return ['ok' => false, 'error' => $pwError];
        }
        $row = $this->consumeToken($token, 'reset');
        if ($row === null) {
            return ['ok' => false, 'error' => 'Dieser Link ist ungültig oder abgelaufen. Fordere bitte einen neuen an.'];
        }
        $users = Database::table('users');
        $stmt = Database::pdo()->prepare("UPDATE {$users} SET password_hash = :p, updated_at = :t WHERE id = :id");
        $stmt->execute(['p' => $this->hashPassword($newPassword), 't' => gmdate('Y-m-d H:i:s'), 'id' => $row['user_id']]);
        $this->log->info('Password reset completed', ['user_id' => $row['user_id']]);
        return ['ok' => true, 'error' => null];
    }

    // ---------- Account ----------

    public function findUserById(int $id): ?array
    {
        $users = Database::table('users');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$users} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    /** Full personal data export as array (JSON-serializable). */
    public function exportUserData(int $userId): array
    {
        $pdo = Database::pdo();
        $out = ['exported_at' => gmdate('c'), 'format_version' => 1];
        $tables = [
            'profile' => [Database::table('users'), 'id', 'SELECT id,email,display_name,locale,timezone,country,onboarding_completed,created_at FROM %s WHERE id=:u'],
            'preferences' => [Database::table('user_preferences'), 'user_id', 'SELECT * FROM %s WHERE user_id=:u'],
            'custom_products' => [Database::table('custom_products'), 'owner_user_id', 'SELECT id,barcode,name,brand,category,quantity,note,visibility,created_at,updated_at FROM %s WHERE owner_user_id=:u'],
            'reviews' => [Database::table('user_product_entries'), 'user_id', 'SELECT * FROM %s WHERE user_id=:u'],
            'prices' => [Database::table('price_entries'), 'user_id', 'SELECT product_key,price,currency,quantity_text,store_name_snapshot,purchased_at,created_at FROM %s WHERE user_id=:u'],
            'stores' => [Database::table('stores'), 'user_id', 'SELECT name,city,country,created_at FROM %s WHERE user_id=:u'],
            'photos' => [Database::table('review_photos'), 'user_id', 'SELECT entry_id,storage_path,width,height,mime_type,alt_text,created_at FROM %s WHERE user_id=:u'],
            'collections' => [Database::table('collections'), 'user_id', 'SELECT id,name,description,visibility,created_at,updated_at FROM %s WHERE user_id=:u'],
            'battles' => [Database::table('battle_sessions'), 'user_id', 'SELECT id,battle_type,status,created_at,completed_at FROM %s WHERE user_id=:u'],
            'rankings' => [Database::table('ranking_scores'), 'user_id', 'SELECT product_key,dimension,score,match_count,updated_at FROM %s WHERE user_id=:u'],
            'quests' => [Database::table('quests'), 'user_id', 'SELECT quest_type,title,status,progress,target,starts_at,ends_at,completed_at FROM %s WHERE user_id=:u'],
            'shares' => [Database::table('shares'), 'user_id', 'SELECT share_type,resource_id,payload,created_at,revoked_at FROM %s WHERE user_id=:u'],
            'ai_insights' => [Database::table('ai_insights'), 'user_id', 'SELECT insight_type,result,model,created_at,expires_at FROM %s WHERE user_id=:u'],
            'audit_events' => [Database::table('audit_events'), 'user_id', 'SELECT event_type,created_at FROM %s WHERE user_id=:u'],
        ];
        foreach ($tables as $key => [$table, , $sql]) {
            $stmt = $pdo->prepare(sprintf($sql, $table));
            $stmt->execute(['u' => $userId]);
            $rows = $stmt->fetchAll();
            $out[$key] = $key === 'profile' ? ($rows[0] ?? null) : $rows;
        }
        $collectionItems = Database::table('collection_items');
        $collections = Database::table('collections');
        $stmt = $pdo->prepare(
            "SELECT ci.collection_id, ci.product_key, ci.product_name, ci.position, ci.added_at
             FROM {$collectionItems} ci
             JOIN {$collections} c ON c.id = ci.collection_id
             WHERE c.user_id = :u
             ORDER BY ci.collection_id, ci.position, ci.id"
        );
        $stmt->execute(['u' => $userId]);
        $out['collection_items'] = $stmt->fetchAll();

        $entries = Database::table('user_product_entries');
        $entryTags = Database::table('user_product_tags');
        $tags = Database::table('taste_tags');
        $stmt = $pdo->prepare(
            "SELECT et.entry_id, t.slug, t.label, t.category
             FROM {$entryTags} et
             JOIN {$entries} e ON e.id = et.entry_id
             JOIN {$tags} t ON t.id = et.tag_id
             WHERE e.user_id = :u
             ORDER BY et.entry_id, t.category, t.label"
        );
        $stmt->execute(['u' => $userId]);
        $out['review_tags'] = $stmt->fetchAll();

        $sessions = Database::table('battle_sessions');
        $pairs = Database::table('battle_pairs');
        $stmt = $pdo->prepare(
            "SELECT bp.battle_session_id,bp.left_product_key,bp.right_product_key,bp.winner_product_key,
                    bp.selection_reason,bp.created_at
             FROM {$pairs} bp
             JOIN {$sessions} bs ON bs.id = bp.battle_session_id
             WHERE bs.user_id = :u
             ORDER BY bp.id"
        );
        $stmt->execute(['u' => $userId]);
        $out['battle_pairs'] = $stmt->fetchAll();
        return $out;
    }

    /** Permanently delete the account and all personal data (FK cascades). */
    public function deleteAccount(int $userId): bool
    {
        $users = Database::table('users');
        $stmt = Database::pdo()->prepare("DELETE FROM {$users} WHERE id = :id");
        $ok = $stmt->execute(['id' => $userId]);
        $this->log->info('Account deleted', ['user_id' => $userId]);
        return $ok;
    }

    // ---------- Internals ----------

    public function passwordPolicyError(string $password): ?string
    {
        $min = (int)$this->config->get('auth.min_password_length', 10);
        if (mb_strlen($password) < $min) {
            return "Das Passwort muss mindestens {$min} Zeichen lang sein.";
        }
        if (mb_strlen($password) > 200) {
            return 'Das Passwort ist zu lang.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Das Passwort muss Buchstaben und mindestens eine Zahl enthalten.';
        }
        return null;
    }

    public function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }

    private function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_needs_rehash($hash, $algo);
    }

    /** Create a one-time token; only its SHA-256 hash is stored. */
    private function createToken(int $userId, string $type, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $tokens = Database::table('auth_tokens');
        $stmt = Database::pdo()->prepare(
            "INSERT INTO {$tokens} (user_id, token_type, token_hash, expires_at, created_at)
             VALUES (:u, :t, :h, :e, :c)"
        );
        $stmt->execute([
            'u' => $userId,
            't' => $type,
            'h' => hash('sha256', $token),
            'e' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'c' => gmdate('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** Validate + mark a token used. Returns the token row or null. */
    private function consumeToken(string $token, string $type): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $tokens = Database::table('auth_tokens');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, user_id, expires_at, used_at FROM {$tokens}
             WHERE token_type = :t AND token_hash = :h"
        );
        $stmt->execute(['t' => $type, 'h' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null || $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            return null;
        }
        $up = $pdo->prepare("UPDATE {$tokens} SET used_at = :now WHERE id = :id AND used_at IS NULL");
        $up->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
        if ($up->rowCount() !== 1) {
            return null; // raced: someone else consumed it first
        }
        return $row;
    }
}
