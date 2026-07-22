<?php
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\App;
use SnackQuest\Database;
use SnackQuest\Http\Request;
use SnackQuest\Http\Response;

final class ApiController extends BaseController
{
    public function health(Request $r,array $p):never
    {
        $db='ok';try{Database::pdo()->query('SELECT 1');}catch(\Throwable){$db='degraded';}
        Response::json(['status'=>$db==='ok'?'ok':'degraded','version'=>(string)App::$config->get('app_version','1.0.0'),'database'=>$db,'open_food_facts'=>'configured','ai'=>(bool)App::$config->get('ai.enabled',false)?'optional':'off','request_id'=>App::$requestId]);
    }

    public function product(Request $r,array $p):never
    {
        $result=$this->off->find((string)($p['barcode']??''),$r->q('refresh')==='1');
        Response::json($result,$result['status']==='invalid'?422:($result['status']==='rate_limited'?429:200));
    }

    public function syncReview(Request $r,array $p):never
    {
        $result=$this->off->find($r->p('barcode','')??'');
        if(!$result['product'])Response::json(['ok'=>false,'error'=>'Produkt konnte nicht geladen werden.'],422);
        try{$entryId=$this->products->saveReview($this->userId(),$result['product'],$r->post);Response::json(['ok'=>true,'entry_id'=>$entryId]);}
        catch(\InvalidArgumentException $e){Response::json(['ok'=>false,'error'=>$e->getMessage()],422);}
    }
}
