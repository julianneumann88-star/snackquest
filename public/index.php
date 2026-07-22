<?php
declare(strict_types=1);

use SnackQuest\App;
use SnackQuest\Controllers\ApiController;
use SnackQuest\Controllers\AppController;
use SnackQuest\Controllers\AuthController;
use SnackQuest\Controllers\PublicController;
use SnackQuest\Http\Request;
use SnackQuest\Http\Response;
use SnackQuest\Http\Session;
use SnackQuest\Router;

(static function():void{
    $uri=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';$rel=preg_replace('#^/snackquest/#','',$uri);
    if($rel===$uri||$rel===''||str_contains($rel,'..'))return;$file=__DIR__.'/'.$rel;$real=realpath($file);$root=realpath(__DIR__);
    if($real===false||$root===false||!is_file($real)||!str_starts_with($real,$root.DIRECTORY_SEPARATOR))return;
    $types=['css'=>'text/css','js'=>'application/javascript','mjs'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png','webp'=>'image/webp','ico'=>'image/x-icon','webmanifest'=>'application/manifest+json','json'=>'application/json','xml'=>'application/xml','txt'=>'text/plain','woff2'=>'font/woff2'];$ext=strtolower(pathinfo($real,PATHINFO_EXTENSION));if(!isset($types[$ext]))return;
    header('Content-Type: '.$types[$ext]);header('Cache-Control: public, max-age='.($rel==='sw.js'?0:604800));if($rel==='sw.js')header('Service-Worker-Allowed: /snackquest/');readfile($real);exit;
})();

require dirname(__DIR__).'/src/bootstrap.php';
App::boot();$base=(string)App::$config->get('base_path','');Response::securityHeaders($base);App::startSession();$request=Request::fromGlobals($base);$router=new Router();

foreach(['/'=>'home','/features'=>'features','/how-it-works'=>'howItWorks','/about'=>'about','/privacy'=>'privacy','/terms'=>'terms','/imprint'=>'imprint','/credits'=>'credits','/status'=>'status','/offline'=>'offline'] as $path=>$method)$router->get($path,[PublicController::class,$method]);
$router->get('/s/{token}',[PublicController::class,'shared']);
$router->get('/login',[AuthController::class,'loginForm']);$router->post('/login',[AuthController::class,'loginSubmit']);$router->get('/register',[AuthController::class,'registerForm']);$router->post('/register',[AuthController::class,'registerSubmit']);$router->get('/verify',[AuthController::class,'verify']);$router->get('/forgot-password',[AuthController::class,'forgotForm']);$router->post('/forgot-password',[AuthController::class,'forgotSubmit']);$router->get('/reset-password',[AuthController::class,'resetForm']);$router->post('/reset-password',[AuthController::class,'resetSubmit']);$router->get('/auth/google',[AuthController::class,'googleStart']);$router->get('/auth/callback',[AuthController::class,'googleCallback']);$router->post('/logout',[AuthController::class,'logout']);
$router->get('/app',[AppController::class,'dashboard']);$router->get('/app/onboarding',[AppController::class,'onboardingForm']);$router->post('/app/onboarding',[AppController::class,'onboardingSubmit']);$router->get('/app/scan',[AppController::class,'scan']);$router->post('/app/scan/manual',[AppController::class,'scanManual']);$router->get('/app/product/{barcode}',[AppController::class,'product']);$router->get('/app/custom/{id}',[AppController::class,'customProduct']);$router->post('/app/review',[AppController::class,'reviewSave']);$router->get('/app/entry/{id}',[AppController::class,'entry']);$router->get('/app/add-custom',[AppController::class,'addCustomForm']);$router->post('/app/add-custom',[AppController::class,'addCustomSubmit']);
$router->get('/app/library',[AppController::class,'library']);$router->get('/app/favorites',[AppController::class,'favorites']);$router->get('/app/buy-again',[AppController::class,'buyAgain']);$router->get('/app/never-again',[AppController::class,'neverAgain']);$router->get('/app/collections',[AppController::class,'collections']);$router->post('/app/collections/create',[AppController::class,'collectionCreate']);$router->get('/app/collections/{id}',[AppController::class,'collectionDetail']);$router->post('/app/collections/add',[AppController::class,'collectionAdd']);$router->post('/app/collections/remove',[AppController::class,'collectionRemove']);$router->post('/app/collections/delete',[AppController::class,'collectionDelete']);
$router->post('/app/shares/create',[AppController::class,'shareCreate']);$router->post('/app/shares/revoke',[AppController::class,'shareRevoke']);
$router->get('/app/shares',[AppController::class,'shares']);
$router->get('/app/battles',[AppController::class,'battles']);$router->post('/app/battles/decide',[AppController::class,'battleDecide']);$router->get('/app/quests',[AppController::class,'quests']);$router->post('/app/quests/complete',[AppController::class,'questComplete']);$router->get('/app/taste-profile',[AppController::class,'tasteProfile']);$router->post('/app/taste-profile/ai',[AppController::class,'tasteInsight']);$router->get('/app/settings',[AppController::class,'settings']);$router->post('/app/settings',[AppController::class,'settingsSave']);$router->get('/app/account',[AppController::class,'account']);$router->post('/app/account/export',[AppController::class,'accountExport']);$router->post('/app/account/delete',[AppController::class,'accountDelete']);$router->get('/media/{kind}/{id}',[AppController::class,'media']);
$router->get('/api/health',[ApiController::class,'health']);$router->get('/api/product/{barcode}',[ApiController::class,'product']);$router->post('/api/reviews/sync',[ApiController::class,'syncReview']);

$match=$router->match($request);if($match===null)Response::html(\SnackQuest\Support\View::render('pages/404',['title'=>'Seite nicht gefunden','flashes'=>[],'isLoggedIn'=>Session::userId()!==null]),404);
[$handler,$params]=$match;[$class,$method]=$handler;$protected=str_starts_with($request->path,'/app')||str_starts_with($request->path,'/media')||(str_starts_with($request->path,'/api/')&&$request->path!=='/api/health');
if($protected&&Session::userId()===null){if($request->wantsJson())Response::json(['error'=>'Nicht angemeldet.'],401);Session::flash('info','Bitte melde dich an, um SnackQuest zu nutzen.');Response::redirect($base,'/login');}
if($request->isPost()&&!\SnackQuest\Http\Csrf::verify($request)){if($request->wantsJson())Response::json(['error'=>'Sitzung abgelaufen. Bitte Seite neu laden.'],419);Session::flash('error','Deine Sitzung ist abgelaufen. Bitte versuch es erneut.');Response::redirect($base,$request->path==='/logout'?'/':$request->path);}
(new $class())->$method($request,$params);
