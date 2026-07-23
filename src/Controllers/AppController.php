<?php
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\App;
use SnackQuest\Database;
use SnackQuest\Http\Request;
use SnackQuest\Http\RateLimiter;
use SnackQuest\Http\Response;
use SnackQuest\Http\Session;
use SnackQuest\Services\BarcodeService;
use SnackQuest\Services\ProductService;

final class AppController extends BaseController
{
    public function dashboard(Request $r,array $p):never
    {
        $user=$this->currentUser();if(!(int)$user['onboarding_completed'])$this->redirect('/app/onboarding');
        $pick = null;
        if ($r->q('pick') === '1') {
            $history = Session::get('_snack_pick_history', []);
            $pick = $this->products->pickForUser($this->userId(), is_array($history) ? $history : []);
            if ($pick !== null) {
                $pickedKey = (string)$pick['product_key'];
                $keys = array_values(array_filter(
                    is_array($history) ? $history : [],
                    static fn(mixed $key): bool => is_string($key) && $key !== $pickedKey
                ));
                $keys[] = $pickedKey;
                Session::set('_snack_pick_history', array_slice($keys, -8));
            }
        }
        $this->render('app/dashboard',[
            'title'=>'Deine SnackQuest',
            'dashboard'=>$this->products->dashboard($this->userId()),
            'quest'=>$this->games->currentQuest($this->userId()),
            'snackPick'=>$pick,
            'pickRequested'=>$r->q('pick') === '1',
        ],'layouts/app');
    }

    public function onboardingForm(Request $r,array $p):never{$this->render('app/onboarding',['title'=>'Dein Geschmack','user'=>$this->currentUser()],'layouts/app');}
    public function onboardingSubmit(Request $r,array $p):never
    {
        $this->products->savePreferences($this->userId(),['display_name'=>$r->p('display_name',''),'taste_preferences'=>$r->pArray('taste_preferences')]);
        Session::flash('success','Dein Profil ist bereit. Zeit für den ersten Scan.');$this->redirect('/app/scan');
    }

    public function scan(Request $r,array $p):never
    {
        $recent = Session::get('_recent_scans', []);
        $this->render('app/scan',[
            'title'=>'Snack scannen',
            'recentScans'=>is_array($recent) ? array_slice($recent, 0, 5) : [],
        ],'layouts/app');
    }
    public function scanManual(Request $r,array $p):never
    {
        $barcode=BarcodeService::normalize($r->p('barcode','')??'');if(!BarcodeService::validate($barcode)){Session::flash('error','Dieser Barcode ist ungültig. Prüfe bitte alle Ziffern.');$this->redirect('/app/scan');}
        $this->redirect('/app/product/'.$barcode);
    }

    public function product(Request $r,array $p):never
    {
        $barcode=BarcodeService::normalize((string)($p['barcode']??''));$result=$this->off->find($barcode,$r->q('refresh')==='1');
        if($result['status']==='invalid'){Session::flash('error',(string)$result['error']);$this->redirect('/app/scan');}
        if($result['product']===null){$this->render('app/product-missing',['title'=>'Produkt nicht gefunden','barcode'=>$barcode,'sourceStatus'=>$result['status'],'sourceError'=>$result['error']],'layouts/app');}
        $this->rememberScan($result['product']);
        $entry=$this->products->findEntryByKey($this->userId(),'off:'.$barcode);
        $this->render('app/product',['title'=>($result['product']['name']??'Produkt').' · SnackQuest','product'=>$result['product'],'entry'=>$entry,'tags'=>$this->products->allTags(),'prices'=>$entry?$this->products->prices($this->userId(),(string)$entry['product_key']):[],'entryTags'=>$entry?$this->products->tagsForEntry((int)$entry['id']):[],'cached'=>$result['cached'],'sourceStatus'=>$result['status']],'layouts/app');
    }

    public function customProduct(Request $r,array $p):never
    {
        $product=$this->products->customProduct($this->userId(),(int)($p['id']??0));if(!$product)$this->notFound();
        $entry=$this->products->findEntryByKey($this->userId(),$product['key']);
        $this->render('app/product',['title'=>$product['name'].' · SnackQuest','product'=>$product,'entry'=>$entry,'tags'=>$this->products->allTags(),'prices'=>$entry?$this->products->prices($this->userId(),$product['key']):[],'entryTags'=>$entry?$this->products->tagsForEntry((int)$entry['id']):[],'cached'=>true,'sourceStatus'=>'custom'],'layouts/app');
    }

    public function reviewSave(Request $r,array $p):never
    {
        $type=$r->p('product_type','off');$product=null;
        if($type==='custom')$product=$this->products->customProduct($this->userId(),(int)$r->p('custom_id','0'));
        else{$barcode=BarcodeService::normalize($r->p('barcode','')??'');$found=$this->off->find($barcode);$product=$found['product'];}
        if(!$product){Session::flash('error','Das Produkt konnte nicht sicher geladen werden.');$this->redirect('/app/scan');}
        try{
            $input=$r->post;$input['tags']=$r->pArray('tags');$entryId=$this->products->saveReview($this->userId(),$product,$input);
            if(isset($_FILES['review_photo'])){$upload=$this->uploads->image($_FILES['review_photo'],$this->userId(),'review');if($upload)$this->products->saveReviewPhoto($this->userId(),$entryId,$upload,(string)$product['name']);}
            Session::set('_clear_review_draft', (string)$product['key']);
            $saved=$this->products->findEntry($this->userId(),$entryId);
            $clientDraftAt=$r->p('_client_draft_at','')??'';
            $clearBefore=preg_match('/^\d{13}$/D',$clientDraftAt)===1
                ? (int)$clientDraftAt
                : ($saved?ProductService::parseDatabaseUtc((string)$saved['updated_at'])*1000+999:0);
            Session::set('_clear_review_before',$clearBefore);
            Session::flash('success','Bewertung gespeichert — dein Snack-Gedächtnis ist aktualisiert.');$this->redirect('/app/entry/'.$entryId);
        }catch(\InvalidArgumentException $e){Session::flash('error',$e->getMessage());$target=$type==='custom'?'/app/custom/'.(int)$r->p('custom_id','0'):'/app/product/'.rawurlencode((string)$r->p('barcode',''));$this->redirect($target);}
    }

    public function entry(Request $r,array $p):never
    {
        $entry=$this->products->findEntry($this->userId(),(int)($p['id']??0));if(!$entry)$this->notFound();
        $this->render('app/entry',['title'=>$entry['product_name'].' · Deine Bewertung','entry'=>$entry,'tags'=>$this->products->tagsForEntry((int)$entry['id']),'prices'=>$this->products->prices($this->userId(),(string)$entry['product_key']),'photos'=>$this->products->photos($this->userId(),(int)$entry['id']),'collections'=>$this->products->collections($this->userId())],'layouts/app');
    }

    public function library(Request $r,array $p):never
    {
        $filters=['q'=>$r->q('q',''),'sort'=>$r->q('sort','recent'),'min_rating'=>$r->q('min_rating'),'favorite'=>$r->q('favorite')==='1','buy_again'=>$r->q('buy_again'),'never_again'=>$r->q('never_again')==='1','movie_night'=>$r->q('movie_night')==='1'];
        $this->render('app/library',['title'=>'Deine Bibliothek','entries'=>$this->products->list($this->userId(),$filters),'filters'=>$filters],'layouts/app');
    }
    public function favorites(Request $r,array $p):never{$r=new Request($r->method,$r->path,['favorite'=>'1'],$r->post,$r->server);$this->render('app/library',['title'=>'Favoriten','entries'=>$this->products->list($this->userId(),['favorite'=>true]),'filters'=>['favorite'=>true]],'layouts/app');}
    public function buyAgain(Request $r,array $p):never{$this->render('app/library',['title'=>'Wieder kaufen','entries'=>$this->products->list($this->userId(),['buy_again'=>'yes']),'filters'=>['buy_again'=>'yes']],'layouts/app');}
    public function neverAgain(Request $r,array $p):never{$this->render('app/library',['title'=>'Nie wieder','entries'=>$this->products->list($this->userId(),['never_again'=>true]),'filters'=>['never_again'=>true]],'layouts/app');}

    public function addCustomForm(Request $r,array $p):never{$this->render('app/add-custom',['title'=>'Eigenes Produkt'],'layouts/app');}
    public function addCustomSubmit(Request $r,array $p):never
    {
        try{$upload=isset($_FILES['product_photo'])?$this->uploads->image($_FILES['product_photo'],$this->userId(),'product'):null;$product=$this->products->createCustom($this->userId(),$r->post,$upload['path']??'');Session::flash('success','Privates Produkt angelegt. Jetzt kannst du es bewerten.');$this->redirect('/app/custom/'.(int)$product['custom_id']);}
        catch(\InvalidArgumentException $e){Session::flash('error',$e->getMessage());$this->redirect('/app/add-custom');}
    }

    public function collections(Request $r,array $p):never{$this->render('app/collections',['title'=>'Sammlungen','collections'=>$this->products->collections($this->userId())],'layouts/app');}
    public function collectionCreate(Request $r,array $p):never{try{$id=$this->products->createCollection($this->userId(),$r->p('name','')??'',$r->p('description','')??'');Session::flash('success','Sammlung angelegt.');$this->redirect('/app/collections/'.$id);}catch(\InvalidArgumentException $e){Session::flash('error',$e->getMessage());$this->redirect('/app/collections');}}
    public function collectionDetail(Request $r,array $p):never{$collection=$this->products->collection($this->userId(),(int)($p['id']??0));if(!$collection)$this->notFound();$this->render('app/collection-detail',['title'=>$collection['name'],'collection'=>$collection,'entries'=>$this->products->list($this->userId(),['limit'=>200])],'layouts/app');}
    public function collectionAdd(Request $r,array $p):never{try{$id=(int)$r->p('collection_id','0');$this->products->addToCollection($this->userId(),$id,$r->p('product_key','')??'');Session::flash('success','Zur Sammlung hinzugefügt.');$this->redirect('/app/collections/'.$id);}catch(\Throwable $e){Session::flash('error',$e->getMessage());$this->redirect('/app/collections');}}
    public function collectionDelete(Request $r,array $p):never{$this->products->deleteCollection($this->userId(),(int)$r->p('collection_id','0'));Session::flash('success','Sammlung gelöscht.');$this->redirect('/app/collections');}
    public function collectionRemove(Request $r,array $p):never{$id=(int)$r->p('collection_id','0');$removed=$this->products->removeFromCollection($this->userId(),$id,(int)$r->p('item_id','0'));Session::flash($removed?'success':'info',$removed?'Snack aus der Sammlung entfernt.':'Eintrag wurde nicht gefunden.');$this->redirect('/app/collections/'.$id);}

    public function shareCreate(Request $r,array $p):never
    {
        try{$type=$r->p('share_type','')??'';$resource=(int)$r->p('resource_id','0');$share=$this->shares->create($this->userId(),$type,$resource);$url=rtrim((string)App::$config->get('app_base_url'),'/').'/s/'.$share['token'];$this->render('app/share-ready',['title'=>'Freigabe bereit','shareUrl'=>$url,'shareId'=>$share['id'],'shareType'=>$type,'resourceId'=>$resource,'payload'=>$share['payload']],'layouts/app');}
        catch(\Throwable $e){Session::flash('error',$e->getMessage());$this->redirect('/app');}
    }
    public function shareRevoke(Request $r,array $p):never
    {
        $this->shares->revoke($this->userId(),(int)$r->p('share_id','0'));Session::flash('success','Freigabe widerrufen. Der Link ist nicht mehr erreichbar.');$this->redirect('/app/shares');
    }
    public function shares(Request $r,array $p):never{$this->render('app/shares',['title'=>'Deine Freigaben','shares'=>$this->shares->listForUser($this->userId())],'layouts/app');}

    public function battles(Request $r,array $p):never{$type=$r->q('type','taste')??'taste';$this->render('app/battle',['title'=>'Produktduell','battle'=>$this->games->nextBattle($this->userId(),$type),'rankings'=>$this->games->rankings($this->userId(),$type),'dimension'=>$type],'layouts/app');}
    public function battleDecide(Request $r,array $p):never{try{$this->games->decide($this->userId(),(int)$r->p('session_id','0'),$r->p('left','')??'',$r->p('right','')??'',$r->p('winner','')??'',$r->p('reason','taste')??'taste');Session::flash('success','Duell gewertet. Dein Ranking ist aktualisiert.');}catch(\Throwable $e){Session::flash('error',$e->getMessage());}$this->redirect('/app/battles?type='.urlencode($r->p('reason','taste')??'taste'));}
    public function quests(Request $r,array $p):never{$this->render('app/quests',['title'=>'Snack-Quests','quest'=>$this->games->currentQuest($this->userId())],'layouts/app');}
    public function questComplete(Request $r,array $p):never{$ok=$this->games->completeQuest($this->userId(),(int)$r->p('quest_id','0'));Session::flash($ok?'success':'info',$ok?'Quest abgeschlossen. Stark gesammelt!':'Diese Quest ist nicht mehr aktiv.');$this->redirect('/app/quests');}
    public function tasteProfile(Request $r,array $p):never{$this->render('app/taste-profile',['title'=>'Dein Geschmacksprofil','profile'=>$this->products->tasteProfile($this->userId()),'aiAvailable'=>$this->ai->available(),'aiOptedIn'=>$this->ai->optedIn($this->userId()),'aiInsight'=>$this->ai->latest($this->userId())],'layouts/app');}
    public function tasteInsight(Request $r,array $p):never
    {
        if(!RateLimiter::allow('ai-insight:user:'.$this->userId(),4,3600)){Session::flash('error','Du hast die private Auswertung gerade mehrfach erstellt. Bitte warte etwas.');$this->redirect('/app/taste-profile');}
        try{$this->ai->generate($this->userId(),$this->products->tasteProfile($this->userId()));Session::flash('success','Deine private Auswertung ist bereit.');}
        catch(\RuntimeException $e){Session::flash('error',$e->getMessage());}
        $this->redirect('/app/taste-profile');
    }

    public function settings(Request $r,array $p):never
    {
        $table=Database::table('user_preferences');$stmt=Database::pdo()->prepare("SELECT * FROM {$table} WHERE user_id=:u");$stmt->execute(['u'=>$this->userId()]);$this->render('app/settings',['title'=>'Einstellungen','prefs'=>$stmt->fetch()?:[],'aiAvailable'=>$this->ai->available()],'layouts/app');
    }
    public function settingsSave(Request $r,array $p):never
    {
        $table=Database::table('user_preferences');$ai=$r->p('ai_opt_in')==='1'?1:0;$analytics=0;$params=['u'=>$this->userId(),'ai'=>$ai,'an'=>$analytics,'t'=>gmdate('Y-m-d H:i:s')];
        $sql=Database::driver()==='sqlite'?"INSERT INTO {$table}(user_id,ai_opt_in,analytics_opt_in,updated_at) VALUES(:u,:ai,:an,:t) ON CONFLICT(user_id) DO UPDATE SET ai_opt_in=:ai,analytics_opt_in=:an,updated_at=:t":"INSERT INTO {$table}(user_id,ai_opt_in,analytics_opt_in,updated_at) VALUES(:u,:ai,:an,:t) ON DUPLICATE KEY UPDATE ai_opt_in=VALUES(ai_opt_in),analytics_opt_in=VALUES(analytics_opt_in),updated_at=VALUES(updated_at)";
        Database::pdo()->prepare($sql)->execute($params);Session::flash('success','Einstellungen gespeichert.');$this->redirect('/app/settings');
    }
    public function account(Request $r,array $p):never{$this->render('app/account',['title'=>'Konto','user'=>$this->currentUser()],'layouts/app');}
    public function accountExport(Request $r,array $p):never{Response::jsonDownload($this->auth->exportUserData($this->userId()),'snackquest-export-'.gmdate('Y-m-d').'.json');}
    public function accountDelete(Request $r,array $p):never
    {
        if($r->p('confirm')!=='KONTO LÖSCHEN'){
            if($r->wantsJson())Response::json(['ok'=>false,'error'=>'Gib zur Bestätigung exakt „KONTO LÖSCHEN“ ein.'],422);
            Session::flash('error','Gib zur Bestätigung exakt „KONTO LÖSCHEN“ ein.');$this->redirect('/app/account');
        }
        $paths=[];foreach(['review_photos'=>['user_id','storage_path'],'custom_products'=>['owner_user_id','image_path']] as $name=>[$owner,$col]){$t=Database::table($name);$s=Database::pdo()->prepare("SELECT {$col} FROM {$t} WHERE {$owner}=:u");$s->execute(['u'=>$this->userId()]);$paths=array_merge($paths,array_filter($s->fetchAll(\PDO::FETCH_COLUMN)));}
        $id=$this->userId();$this->auth->deleteAccount($id);foreach($paths as $path){$absolute=$this->uploads->absolute((string)$path);if($absolute)@unlink($absolute);}Session::logout();
        if($r->wantsJson())Response::json(['ok'=>true,'redirect'=>$this->basePath.'/']);
        Response::redirect($this->basePath,'/');
    }

    public function media(Request $r,array $p):never
    {
        $kind=(string)($p['kind']??'');$id=(int)($p['id']??0);$path='';$mime='image/webp';
        if($kind==='review'){$t=Database::table('review_photos');$s=Database::pdo()->prepare("SELECT storage_path,mime_type FROM {$t} WHERE id=:id AND user_id=:u");$s->execute(['id'=>$id,'u'=>$this->userId()]);$row=$s->fetch();if($row){$path=$row['storage_path'];$mime=$row['mime_type'];}}
        elseif($kind==='custom'){$t=Database::table('custom_products');$s=Database::pdo()->prepare("SELECT image_path FROM {$t} WHERE id=:id AND owner_user_id=:u");$s->execute(['id'=>$id,'u'=>$this->userId()]);$path=(string)($s->fetchColumn()?:'');}
        $absolute=$this->uploads->absolute($path);if(!$absolute)$this->notFound();header('Content-Type: '.$mime);header('Cache-Control: private, no-store');header('Vary: Cookie');header('Content-Length: '.filesize($absolute));readfile($absolute);exit;
    }

    private function notFound():never{Response::html(\SnackQuest\Support\View::render('pages/404',['title'=>'Seite nicht gefunden','flashes'=>[],'isLoggedIn'=>Session::userId()!==null]),404);}

    private function rememberScan(array $product): void
    {
        $barcode = BarcodeService::normalize((string)($product['barcode'] ?? ''));
        if (!BarcodeService::validate($barcode)) {
            return;
        }
        $recent = Session::get('_recent_scans', []);
        $recent = is_array($recent) ? $recent : [];
        $recent = array_values(array_filter(
            $recent,
            static fn(mixed $item): bool => is_array($item) && ($item['barcode'] ?? '') !== $barcode
        ));
        array_unshift($recent, [
            'barcode' => $barcode,
            'name' => mb_substr(trim((string)($product['name'] ?? 'Unbenanntes Produkt')), 0, 240),
            'brand' => mb_substr(trim((string)($product['brand'] ?? '')), 0, 160),
        ]);
        Session::set('_recent_scans', array_slice($recent, 0, 5));
    }
}
