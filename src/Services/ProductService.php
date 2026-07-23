<?php
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Database;

final class ProductService
{
    public function dashboard(int $userId): array
    {
        $entries = Database::table('user_product_entries');
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) total, COALESCE(AVG(overall_rating),0) avg_rating, SUM(favorite) favorites, SUM(buy_again='yes') buy_again FROM {$entries} WHERE user_id=:u");
        $stmt->execute(['u' => $userId]);
        $stats = $stmt->fetch() ?: ['total'=>0,'avg_rating'=>0,'favorites'=>0,'buy_again'=>0];
        return ['stats' => $stats, 'recent' => $this->list($userId, ['sort' => 'recent', 'limit' => 6])];
    }

    /**
     * Pick a personal, positively rated snack without immediately repeating recent
     * picks. This stays fully local and deterministic in its eligibility rules;
     * only the final weighted draw is random.
     *
     * @param list<string> $excludedProductKeys
     */
    public function pickForUser(int $userId, array $excludedProductKeys = []): ?array
    {
        $entries = array_values(array_filter(
            $this->list($userId, ['sort' => 'rating', 'limit' => 200]),
            static fn(array $entry): bool =>
                empty($entry['never_again'])
                && ($entry['buy_again'] ?? 'maybe') !== 'no'
                && (int)($entry['overall_rating'] ?? 0) >= 5
        ));
        if ($entries === []) {
            return null;
        }

        $recentKeys = array_slice(array_values(array_filter(
            $excludedProductKeys,
            static fn(mixed $key): bool => is_string($key) && $key !== ''
        )), -8);
        $excluded = array_fill_keys($recentKeys, true);
        $fresh = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => !isset($excluded[(string)$entry['product_key']])
        ));
        if ($fresh !== []) {
            $entries = $fresh;
        } elseif (count($entries) > 1 && $recentKeys !== []) {
            $lastKey = (string)$recentKeys[array_key_last($recentKeys)];
            $withoutImmediateRepeat = array_values(array_filter(
                $entries,
                static fn(array $entry): bool => (string)$entry['product_key'] !== $lastKey
            ));
            if ($withoutImmediateRepeat !== []) {
                $entries = $withoutImmediateRepeat;
            }
        }

        $weighted = [];
        $totalWeight = 0;
        foreach ($entries as $entry) {
            $rating = max(1, min(10, (int)($entry['overall_rating'] ?? 1)));
            $weight = ($rating * $rating)
                + (!empty($entry['favorite']) ? 24 : 0)
                + (($entry['buy_again'] ?? '') === 'yes' ? 18 : 4)
                + (!empty($entry['movie_night']) ? 4 : 0);
            $totalWeight += $weight;
            $weighted[] = ['entry' => $entry, 'ceiling' => $totalWeight];
        }

        $draw = random_int(1, max(1, $totalWeight));
        $picked = $weighted[array_key_last($weighted)]['entry'];
        foreach ($weighted as $candidate) {
            if ($draw <= $candidate['ceiling']) {
                $picked = $candidate['entry'];
                break;
            }
        }

        $picked['pick_reason'] = $this->pickReason($picked);
        return $picked;
    }

    public function list(int $userId, array $filters = []): array
    {
        $table = Database::table('user_product_entries');
        $where = ['user_id = :u'];
        $params = ['u' => $userId];
        if (!empty($filters['q'])) {
            $where[] = '(product_name LIKE :q OR brand LIKE :q OR barcode LIKE :q)';
            $params['q'] = '%' . mb_substr(trim((string)$filters['q']), 0, 100) . '%';
        }
        if (!empty($filters['favorite'])) $where[] = 'favorite = 1';
        if (($filters['buy_again'] ?? '') === 'yes') $where[] = "buy_again = 'yes'";
        if (!empty($filters['never_again'])) $where[] = 'never_again = 1';
        if (!empty($filters['movie_night'])) $where[] = 'movie_night = 1';
        if (isset($filters['min_rating']) && is_numeric($filters['min_rating'])) {
            $where[] = 'overall_rating >= :min_rating';
            $params['min_rating'] = max(1, min(10, (int)$filters['min_rating']));
        }
        $sort = match ($filters['sort'] ?? 'recent') {
            'rating' => 'overall_rating DESC, updated_at DESC',
            'name' => 'product_name ASC, updated_at DESC',
            'oldest' => 'updated_at ASC',
            default => 'updated_at DESC',
        };
        $limit = max(1, min(200, (int)($filters['limit'] ?? 60)));
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$sort} LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findEntry(int $userId, int $id): ?array
    {
        $table = Database::table('user_product_entries');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND user_id=:u");
        $stmt->execute(['id'=>$id,'u'=>$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findEntryByKey(int $userId, string $key): ?array
    {
        $table = Database::table('user_product_entries');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE user_id=:u AND product_key=:k");
        $stmt->execute(['u'=>$userId,'k'=>$key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function customProduct(int $userId, int $id): ?array
    {
        $table = Database::table('custom_products');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND owner_user_id=:u");
        $stmt->execute(['id'=>$id,'u'=>$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return [
            'key'=>'custom:' . $row['id'],'custom_id'=>(int)$row['id'],'barcode'=>$row['barcode'],'name'=>$row['name'],
            'brand'=>$row['brand'],'categories'=>$row['category'],'quantity'=>$row['quantity'],'image'=>$row['image_path'] ? '/media/custom/' . $row['id'] : null,
            'ingredients'=>null,'allergens'=>null,'traces'=>null,'source_url'=>null,
        ];
    }

    public function createCustom(int $userId, array $input, string $imagePath = ''): array
    {
        $name = $this->clean($input['name'] ?? '', 240);
        if ($name === '') throw new \InvalidArgumentException('Bitte gib einen Produktnamen an.');
        $barcode = BarcodeService::normalize((string)($input['barcode'] ?? ''));
        if ($barcode !== '' && !BarcodeService::validate($barcode)) throw new \InvalidArgumentException('Der Barcode ist ungültig.');
        $table = Database::table('custom_products');
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::pdo()->prepare("INSERT INTO {$table}(owner_user_id,barcode,name,brand,category,quantity,image_path,note,visibility,created_at,updated_at) VALUES(:u,:b,:n,:br,:c,:q,:i,:note,'private',:t,:t2)");
        $stmt->execute(['u'=>$userId,'b'=>$barcode !== '' ? $barcode : null,'n'=>$name,'br'=>$this->clean($input['brand'] ?? '',160),'c'=>$this->clean($input['category'] ?? '',160),'q'=>$this->clean($input['quantity'] ?? '',80),'i'=>$imagePath,'note'=>$this->clean($input['note'] ?? '',1200),'t'=>$now,'t2'=>$now]);
        return $this->customProduct($userId, (int)Database::pdo()->lastInsertId()) ?? [];
    }

    public function saveReview(int $userId, array $product, array $input): int
    {
        $rating = $this->rating($input['overall_rating'] ?? null, true);
        $buyAgain = in_array(($input['buy_again'] ?? ''), ['yes','maybe','no'], true) ? $input['buy_again'] : 'maybe';
        $key = $this->clean($product['key'] ?? '', 80);
        $name = $this->clean($product['name'] ?? '', 240);
        if ($key === '' || $name === '') throw new \InvalidArgumentException('Produktdaten sind unvollständig.');
        $table = Database::table('user_product_entries');
        $sql = Database::driver() === 'sqlite'
            ? "INSERT INTO {$table}(user_id,product_key,barcode,custom_product_id,product_name,brand,category,image_url,overall_rating,taste_rating,texture_rating,value_rating,packaging_rating,portion_rating,buy_again,favorite,never_again,movie_night,note,first_tried_at,last_tried_at,created_at,updated_at) VALUES(:u,:k,:b,:cid,:n,:brand,:cat,:img,:o,:taste,:texture,:value,:pack,:portion,:ba,:fav,:never,:movie,:note,:t,:t2,:t3,:t4) ON CONFLICT(user_id,product_key) DO UPDATE SET product_name=:n,brand=:brand,category=:cat,image_url=:img,overall_rating=:o,taste_rating=:taste,texture_rating=:texture,value_rating=:value,packaging_rating=:pack,portion_rating=:portion,buy_again=:ba,favorite=:fav,never_again=:never,movie_night=:movie,note=:note,last_tried_at=:t2,updated_at=:t4"
            : "INSERT INTO {$table}(user_id,product_key,barcode,custom_product_id,product_name,brand,category,image_url,overall_rating,taste_rating,texture_rating,value_rating,packaging_rating,portion_rating,buy_again,favorite,never_again,movie_night,note,first_tried_at,last_tried_at,created_at,updated_at) VALUES(:u,:k,:b,:cid,:n,:brand,:cat,:img,:o,:taste,:texture,:value,:pack,:portion,:ba,:fav,:never,:movie,:note,:t,:t2,:t3,:t4) ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),brand=VALUES(brand),category=VALUES(category),image_url=VALUES(image_url),overall_rating=VALUES(overall_rating),taste_rating=VALUES(taste_rating),texture_rating=VALUES(texture_rating),value_rating=VALUES(value_rating),packaging_rating=VALUES(packaging_rating),portion_rating=VALUES(portion_rating),buy_again=VALUES(buy_again),favorite=VALUES(favorite),never_again=VALUES(never_again),movie_night=VALUES(movie_night),note=VALUES(note),last_tried_at=VALUES(last_tried_at),updated_at=VALUES(updated_at)";
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($ownsTransaction) {
                $this->acquireSyncLock($userId, $key);
            }
            // The per-product lock and transaction must precede the revision read.
            // Offline sync already owns this same lock before calling saveReview().
            $previous = $this->findEntryByKey($userId, $key);
            $nowEpoch = time();
            if ($previous !== null) {
                $nowEpoch = max($nowEpoch, self::parseDatabaseUtc((string)$previous['updated_at']) + 1);
            }
            $now = gmdate('Y-m-d H:i:s', $nowEpoch);
            $params = [
                'u'=>$userId,'k'=>$key,'b'=>($product['barcode'] ?? '') !== '' ? $product['barcode'] : null,
                'cid'=>isset($product['custom_id']) ? (int)$product['custom_id'] : null,'n'=>$name,'brand'=>$this->clean($product['brand'] ?? '',160),
                'cat'=>$this->clean($product['categories'] ?? ($product['category'] ?? ''),160),'img'=>$this->clean($product['image_small'] ?? ($product['image'] ?? ''),700),
                'o'=>$rating,'taste'=>$this->rating($input['taste_rating'] ?? null),'texture'=>$this->rating($input['texture_rating'] ?? null),
                'value'=>$this->rating($input['value_rating'] ?? null),'pack'=>$this->rating($input['packaging_rating'] ?? null),'portion'=>$this->rating($input['portion_rating'] ?? null),
                'ba'=>$buyAgain,'fav'=>isset($input['favorite']) ? 1 : 0,'never'=>($buyAgain === 'no' || isset($input['never_again'])) ? 1 : 0,
                'movie'=>isset($input['movie_night']) ? 1 : 0,'note'=>$this->clean($input['note'] ?? '',3000),'t'=>$now,'t2'=>$now,'t3'=>$now,'t4'=>$now,
            ];
            $pdo->prepare($sql)->execute($params);
            $entry = $this->findEntryByKey($userId, $key);
            if (!$entry) throw new \RuntimeException('Bewertung konnte nicht geladen werden.');
            $this->replaceTags((int)$entry['id'], is_array($input['tags'] ?? null) ? $input['tags'] : []);
            if (($input['price'] ?? '') !== '') {
                $this->addPrice($userId, $key, $input);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return (int)$entry['id'];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Persist an offline review exactly once across sessions and concurrent retries.
     *
     * Review, tags, optional price and receipt share one transaction. If two
     * requests race with the same UUID, the unique receipt constraint rolls the
     * losing transaction (including its price row) back.
     *
     * @return array{entry_id:int,duplicate:bool,server_updated_at:int}
     */
    public function syncReview(
        int $userId,
        array $product,
        array $input,
        string $syncId,
        int $baseUpdatedAt
    ): array {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $syncId) !== 1) {
            throw new \InvalidArgumentException('Die Synchronisations-ID ist ungültig.');
        }
        if ($baseUpdatedAt < 0) {
            throw new \InvalidArgumentException('Die Basisversion ist ungültig.');
        }

        $pdo = Database::pdo();
        $receipts = Database::table('sync_receipts');
        $key = $this->clean($product['key'] ?? '', 80);
        if ($key === '') {
            throw new \InvalidArgumentException('Produktdaten sind unvollständig.');
        }

        $pdo->beginTransaction();
        try {
            $this->acquireSyncLock($userId, $key);
            $existingReceipt = $this->syncReceipt($userId, $syncId);
            if ($existingReceipt !== null) {
                $entry = $this->findEntry($userId, (int)$existingReceipt['entry_id']);
                if ($entry === null) {
                    throw new \RuntimeException('Synchronisierte Bewertung wurde nicht gefunden.');
                }
                $pdo->commit();
                return [
                    'entry_id'=>(int)$existingReceipt['entry_id'],
                    'duplicate'=>true,
                    'server_updated_at'=>self::parseDatabaseUtc((string)$entry['updated_at']),
                ];
            }

            $serverEntry = $this->findEntryByKey($userId, $key);
            if ($serverEntry !== null) {
                $serverUpdatedAt = self::parseDatabaseUtc((string)$serverEntry['updated_at']);
                if ($serverUpdatedAt > $baseUpdatedAt) {
                    throw new ReviewConflictException($serverUpdatedAt);
                }
            }

            $entryId = $this->saveReview($userId, $product, $input);
            $now = gmdate('Y-m-d H:i:s');
            $insert = $pdo->prepare(
                "INSERT INTO {$receipts}(user_id,sync_id,entry_id,created_at,completed_at) "
                . "VALUES(:u,:sync,:entry,:created,:completed)"
            );
            $insert->execute([
                'u'=>$userId,
                'sync'=>$syncId,
                'entry'=>$entryId,
                'created'=>$now,
                'completed'=>$now,
            ]);
            $savedEntry = $this->findEntry($userId, $entryId);
            if ($savedEntry === null) {
                throw new \RuntimeException('Synchronisierte Bewertung wurde nicht gefunden.');
            }
            $serverUpdatedAt = self::parseDatabaseUtc((string)$savedEntry['updated_at']);
            $pdo->commit();
            return [
                'entry_id'=>$entryId,
                'duplicate'=>false,
                'server_updated_at'=>$serverUpdatedAt,
            ];
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isUniqueViolation($e)) {
                $receipt = $this->syncReceipt($userId, $syncId);
                if ($receipt !== null) {
                    $entry = $this->findEntry($userId, (int)$receipt['entry_id']);
                    if ($entry !== null) {
                        return [
                            'entry_id'=>(int)$receipt['entry_id'],
                            'duplicate'=>true,
                            'server_updated_at'=>self::parseDatabaseUtc((string)$entry['updated_at']),
                        ];
                    }
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resolve a committed receipt before any optional external product lookup.
     * This makes a lost-response retry succeed even while Open Food Facts is
     * temporarily unavailable.
     *
     * @return array{entry_id:int,duplicate:true,server_updated_at:int}|null
     */
    public function completedSync(int $userId, string $syncId): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $syncId) !== 1) {
            throw new \InvalidArgumentException('Die Synchronisations-ID ist ungültig.');
        }
        $receipt = $this->syncReceipt($userId, $syncId);
        if ($receipt === null) {
            return null;
        }
        $entry = $this->findEntry($userId, (int)$receipt['entry_id']);
        if ($entry === null) {
            throw new \RuntimeException('Synchronisierte Bewertung wurde nicht gefunden.');
        }
        return [
            'entry_id'=>(int)$receipt['entry_id'],
            'duplicate'=>true,
            'server_updated_at'=>self::parseDatabaseUtc((string)$entry['updated_at']),
        ];
    }

    /**
     * Parse MariaDB/SQLite DATETIME values as UTC, independent of PHP's local
     * timezone. Invalid database values fail closed instead of weakening conflict
     * detection.
     */
    public static function parseDatabaseUtc(string $value): int
    {
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), $utc);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        ) {
            throw new \RuntimeException('Ungültiger UTC-Zeitstempel in der Datenbank.');
        }
        return $date->getTimestamp();
    }

    private function syncReceipt(int $userId, string $syncId): ?array
    {
        $table = Database::table('sync_receipts');
        $stmt = Database::pdo()->prepare(
            "SELECT entry_id,completed_at FROM {$table} WHERE user_id=:u AND sync_id=:sync LIMIT 1"
        );
        $stmt->execute(['u'=>$userId, 'sync'=>$syncId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Upserting this narrow row obtains an exclusive transaction lock before the
     * server revision is inspected. That prevents two different sync UUIDs with
     * the same base revision from both passing the conflict check.
     */
    private function acquireSyncLock(int $userId, string $productKey): void
    {
        $table = Database::table('sync_locks');
        $params = [
            'u'=>$userId,
            'k'=>$productKey,
            't'=>gmdate('Y-m-d H:i:s'),
        ];
        $sql = Database::driver() === 'sqlite'
            ? "INSERT INTO {$table}(user_id,product_key,touched_at) VALUES(:u,:k,:t) "
                . "ON CONFLICT(user_id,product_key) DO UPDATE SET touched_at=:t"
            : "INSERT INTO {$table}(user_id,product_key,touched_at) VALUES(:u,:k,:t) "
                . "ON DUPLICATE KEY UPDATE touched_at=VALUES(touched_at)";
        Database::pdo()->prepare($sql)->execute($params);
    }

    private function isUniqueViolation(\PDOException $error): bool
    {
        $sqlState = (string)$error->getCode();
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        return $sqlState === '23000'
            || $sqlState === '19'
            || $driverCode === 1062
            || $driverCode === 19;
    }

    public function tagsForEntry(int $entryId): array
    {
        $map = Database::table('user_product_tags');
        $tags = Database::table('taste_tags');
        $stmt = Database::pdo()->prepare("SELECT t.slug,t.label,t.category FROM {$map} m JOIN {$tags} t ON t.id=m.tag_id WHERE m.entry_id=:e ORDER BY t.category,t.label");
        $stmt->execute(['e'=>$entryId]);
        return $stmt->fetchAll();
    }

    public function allTags(): array
    {
        $table = Database::table('taste_tags');
        return Database::pdo()->query("SELECT slug,label,category FROM {$table} ORDER BY category,label")->fetchAll();
    }

    private function replaceTags(int $entryId, array $slugs): void
    {
        $slugs = array_slice(array_values(array_unique(array_filter($slugs, fn($v) => is_string($v) && preg_match('/^[a-z0-9-]{2,48}$/', $v)))), 0, 12);
        $map = Database::table('user_product_tags');
        $tags = Database::table('taste_tags');
        Database::pdo()->prepare("DELETE FROM {$map} WHERE entry_id=:e")->execute(['e'=>$entryId]);
        if ($slugs === []) return;
        $find = Database::pdo()->prepare("SELECT id FROM {$tags} WHERE slug=:s");
        $ins = Database::pdo()->prepare("INSERT INTO {$map}(entry_id,tag_id) VALUES(:e,:t)");
        foreach ($slugs as $slug) {
            $find->execute(['s'=>$slug]);
            $id = $find->fetchColumn();
            if ($id !== false) $ins->execute(['e'=>$entryId,'t'=>$id]);
        }
    }

    private function addPrice(int $userId, string $key, array $input): void
    {
        $raw = str_replace(',', '.', trim((string)$input['price']));
        if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 99999.99) throw new \InvalidArgumentException('Bitte gib einen gültigen Preis an.');
        $storeName = $this->clean($input['store_name'] ?? '',160);
        $storeId = null;
        if ($storeName !== '') {
            $stores = Database::table('stores');
            $sql = Database::driver() === 'sqlite'
                ? "INSERT INTO {$stores}(user_id,name,city,country,created_at) VALUES(:u,:n,'','DE',:t) ON CONFLICT(user_id,name,city) DO NOTHING"
                : "INSERT INTO {$stores}(user_id,name,city,country,created_at) VALUES(:u,:n,'','DE',:t) ON DUPLICATE KEY UPDATE name=VALUES(name)";
            Database::pdo()->prepare($sql)->execute(['u'=>$userId,'n'=>$storeName,'t'=>gmdate('Y-m-d H:i:s')]);
            $stmt = Database::pdo()->prepare("SELECT id FROM {$stores} WHERE user_id=:u AND name=:n AND city='' LIMIT 1");
            $stmt->execute(['u'=>$userId,'n'=>$storeName]);
            $storeId = $stmt->fetchColumn() ?: null;
        }
        $date = (string)($input['purchased_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = gmdate('Y-m-d');
        $prices = Database::table('price_entries');
        Database::pdo()->prepare("INSERT INTO {$prices}(user_id,product_key,price,currency,quantity_text,store_id,store_name_snapshot,purchased_at,created_at) VALUES(:u,:k,:p,'EUR',:q,:sid,:sn,:d,:t)")
            ->execute(['u'=>$userId,'k'=>$key,'p'=>round((float)$raw,2),'q'=>$this->clean($input['quantity_text'] ?? '',80),'sid'=>$storeId,'sn'=>$storeName,'d'=>$date,'t'=>gmdate('Y-m-d H:i:s')]);
    }

    public function prices(int $userId, string $key): array
    {
        $table = Database::table('price_entries');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE user_id=:u AND product_key=:k ORDER BY purchased_at DESC,id DESC LIMIT 30");
        $stmt->execute(['u'=>$userId,'k'=>$key]);
        return $stmt->fetchAll();
    }

    public function saveReviewPhoto(int $userId, int $entryId, array $upload, string $altText): int
    {
        if (!$this->findEntry($userId, $entryId)) throw new \RuntimeException('Bewertung nicht gefunden.');
        $table=Database::table('review_photos');
        Database::pdo()->prepare("INSERT INTO {$table}(user_id,entry_id,storage_path,width,height,mime_type,alt_text,created_at) VALUES(:u,:e,:p,:w,:h,:m,:a,:t)")
            ->execute(['u'=>$userId,'e'=>$entryId,'p'=>$upload['path'],'w'=>$upload['width'],'h'=>$upload['height'],'m'=>$upload['mime'],'a'=>$this->clean($altText,240),'t'=>gmdate('Y-m-d H:i:s')]);
        return (int)Database::pdo()->lastInsertId();
    }

    public function photos(int $userId, int $entryId): array
    {
        $table=Database::table('review_photos');$stmt=Database::pdo()->prepare("SELECT * FROM {$table} WHERE user_id=:u AND entry_id=:e ORDER BY id DESC");$stmt->execute(['u'=>$userId,'e'=>$entryId]);return $stmt->fetchAll();
    }

    public function savePreferences(int $userId, array $input): void
    {
        $allowed = ['suess','salzig','sauer','scharf','herb','knusprig','weich','schokoladig','fruchtig','nussig','ungewoehnlich'];
        $prefs = array_values(array_intersect($allowed, is_array($input['taste_preferences'] ?? null) ? $input['taste_preferences'] : []));
        $table = Database::table('user_preferences');
        $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        $params = ['u'=>$userId,'p'=>$json,'t'=>gmdate('Y-m-d H:i:s')];
        $sql = Database::driver() === 'sqlite'
            ? "INSERT INTO {$table}(user_id,taste_preferences,updated_at) VALUES(:u,:p,:t) ON CONFLICT(user_id) DO UPDATE SET taste_preferences=:p,updated_at=:t"
            : "INSERT INTO {$table}(user_id,taste_preferences,updated_at) VALUES(:u,:p,:t) ON DUPLICATE KEY UPDATE taste_preferences=VALUES(taste_preferences),updated_at=VALUES(updated_at)";
        Database::pdo()->prepare($sql)->execute($params);
        $users = Database::table('users');
        Database::pdo()->prepare("UPDATE {$users} SET display_name=:d,onboarding_completed=1,updated_at=:t WHERE id=:u")
            ->execute(['d'=>$this->clean($input['display_name'] ?? '',80) ?: 'Snack-Fan','t'=>gmdate('Y-m-d H:i:s'),'u'=>$userId]);
    }

    public function collections(int $userId): array
    {
        $c = Database::table('collections'); $i = Database::table('collection_items');
        $stmt = Database::pdo()->prepare("SELECT c.*,COUNT(i.id) item_count FROM {$c} c LEFT JOIN {$i} i ON i.collection_id=c.id WHERE c.user_id=:u GROUP BY c.id ORDER BY c.updated_at DESC");
        $stmt->execute(['u'=>$userId]); return $stmt->fetchAll();
    }

    public function collection(int $userId, int $id): ?array
    {
        $c = Database::table('collections'); $i = Database::table('collection_items');
        $stmt = Database::pdo()->prepare("SELECT * FROM {$c} WHERE id=:id AND user_id=:u"); $stmt->execute(['id'=>$id,'u'=>$userId]);
        $row=$stmt->fetch(); if(!$row) return null;
        $items=Database::pdo()->prepare("SELECT * FROM {$i} WHERE collection_id=:id ORDER BY position,id"); $items->execute(['id'=>$id]);
        $row['items']=$items->fetchAll(); return $row;
    }

    public function createCollection(int $userId, string $name, string $description): int
    {
        $name=$this->clean($name,120); if($name==='') throw new \InvalidArgumentException('Bitte gib einen Namen an.');
        $t=gmdate('Y-m-d H:i:s'); $table=Database::table('collections');
        Database::pdo()->prepare("INSERT INTO {$table}(user_id,name,description,visibility,created_at,updated_at) VALUES(:u,:n,:d,'private',:t,:t2)")->execute(['u'=>$userId,'n'=>$name,'d'=>$this->clean($description,600),'t'=>$t,'t2'=>$t]);
        return (int)Database::pdo()->lastInsertId();
    }

    public function addToCollection(int $userId, int $collectionId, string $productKey): void
    {
        $collection=$this->collection($userId,$collectionId); if(!$collection) throw new \RuntimeException('Sammlung nicht gefunden.');
        $entry=$this->findEntryByKey($userId,$productKey); if(!$entry) throw new \RuntimeException('Produkt gehört nicht zu deiner Bibliothek.');
        $table=Database::table('collection_items');
        $sql=Database::driver()==='sqlite'
            ? "INSERT INTO {$table}(collection_id,product_key,product_name,image_url,position,added_at) VALUES(:c,:k,:n,:i,:p,:t) ON CONFLICT(collection_id,product_key) DO NOTHING"
            : "INSERT INTO {$table}(collection_id,product_key,product_name,image_url,position,added_at) VALUES(:c,:k,:n,:i,:p,:t) ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),image_url=VALUES(image_url)";
        Database::pdo()->prepare($sql)->execute(['c'=>$collectionId,'k'=>$productKey,'n'=>$entry['product_name'],'i'=>$entry['image_url'],'p'=>count($collection['items']),'t'=>gmdate('Y-m-d H:i:s')]);
    }

    public function deleteCollection(int $userId, int $id): bool
    {
        $table=Database::table('collections'); $stmt=Database::pdo()->prepare("DELETE FROM {$table} WHERE id=:id AND user_id=:u"); $stmt->execute(['id'=>$id,'u'=>$userId]); return $stmt->rowCount()===1;
    }

    public function removeFromCollection(int $userId, int $collectionId, int $itemId): bool
    {
        $collections = Database::table('collections');
        $items = Database::table('collection_items');
        $stmt = Database::pdo()->prepare(
            "DELETE FROM {$items} WHERE id=:item AND collection_id=:collection AND EXISTS "
            . "(SELECT 1 FROM {$collections} c WHERE c.id=:owned_collection AND c.user_id=:u)"
        );
        $stmt->execute(['item'=>$itemId,'collection'=>$collectionId,'owned_collection'=>$collectionId,'u'=>$userId]);
        return $stmt->rowCount() === 1;
    }

    public function tasteProfile(int $userId): array
    {
        $entries=$this->list($userId,['limit'=>200,'sort'=>'rating']);
        $categories=[];$brands=[];$sum=0;$buy=0;
        foreach($entries as $e){$sum+=(int)$e['overall_rating'];$buy+=(int)($e['buy_again']==='yes');$cat=trim(explode(',',(string)$e['category'])[0]);if($cat!==''){$categories[$cat]??=['sum'=>0,'count'=>0];$categories[$cat]['sum']+=(int)$e['overall_rating'];$categories[$cat]['count']++;}$brand=trim((string)$e['brand']);if($brand!==''){$brands[$brand]??=['sum'=>0,'count'=>0];$brands[$brand]['sum']+=(int)$e['overall_rating'];$brands[$brand]['count']++;}}
        $score=static fn(array $a):float=>round($a['sum']/$a['count'],1);
        uasort($categories,fn($a,$b)=>$score($b)<=>$score($a)); uasort($brands,fn($a,$b)=>$score($b)<=>$score($a));
        $tagMap=Database::table('user_product_tags');$tags=Database::table('taste_tags');$entryTable=Database::table('user_product_entries');
        $stmt=Database::pdo()->prepare("SELECT t.label,COUNT(*) uses FROM {$tagMap} m JOIN {$tags} t ON t.id=m.tag_id JOIN {$entryTable} e ON e.id=m.entry_id WHERE e.user_id=:u GROUP BY t.id ORDER BY uses DESC,t.label LIMIT 8");$stmt->execute(['u'=>$userId]);
        return ['count'=>count($entries),'average'=>count($entries)?round($sum/count($entries),1):0,'buy_again_rate'=>count($entries)?round($buy*100/count($entries)):0,'categories'=>array_slice(array_map(fn($k,$v)=>['name'=>$k,'average'=>$score($v),'count'=>$v['count']],array_keys($categories),$categories),0,6),'brands'=>array_slice(array_map(fn($k,$v)=>['name'=>$k,'average'=>$score($v),'count'=>$v['count']],array_keys($brands),$brands),0,6),'tags'=>$stmt->fetchAll()];
    }

    private function rating(mixed $value, bool $required=false): ?int
    {
        if($value===null||$value===''){if($required) throw new \InvalidArgumentException('Bitte wähle eine Gesamtwertung.');return null;}
        if(!is_numeric($value)||(int)$value<1||(int)$value>10) throw new \InvalidArgumentException('Bewertungen müssen zwischen 1 und 10 liegen.'); return (int)$value;
    }

    private function clean(mixed $value, int $max): string
    {
        $value=(string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u',' ',(string)$value);return mb_substr(trim((string)preg_replace('/\s+/u',' ',$value)),0,$max);
    }

    private function pickReason(array $entry): string
    {
        if (!empty($entry['favorite']) && ($entry['buy_again'] ?? '') === 'yes') {
            return 'Einer deiner Favoriten, den du wieder kaufen würdest.';
        }
        if ((int)($entry['overall_rating'] ?? 0) >= 9) {
            return 'Eine deiner stärksten Bewertungen.';
        }
        if (($entry['buy_again'] ?? '') === 'yes') {
            return 'Von dir ausdrücklich zum Wiederkauf markiert.';
        }
        return 'Passt zu deinen bisher positiven Bewertungen.';
    }
}
