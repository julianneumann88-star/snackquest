<?php
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Config;
use SnackQuest\Database;
use SnackQuest\Http\RateLimiter;
use SnackQuest\Support\HttpClient;
use SnackQuest\Support\Logger;

final class OpenFoodFactsService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
        private readonly HttpClient $http,
    ) {
    }

    /** @return array{status:string,product:?array,cached:bool,error:?string} */
    public function find(string $rawBarcode, bool $refresh = false): array
    {
        $barcode = BarcodeService::normalize($rawBarcode);
        if (!BarcodeService::validate($barcode)) {
            return ['status' => 'invalid', 'product' => null, 'cached' => false, 'error' => 'Der Barcode ist formal ungültig.'];
        }

        $table = Database::table('product_cache');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE barcode = :b");
        $stmt->execute(['b' => $barcode]);
        $cached = $stmt->fetch();
        if (!$refresh && $cached && (string)$cached['expires_at'] > gmdate('Y-m-d H:i:s')) {
            $product = $cached['product_json'] ? json_decode((string)$cached['product_json'], true) : null;
            return ['status' => (string)$cached['fetch_status'], 'product' => is_array($product) ? $product : null, 'cached' => true, 'error' => null];
        }

        try {
            if (!RateLimiter::allow('off:global', 13, 60)) {
                if ($cached && $cached['product_json']) {
                    $product = json_decode((string)$cached['product_json'], true);
                    return ['status' => 'stale', 'product' => is_array($product) ? $product : null, 'cached' => true, 'error' => 'Produktquelle ist gerade ausgelastet.'];
                }
                return ['status' => 'rate_limited', 'product' => null, 'cached' => false, 'error' => 'Zu viele Produktabfragen. Bitte versuche es gleich noch einmal.'];
            }
        } catch (\Throwable $e) {
            $this->log->warning('OFF rate limiter unavailable', ['type' => get_class($e)]);
        }

        $base = rtrim((string)$this->config->get('open_food_facts.base_url', 'https://world.openfoodfacts.org'), '/');
        $fields = implode(',', [
            'code','product_name','product_name_de','abbreviated_product_name','brands','quantity','categories','countries','labels','lang',
            'image_front_url','image_front_small_url','image_front_thumb_url','ingredients_text','ingredients_text_de','allergens','traces',
            'nutriments','nutrition_grades','nova_group','ecoscore_grade','environmental_score_grade','completeness','last_modified_t',
        ]);
        $url = $base . '/api/v3.6/product/' . rawurlencode($barcode) . '.json?fields=' . rawurlencode($fields);
        $response = $this->http->request('GET', $url, [
            'Accept' => 'application/json',
            'User-Agent' => (string)$this->config->get('open_food_facts.user_agent', 'SnackQuest/1.0 (https://julian-neumann.org/snackquest)'),
        ], null, (int)$this->config->get('open_food_facts.timeout_seconds', 9));

        if ($response['status'] === 404) {
            $this->saveCache($barcode, null, 'not_found', 900, $url);
            return ['status' => 'not_found', 'product' => null, 'cached' => false, 'error' => null];
        }
        if ($response['status'] !== 200) {
            $this->log->warning('Open Food Facts request failed', ['status' => $response['status'], 'barcode_hash' => substr(hash('sha256', $barcode), 0, 12)]);
            if ($cached && $cached['product_json']) {
                $product = json_decode((string)$cached['product_json'], true);
                return ['status' => 'stale', 'product' => is_array($product) ? $product : null, 'cached' => true, 'error' => 'Produktquelle vorübergehend nicht erreichbar.'];
            }
            return ['status' => 'unavailable', 'product' => null, 'cached' => false, 'error' => 'Produktquelle vorübergehend nicht erreichbar.'];
        }
        $json = json_decode($response['body'], true);
        $raw = is_array($json) && isset($json['product']) && is_array($json['product']) ? $json['product'] : null;
        if ($raw === null) {
            $this->saveCache($barcode, null, 'not_found', 900, $url);
            return ['status' => 'not_found', 'product' => null, 'cached' => false, 'error' => null];
        }
        $product = $this->normalize($barcode, $raw, $url);
        $this->saveCache($barcode, $product, 'ok', (int)$this->config->get('open_food_facts.cache_ttl_seconds', 604800), $url);
        return ['status' => 'ok', 'product' => $product, 'cached' => false, 'error' => null];
    }

    public function normalize(string $barcode, array $raw, string $sourceUrl = ''): array
    {
        $pick = static function (array $keys) use ($raw): ?string {
            foreach ($keys as $key) {
                if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                    return trim($raw[$key]);
                }
            }
            return null;
        };
        $nutriments = isset($raw['nutriments']) && is_array($raw['nutriments']) ? $raw['nutriments'] : [];
        $modified = isset($raw['last_modified_t']) && is_numeric($raw['last_modified_t']) ? gmdate('c', (int)$raw['last_modified_t']) : null;
        return [
            'key' => 'off:' . $barcode,
            'barcode' => $barcode,
            'name' => $pick(['product_name_de','product_name','abbreviated_product_name']) ?? 'Unbenanntes Produkt',
            'original_name' => $pick(['product_name']),
            'brand' => $pick(['brands']),
            'quantity' => $pick(['quantity']),
            'categories' => $pick(['categories']),
            'countries' => $pick(['countries']),
            'labels' => $pick(['labels']),
            'language' => $pick(['lang']),
            'image' => $pick(['image_front_url','image_front_small_url','image_front_thumb_url']),
            'image_small' => $pick(['image_front_small_url','image_front_thumb_url','image_front_url']),
            'ingredients' => $pick(['ingredients_text_de','ingredients_text']),
            'allergens' => $pick(['allergens']),
            'traces' => $pick(['traces']),
            'nutrition_grade' => $pick(['nutrition_grades']),
            'nova_group' => isset($raw['nova_group']) ? (int)$raw['nova_group'] : null,
            'eco_score' => $pick(['environmental_score_grade','ecoscore_grade']),
            'nutriments' => array_intersect_key($nutriments, array_flip(['energy-kcal_100g','fat_100g','saturated-fat_100g','carbohydrates_100g','sugars_100g','proteins_100g','salt_100g'])),
            'completeness' => isset($raw['completeness']) && is_numeric($raw['completeness']) ? round((float)$raw['completeness'] * 100) : null,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : 'https://world.openfoodfacts.org/product/' . $barcode,
            'source_updated_at' => $modified,
            'fetched_at' => gmdate('c'),
        ];
    }

    private function saveCache(string $barcode, ?array $product, string $status, int $ttl, string $url): void
    {
        $table = Database::table('product_cache');
        $payload = $product === null ? null : json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $params = ['b'=>$barcode,'j'=>$payload,'u'=>$url,'f'=>gmdate('Y-m-d H:i:s'),'e'=>gmdate('Y-m-d H:i:s', time() + max(60, $ttl)),'s'=>$status];
        $sql = Database::driver() === 'sqlite'
            ? "INSERT INTO {$table}(barcode,product_json,source_version,source_url,last_fetched_at,expires_at,fetch_status) VALUES(:b,:j,'v3.6',:u,:f,:e,:s) ON CONFLICT(barcode) DO UPDATE SET product_json=:j,source_url=:u,last_fetched_at=:f,expires_at=:e,fetch_status=:s"
            : "INSERT INTO {$table}(barcode,product_json,source_version,source_url,last_fetched_at,expires_at,fetch_status) VALUES(:b,:j,'v3.6',:u,:f,:e,:s) ON DUPLICATE KEY UPDATE product_json=VALUES(product_json),source_url=VALUES(source_url),last_fetched_at=VALUES(last_fetched_at),expires_at=VALUES(expires_at),fetch_status=VALUES(fetch_status)";
        Database::pdo()->prepare($sql)->execute($params);
    }
}
