<?php
$title=$title??'SnackQuest';
$description=$description??'Dein persönliches Snack-Gedächtnis.';
$flashes=$flashes??[];
$isLoggedIn=$isLoggedIn??false;
$siteBase=rtrim((string)\SnackQuest\App::$config->get('app_base_url','https://julian-neumann.org/snackquest'),'/');
$requestPath=(string)(parse_url($_SERVER['REQUEST_URI']??'/snackquest',PHP_URL_PATH)?:'/snackquest');
$basePath=(string)\SnackQuest\App::$config->get('base_path','/snackquest');
$localPath=$requestPath===$basePath?'/':(str_starts_with($requestPath,$basePath)?substr($requestPath,strlen($basePath)):$requestPath);
$indexable=in_array($localPath,['/','/features','/how-it-works','/about','/privacy','/terms','/imprint','/credits','/status'],true);
$canonical=$siteBase.($localPath==='/'?'':$localPath);
$ogImage=$siteBase.'/assets/images/og-snackquest.png';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#fffdf5">
  <meta name="description" content="<?=e($description)?>">
  <meta name="robots" content="<?=$indexable?'index,follow':'noindex,nofollow'?>">
  <link rel="canonical" href="<?=e($canonical)?>">
  <meta property="og:locale" content="de_DE">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="SnackQuest">
  <meta property="og:title" content="<?=e($title)?>">
  <meta property="og:description" content="<?=e($description)?>">
  <meta property="og:image" content="<?=e($ogImage)?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="SnackQuest – Scannen, bewerten und wiederfinden">
  <meta property="og:url" content="<?=e($canonical)?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?=e($title)?>">
  <meta name="twitter:description" content="<?=e($description)?>">
  <meta name="twitter:image" content="<?=e($ogImage)?>">
  <meta name="twitter:image:alt" content="SnackQuest – Scannen, bewerten und wiederfinden">
  <title><?=e($title)?></title>
  <link rel="manifest" href="<?=u('/manifest.webmanifest')?>">
  <link rel="icon" href="<?=u('/assets/icons/favicon-32.png')?>" sizes="32x32" type="image/png">
  <link rel="icon" href="<?=u('/assets/icons/favicon-64.png')?>" sizes="64x64" type="image/png">
  <link rel="apple-touch-icon" href="<?=u('/assets/icons/icon-180.png')?>">
  <link rel="stylesheet" href="<?=u('/assets/css/app.css')?>?v=<?=e(\SnackQuest\Support\View::$appVersion)?>">
  <script defer src="<?=u('/assets/js/app.js')?>?v=<?=e(\SnackQuest\Support\View::$appVersion)?>"></script>
  <script defer src="<?=u('/assets/js/share.js')?>?v=<?=e(\SnackQuest\Support\View::$appVersion)?>"></script>
  <?php if($localPath==='/'):?><script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'WebApplication','name'=>'SnackQuest','url'=>$siteBase,'applicationCategory'=>'LifestyleApplication','operatingSystem'=>'Any','description'=>$description,'offers'=>['@type'=>'Offer','price'=>'0','priceCurrency'=>'EUR']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script><?php endif;?>
</head>
<body class="public-body">
  <a class="skip-link" href="#main">Zum Inhalt springen</a>
  <header class="site-header"><a class="brand" href="<?=u('/')?>" aria-label="SnackQuest Startseite"><?php include __DIR__.'/../partials/logo.php';?></a><nav class="site-nav" aria-label="Hauptnavigation"><a href="<?=u('/features')?>">Funktionen</a><a href="<?=u('/how-it-works')?>">So geht’s</a><a href="<?=u('/about')?>">Über</a><?php if($isLoggedIn):?><a class="button button-small" href="<?=u('/app')?>">Zur App</a><?php else:?><a href="<?=u('/login')?>">Anmelden</a><a class="button button-small" href="<?=u('/register')?>">Loslegen</a><?php endif;?></nav><button class="nav-toggle" type="button" aria-expanded="false" aria-label="Navigation öffnen">Menü</button></header>
  <?php foreach($flashes as $flash):?><div class="flash flash-<?=e($flash['type']??'info')?>" role="status"><?=e($flash['text']??'')?></div><?php endforeach;?>
  <main id="main"><?=$content?></main>
  <footer class="site-footer"><div><strong>SnackQuest</strong><p>Scannen. Bewerten. Wiederfinden.</p></div><nav aria-label="Rechtliches"><a href="<?=u('/privacy')?>">Datenschutz</a><a href="<?=u('/terms')?>">Nutzung</a><a href="<?=u('/imprint')?>">Impressum</a><a href="<?=u('/credits')?>">Credits</a><a href="<?=u('/status')?>">Status</a></nav><p class="footer-note">Produktdaten können unvollständig sein. Im Zweifel gilt die Verpackung.</p></footer>
</body>
</html>
