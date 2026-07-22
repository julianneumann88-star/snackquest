<?php
/** Privacy-safe, explicit and revocable snapshot shares. */
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Database;

final class ShareService
{
    public function __construct(private readonly ProductService $products) {}

    /** @return array{token:string,id:int,payload:array} */
    public function create(int $userId, string $type, int $resourceId): array
    {
        $payload = match ($type) {
            'review' => $this->reviewPayload($userId, $resourceId),
            'collection' => $this->collectionPayload($userId, $resourceId),
            default => throw new \InvalidArgumentException('Dieser Inhalt kann nicht geteilt werden.'),
        };
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $table = Database::table('shares');
        Database::pdo()->prepare("INSERT INTO {$table}(user_id,share_type,resource_id,token_hash,payload,created_at,revoked_at) VALUES(:u,:type,:resource,:hash,:payload,:created,NULL)")->execute([
            'u'=>$userId, 'type'=>$type, 'resource'=>$resourceId, 'hash'=>hash('sha256',$token),
            'payload'=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), 'created'=>gmdate('Y-m-d H:i:s'),
        ]);
        return ['token'=>$token,'id'=>(int)Database::pdo()->lastInsertId(),'payload'=>$payload];
    }

    public function active(int $userId, string $type, int $resourceId): array
    {
        $table = Database::table('shares');
        $stmt = Database::pdo()->prepare("SELECT id,created_at FROM {$table} WHERE user_id=:u AND share_type=:type AND resource_id=:resource AND revoked_at IS NULL ORDER BY id DESC");
        $stmt->execute(['u'=>$userId,'type'=>$type,'resource'=>$resourceId]);
        return $stmt->fetchAll();
    }

    public function revoke(int $userId, int $id): bool
    {
        $table = Database::table('shares');
        $stmt = Database::pdo()->prepare("UPDATE {$table} SET revoked_at=:now WHERE id=:id AND user_id=:u AND revoked_at IS NULL");
        $stmt->execute(['now'=>gmdate('Y-m-d H:i:s'),'id'=>$id,'u'=>$userId]);
        return $stmt->rowCount() === 1;
    }

    public function listForUser(int $userId): array
    {
        $table = Database::table('shares');
        $stmt = Database::pdo()->prepare("SELECT id,share_type,resource_id,payload,created_at FROM {$table} WHERE user_id=:u AND revoked_at IS NULL ORDER BY id DESC");
        $stmt->execute(['u'=>$userId]);
        return array_map(static function(array $row):array{
            $payload=json_decode((string)$row['payload'],true);
            $row['label']=is_array($payload)?(string)($payload['product_name']??$payload['name']??'Freigabe'):'Freigabe';
            unset($row['payload']);
            return $row;
        },$stmt->fetchAll());
    }

    public function resolve(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/',$token)) return null;
        $table = Database::table('shares');
        $stmt = Database::pdo()->prepare("SELECT share_type,payload,created_at FROM {$table} WHERE token_hash=:hash AND revoked_at IS NULL LIMIT 1");
        $stmt->execute(['hash'=>hash('sha256',$token)]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $payload = json_decode((string)$row['payload'],true);
        return is_array($payload)?['type'=>$row['share_type'],'payload'=>$payload,'created_at'=>$row['created_at']]:null;
    }

    private function reviewPayload(int $userId, int $resourceId): array
    {
        $entry = $this->products->findEntry($userId,$resourceId);
        if (!$entry) throw new \RuntimeException('Die Bewertung wurde nicht gefunden.');
        return ['product_name'=>(string)$entry['product_name'],'brand'=>(string)$entry['brand'],'overall_rating'=>(int)$entry['overall_rating'],'taste_rating'=>$entry['taste_rating']!==null?(int)$entry['taste_rating']:null,'texture_rating'=>$entry['texture_rating']!==null?(int)$entry['texture_rating']:null,'value_rating'=>$entry['value_rating']!==null?(int)$entry['value_rating']:null,'buy_again'=>(string)$entry['buy_again'],'tags'=>array_values(array_map(static fn(array $tag):string=>(string)$tag['label'],$this->products->tagsForEntry($resourceId)))];
    }

    private function collectionPayload(int $userId, int $resourceId): array
    {
        $collection = $this->products->collection($userId,$resourceId);
        if (!$collection) throw new \RuntimeException('Die Sammlung wurde nicht gefunden.');
        return ['name'=>(string)$collection['name'],'description'=>(string)$collection['description'],'items'=>array_values(array_map(static fn(array $item):array=>['product_name'=>(string)$item['product_name']],(array)$collection['items']))];
    }
}
