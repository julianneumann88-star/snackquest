<?php
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\App;
use SnackQuest\Database;
use SnackQuest\Http\Request;
use SnackQuest\Http\RateLimiter;
use SnackQuest\Http\Response;
use SnackQuest\Services\BarcodeService;
use SnackQuest\Services\ReviewConflictException;

final class ApiController extends BaseController
{
    public function health(Request $r,array $p):never
    {
        $db='ok';try{Database::pdo()->query('SELECT 1');}catch(\Throwable){$db='degraded';}
        Response::json(['status'=>$db==='ok'?'ok':'degraded','version'=>(string)App::$config->get('app_version','1.1.0'),'database'=>$db,'open_food_facts'=>'configured','ai'=>(bool)App::$config->get('ai.enabled',false)?'optional':'off','request_id'=>App::$requestId]);
    }

    public function product(Request $r,array $p):never
    {
        $result=$this->off->find((string)($p['barcode']??''),$r->q('refresh')==='1');
        Response::json($result,$result['status']==='invalid'?422:($result['status']==='rate_limited'?429:200));
    }

    public function syncReview(Request $r,array $p):never
    {
        if (!RateLimiter::allow('review-sync:user:'.$this->userId(), 30, 60)) {
            Response::json(['ok'=>false,'error'=>'Zu viele Synchronisationsversuche. Bitte warte kurz.'],429);
        }
        $syncId = (string)($r->p('_sync_id', '') ?? '');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $syncId) !== 1) {
            Response::json(['ok'=>false,'error'=>'Die Synchronisations-ID ist ungültig.'],422);
        }
        $baseRaw = (string)($r->p('_base_updated_at', '') ?? '');
        if (preg_match('/^(0|[1-9][0-9]{0,10})$/', $baseRaw) !== 1) {
            Response::json(['ok'=>false,'error'=>'Die Basisversion der Bewertung ist ungültig.'],422);
        }
        $completed = $this->products->completedSync($this->userId(), $syncId);
        if ($completed !== null) {
            Response::json([
                'ok'=>true,
                'entry_id'=>$completed['entry_id'],
                'duplicate'=>true,
                'server_updated_at'=>$completed['server_updated_at'],
            ]);
        }

        $type = $r->p('product_type', 'off');
        if ($type === 'custom') {
            $product = $this->products->customProduct($this->userId(), (int)$r->p('custom_id', '0'));
        } else {
            $barcode = BarcodeService::normalize((string)($r->p('barcode', '') ?? ''));
            $result = $this->off->find($barcode);
            $product = $result['product'];
        }
        if (!$product)Response::json(['ok'=>false,'error'=>'Produkt konnte nicht geladen werden.'],422);
        try{
            $input=$r->post;
            $input['tags']=$r->pArray('tags');
            $result=$this->products->syncReview(
                $this->userId(),
                $product,
                $input,
                $syncId,
                (int)$baseRaw
            );
            Response::json([
                'ok'=>true,
                'entry_id'=>$result['entry_id'],
                'duplicate'=>$result['duplicate'],
                'server_updated_at'=>$result['server_updated_at'],
            ]);
        }
        catch(ReviewConflictException $e){Response::json([
            'ok'=>false,
            'error'=>$e->getMessage(),
            'code'=>'review_conflict',
            'server_updated_at'=>$e->serverUpdatedAt,
        ],409);}
        catch(\InvalidArgumentException $e){Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}
    }
}
