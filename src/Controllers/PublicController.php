<?php
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\Database;
use SnackQuest\Http\Request;
use SnackQuest\Http\Response;

final class PublicController extends BaseController
{
    public function home(Request $r,array $p):never{$this->render('pages/home',['title'=>'SnackQuest · Scannen. Bewerten. Wiederfinden.','description'=>'Dein persönliches Snack-Gedächtnis. Echte Barcodes, echte Produktdaten, deine privaten Bewertungen.']);}
    public function features(Request $r,array $p):never{$this->render('pages/features',['title'=>'Funktionen · SnackQuest']);}
    public function howItWorks(Request $r,array $p):never{$this->render('pages/how-it-works',['title'=>'So funktioniert SnackQuest']);}
    public function about(Request $r,array $p):never{$this->render('pages/about',['title'=>'Über SnackQuest']);}
    public function privacy(Request $r,array $p):never{$this->render('legal/privacy',['title'=>'Datenschutz']);}
    public function terms(Request $r,array $p):never{$this->render('legal/terms',['title'=>'Nutzungsbedingungen']);}
    public function imprint(Request $r,array $p):never{$this->render('legal/imprint',['title'=>'Impressum']);}
    public function credits(Request $r,array $p):never{$this->render('legal/credits',['title'=>'Datenquellen & Credits']);}
    public function offline(Request $r,array $p):never{$this->render('pages/offline',['title'=>'Offline · SnackQuest']);}
    public function shared(Request $r,array $p):never
    {
        header('Cache-Control: no-store, private');
        $share=$this->shares->resolve((string)($p['token']??''));
        if(!$share)Response::html(\SnackQuest\Support\View::render('pages/404',['title'=>'Freigabe nicht gefunden','flashes'=>[],'isLoggedIn'=>false]),404);
        $this->render('pages/shared',['title'=>'Geteilt mit SnackQuest','description'=>'Eine ausdrücklich freigegebene SnackQuest-Momentaufnahme.','share'=>$share]);
    }
    public function status(Request $r,array $p):never
    {
        $db='ok';try{Database::pdo()->query('SELECT 1');}catch(\Throwable){$db='degraded';}
        $this->render('pages/status',['title'=>'Status · SnackQuest','status'=>['app'=>'ok','database'=>$db,'product_source'=>'operational','ai'=>'optional']]);
    }
}
