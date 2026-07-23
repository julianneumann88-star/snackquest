<?php
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Database;

final class GameService
{
    public function __construct(private readonly ProductService $products)
    {
    }

    /** @return array{session_id:int,left:array,right:array,type:string}|null */
    public function nextBattle(int $userId, string $type = 'taste'): ?array
    {
        $type = in_array($type, ['taste','value','movie_night','buy_again'], true) ? $type : 'taste';
        $entries = $this->products->list($userId, ['limit'=>200,'sort'=>'recent']);
        if (count($entries) < 2) return null;
        $seed = hexdec(substr(hash('sha256', $userId . ':' . gmdate('Y-m-d') . ':' . $type), 0, 7));
        $leftIndex = $seed % count($entries);
        $rightIndex = ($leftIndex + 1 + ($seed % (count($entries) - 1))) % count($entries);
        if ($leftIndex === $rightIndex) $rightIndex = ($rightIndex + 1) % count($entries);
        $sessions = Database::table('battle_sessions');
        Database::pdo()->prepare("INSERT INTO {$sessions}(user_id,battle_type,status,created_at) VALUES(:u,:t,'active',:c)")
            ->execute(['u'=>$userId,'t'=>$type,'c'=>gmdate('Y-m-d H:i:s')]);
        return ['session_id'=>(int)Database::pdo()->lastInsertId(),'left'=>$entries[$leftIndex],'right'=>$entries[$rightIndex],'type'=>$type];
    }

    public function decide(int $userId, int $sessionId, string $left, string $right, string $winner, string $reason): void
    {
        if (!in_array($winner, [$left,$right], true)) throw new \InvalidArgumentException('Ungültige Duellentscheidung.');
        $sessions=Database::table('battle_sessions');
        $pdo=Database::pdo();$pdo->beginTransaction();
        try{
            $claim=$pdo->prepare("UPDATE {$sessions} SET status='processing' WHERE id=:id AND user_id=:u AND status='active'");
            $claim->execute(['id'=>$sessionId,'u'=>$userId]);
            if($claim->rowCount()!==1)throw new \RuntimeException('Dieses Duell ist nicht mehr aktiv.');
            $stmt=$pdo->prepare("SELECT * FROM {$sessions} WHERE id=:id AND user_id=:u AND status='processing'");
            $stmt->execute(['id'=>$sessionId,'u'=>$userId]);$session=$stmt->fetch();
            if(!$session)throw new \RuntimeException('Dieses Duell konnte nicht sicher reserviert werden.');
            if(!$this->products->findEntryByKey($userId,$left)||!$this->products->findEntryByKey($userId,$right))throw new \RuntimeException('Duellprodukte sind nicht verfügbar.');
            $dimension=match($reason){'value'=>'value','movie_night'=>'movie_night','buy_again'=>'buy_again',default=>(string)$session['battle_type']};
            $pairs=Database::table('battle_pairs');
            $pdo->prepare("INSERT INTO {$pairs}(battle_session_id,left_product_key,right_product_key,winner_product_key,selection_reason,created_at) VALUES(:s,:l,:r,:w,:reason,:t)")
                ->execute(['s'=>$sessionId,'l'=>$left,'r'=>$right,'w'=>$winner,'reason'=>$dimension,'t'=>gmdate('Y-m-d H:i:s')]);
            $this->applyElo($userId,$left,$right,$winner,$dimension);
            $complete=$pdo->prepare("UPDATE {$sessions} SET status='completed',completed_at=:t WHERE id=:id AND user_id=:u AND status='processing'");
            $complete->execute(['t'=>gmdate('Y-m-d H:i:s'),'id'=>$sessionId,'u'=>$userId]);
            if($complete->rowCount()!==1)throw new \RuntimeException('Duellabschluss konnte nicht bestätigt werden.');
            $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function rankings(int $userId, string $dimension='taste'): array
    {
        $dimension=in_array($dimension,['taste','value','movie_night','buy_again'],true)?$dimension:'taste';
        $scores=Database::table('ranking_scores');$entries=Database::table('user_product_entries');
        $stmt=Database::pdo()->prepare("SELECT r.*,e.product_name,e.brand,e.image_url,e.overall_rating FROM {$scores} r JOIN {$entries} e ON e.user_id=r.user_id AND e.product_key=r.product_key WHERE r.user_id=:u AND r.dimension=:d ORDER BY r.score DESC,e.overall_rating DESC");
        $stmt->execute(['u'=>$userId,'d'=>$dimension]);return $stmt->fetchAll();
    }

    /**
     * Rebuild every Elo score deterministically from the canonical, ordered
     * battle history. Used by the uniqueness migration after legacy duplicates
     * have been removed, so historical double submissions cannot leave inflated
     * match counters or scores behind.
     */
    public function rebuildRankingsFromCanonicalBattles(): int
    {
        $pdo=Database::pdo();
        $sessions=Database::table('battle_sessions');
        $pairs=Database::table('battle_pairs');
        $scores=Database::table('ranking_scores');
        $pdo->beginTransaction();
        try{
            $lock=Database::driver()==='mysql'?' FOR UPDATE':'';
            $rows=$pdo->query(
                "SELECT p.id,s.user_id,s.battle_type,p.left_product_key,p.right_product_key,"
                ."p.winner_product_key,p.selection_reason FROM {$pairs} p "
                ."JOIN {$sessions} s ON s.id=p.battle_session_id ORDER BY p.id{$lock}"
            )->fetchAll();
            $allowed=['taste','value','movie_night','buy_again'];
            foreach($rows as$row){
                $left=(string)$row['left_product_key'];$right=(string)$row['right_product_key'];$winner=(string)$row['winner_product_key'];
                if((int)$row['user_id']<1||$left===''||$right===''||$left===$right||!in_array($winner,[$left,$right],true)){
                    throw new \RuntimeException('Kanonische Duellhistorie enthält einen ungültigen Datensatz; Rankings wurden nicht verändert.');
                }
            }
            $pdo->exec("DELETE FROM {$scores}");
            foreach($rows as$row){
                $dimension=(string)($row['selection_reason']?:$row['battle_type']);
                if(!in_array($dimension,$allowed,true))$dimension='taste';
                $this->applyElo(
                    (int)$row['user_id'],
                    (string)$row['left_product_key'],
                    (string)$row['right_product_key'],
                    (string)$row['winner_product_key'],
                    $dimension
                );
            }
            $pdo->commit();
            return count($rows);
        }catch(\Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function currentQuest(int $userId): array
    {
        $table=Database::table('quests');
        $stmt=Database::pdo()->prepare("SELECT * FROM {$table} WHERE user_id=:u AND status='active' ORDER BY starts_at DESC LIMIT 1");$stmt->execute(['u'=>$userId]);$quest=$stmt->fetch();
        if($quest) return $quest;
        $profile=$this->products->tasteProfile($userId);
        if($profile['count']===0){$type='first_scan';$title='Starte deine Sammlung: Scanne und bewerte deinen ersten Snack.';$target=1;}
        elseif($profile['count']<5){$type='explore_five';$title='Baue deine Basis: Bewerte insgesamt fünf verschiedene Snacks.';$target=5;}
        elseif($profile['average']<8){$type='find_eight';$title='Finde einen neuen Favoriten mit mindestens 8 von 10 Punkten.';$target=1;}
        else{$type='new_category';$title='Probier diese Woche einen Snack aus einer neuen Kategorie.';$target=1;}
        $now=gmdate('Y-m-d H:i:s');$end=gmdate('Y-m-d H:i:s',time()+7*86400);
        Database::pdo()->prepare("INSERT INTO {$table}(user_id,quest_type,title,parameters,status,progress,target,starts_at,ends_at) VALUES(:u,:qt,:title,'{}','active',0,:target,:s,:e)")
            ->execute(['u'=>$userId,'qt'=>$type,'title'=>$title,'target'=>$target,'s'=>$now,'e'=>$end]);
        $stmt->execute(['u'=>$userId]);return $stmt->fetch() ?: [];
    }

    public function completeQuest(int $userId, int $questId): bool
    {
        $table=Database::table('quests');$stmt=Database::pdo()->prepare("UPDATE {$table} SET status='completed',progress=target,completed_at=:t WHERE id=:id AND user_id=:u AND status='active'");
        $stmt->execute(['t'=>gmdate('Y-m-d H:i:s'),'id'=>$questId,'u'=>$userId]);return $stmt->rowCount()===1;
    }

    private function applyElo(int $userId,string $left,string $right,string $winner,string $dimension):void
    {
        $table=Database::table('ranking_scores');$pdo=Database::pdo();
        $get=$pdo->prepare("SELECT score,match_count FROM {$table} WHERE user_id=:u AND product_key=:k AND dimension=:d");
        $scores=[];foreach([$left,$right] as $key){$get->execute(['u'=>$userId,'k'=>$key,'d'=>$dimension]);$scores[$key]=$get->fetch()?:['score'=>1000,'match_count'=>0];}
        $ra=(float)$scores[$left]['score'];$rb=(float)$scores[$right]['score'];$ea=1/(1+10**(($rb-$ra)/400));$sa=$winner===$left?1.0:0.0;$k=32;
        $new=[$left=>$ra+$k*($sa-$ea),$right=>$rb+$k*((1-$sa)-(1-$ea))];$now=gmdate('Y-m-d H:i:s');
        $sql=Database::driver()==='sqlite'
            ?"INSERT INTO {$table}(user_id,product_key,dimension,score,match_count,updated_at) VALUES(:u,:p,:d,:s,:m,:t) ON CONFLICT(user_id,product_key,dimension) DO UPDATE SET score=:s,match_count=:m,updated_at=:t"
            :"INSERT INTO {$table}(user_id,product_key,dimension,score,match_count,updated_at) VALUES(:u,:p,:d,:s,:m,:t) ON DUPLICATE KEY UPDATE score=VALUES(score),match_count=VALUES(match_count),updated_at=VALUES(updated_at)";
        $up=$pdo->prepare($sql);foreach([$left,$right] as $key)$up->execute(['u'=>$userId,'p'=>$key,'d'=>$dimension,'s'=>round($new[$key],2),'m'=>(int)$scores[$key]['match_count']+1,'t'=>$now]);
    }
}
