<?php
/**
 * Optional, privacy-minimised taste insight via the owner's local GPT-OSS bridge.
 * Only aggregate ratings are sent; never identity, notes, photos or prices.
 */
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Config;
use SnackQuest\Database;
use SnackQuest\Support\HttpClient;
use SnackQuest\Support\Logger;

final class AiInsightService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
        private readonly HttpClient $http,
    ) {}

    public function available(): bool
    {
        return (bool)$this->config->get('ai.enabled', false)
            && trim((string)$this->config->get('ai.base_url', '')) !== ''
            && trim((string)$this->config->get('ai.api_key', '')) !== '';
    }

    public function optedIn(int $userId): bool
    {
        $table = Database::table('user_preferences');
        $stmt = Database::pdo()->prepare("SELECT ai_opt_in FROM {$table} WHERE user_id=:u");
        $stmt->execute(['u' => $userId]);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    public function latest(int $userId): ?array
    {
        $table = Database::table('ai_insights');
        $stmt = Database::pdo()->prepare(
            "SELECT result,model,created_at,expires_at FROM {$table} "
            . "WHERE user_id=:u AND insight_type='taste_summary' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $profile Aggregated taste profile. */
    public function generate(int $userId, array $profile): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('Die lokale KI-Verbindung ist derzeit nicht eingerichtet.');
        }
        if (!$this->optedIn($userId)) {
            throw new \RuntimeException('Aktiviere die lokale KI zuerst ausdrücklich in den Einstellungen.');
        }
        if ((int)($profile['count'] ?? 0) < 3) {
            throw new \RuntimeException('Für eine sinnvolle Auswertung brauchst du mindestens drei Bewertungen.');
        }

        $structured = [
            'review_count' => (int)$profile['count'],
            'average_rating' => (float)$profile['average'],
            'buy_again_rate_percent' => (int)$profile['buy_again_rate'],
            'top_categories' => array_slice((array)$profile['categories'], 0, 5),
            'top_brands' => array_slice((array)$profile['brands'], 0, 5),
            'frequent_taste_tags' => array_slice((array)$profile['tags'], 0, 8),
        ];
        $encoded = json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);
        $cached = $this->freshByHash($userId, $hash);
        if ($cached !== null) {
            return $cached;
        }

        $model = (string)$this->config->get('ai.model', 'local/gpt-oss-20b');
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Du formulierst ein privates Snack-Geschmacksprofil. Nutze nur die gelieferten aggregierten Daten. Schreibe auf Deutsch, freundlich und konkret, höchstens 110 Wörter. Keine Gesundheitsbehauptungen, Diagnosen, erfundenen Fakten oder Kaufempfehlungen außerhalb der Daten.'],
                ['role' => 'user', 'content' => "Fasse meine erkennbaren Geschmacksmuster zusammen und nenne eine vorsichtig formulierte Entdeckungsidee. Aggregierte Daten:\n" . $encoded],
            ],
            'temperature' => 0.2,
            'max_tokens' => 220,
            'stream' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $base = rtrim((string)$this->config->get('ai.base_url'), '/');
        $response = $this->http->request('POST', $base . '/chat/completions', [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . (string)$this->config->get('ai.api_key'),
            'Content-Type' => 'application/json',
        ], $payload, (int)$this->config->get('ai.timeout_seconds', 30));

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->log->warning('Local AI insight request failed', ['status' => $response['status'], 'error' => $response['error']]);
            throw new \RuntimeException('Die lokale KI ist gerade nicht erreichbar. Deine App-Daten bleiben unverändert.');
        }
        $decoded = json_decode($response['body'], true);
        $result = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
        if ($result === '' || mb_strlen($result) > 3000) {
            throw new \RuntimeException('Die lokale KI hat keine verwertbare Antwort geliefert.');
        }

        $table = Database::table('ai_insights');
        Database::pdo()->prepare(
            "INSERT INTO {$table}(user_id,insight_type,structured_input_hash,result,model,created_at,expires_at) "
            . 'VALUES(:u,:type,:hash,:result,:model,:created,:expires)'
        )->execute([
            'u' => $userId, 'type' => 'taste_summary', 'hash' => $hash, 'result' => $result,
            'model' => $model, 'created' => gmdate('Y-m-d H:i:s'),
            'expires' => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
        return $result;
    }

    private function freshByHash(int $userId, string $hash): ?string
    {
        $table = Database::table('ai_insights');
        $stmt = Database::pdo()->prepare(
            "SELECT result FROM {$table} WHERE user_id=:u AND insight_type='taste_summary' "
            . 'AND structured_input_hash=:hash AND (expires_at IS NULL OR expires_at>:now) ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'hash' => $hash, 'now' => gmdate('Y-m-d H:i:s')]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }
}
