<header class="app-page-head"><div><p class="eyebrow">Heute bei SnackQuest</p><h1>Hallo, <?=e($currentUser['display_name']??'Snack-Fan')?>.</h1><p>Was landet als Nächstes in deinem Snack-Gedächtnis?</p></div><a class="button scan-cta" href="<?=u('/app/scan')?>">▦ Snack scannen</a></header>
<section class="stats-strip"><div><strong><?=e($dashboard['stats']['total']??0)?></strong><span>bewertet</span></div><div><strong><?=number_format((float)($dashboard['stats']['avg_rating']??0),1,',','.')?></strong><span>Ø Wertung</span></div><div><strong><?=e($dashboard['stats']['favorites']??0)?></strong><span>Favoriten</span></div><div><strong><?=e($dashboard['stats']['buy_again']??0)?></strong><span>wieder kaufen</span></div></section>
<section class="snack-picker" aria-labelledby="snack-picker-title">
  <div>
    <p class="eyebrow">Snack-Los</p>
    <h2 id="snack-picker-title"><?=!empty($snackPick)?e($snackPick['product_name']):'Unentschlossen? Wirf das Snack-Los.'?></h2>
    <?php if(!empty($snackPick)):?>
      <p><?=e($snackPick['pick_reason'])?> <strong><?=e($snackPick['overall_rating'])?>/10</strong></p>
    <?php elseif(!empty($pickRequested)):?>
      <p>Bewerte zuerst einen Snack mit mindestens 5/10 und ohne „Nie wieder“.</p>
    <?php else:?>
      <p>SnackQuest zieht aus deinen positiven Bewertungen und vermeidet direkte Wiederholungen.</p>
    <?php endif;?>
  </div>
  <div class="snack-picker-actions">
    <?php if(!empty($snackPick)):?><a class="button button-secondary" href="<?=u('/app/entry/'.(int)$snackPick['id'])?>">Snack öffnen</a><?php endif;?>
    <a class="button" href="<?=u('/app?pick=1')?>"><?=!empty($snackPick)?'Neu ziehen':'Snack ziehen'?></a>
  </div>
</section>
<section class="quest-banner"><div class="quest-stamp">QUEST</div><div><p class="eyebrow">Deine aktuelle Aufgabe</p><h2><?=e($quest['title']??'Scanne deinen ersten Snack.')?></h2><p><?=e($quest['progress']??0)?> / <?=e($quest['target']??1)?> geschafft</p></div><a class="text-link" href="<?=u('/app/quests')?>">Quest öffnen →</a></section>
<section class="section-head"><div><p class="eyebrow">Zuletzt probiert</p><h2>Deine jüngsten Urteile</h2></div><a href="<?=u('/app/library')?>">Alle ansehen</a></section><?php if(empty($dashboard['recent'])):?><div class="empty-state"><span class="empty-barcode">|||| || |||</span><h2>Noch keine Bewertung.</h2><p>Dein erster Scan macht diese Seite zu deiner.</p><a class="button" href="<?=u('/app/scan')?>">Ersten Snack scannen</a></div><?php else:?><div class="product-grid"><?php foreach($dashboard['recent'] as $entry)include __DIR__.'/../partials/product-card.php';?></div><?php endif;?>
