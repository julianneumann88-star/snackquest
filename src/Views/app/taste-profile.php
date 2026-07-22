<header class="app-page-head">
  <div><p class="eyebrow">Nur aus echten Bewertungen</p><h1>Dein Geschmacksprofil</h1><p><?=e($profile['count'])?> Snacks bilden derzeit deine persönliche Datenbasis.</p></div>
  <div class="big-rating"><strong><?=e($profile['average'])?></strong><span>Ø /10</span></div>
</header>
<?php if(!$profile['count']):?>
  <div class="empty-state"><h2>Noch kein Profil.</h2><p>Nach der ersten Bewertung entstehen hier nachvollziehbare Muster.</p><a class="button" href="<?=u('/app/scan')?>">Ersten Snack scannen</a></div>
<?php else:?>
  <section class="profile-highlight"><div><span>Wiederkaufrate</span><strong><?=e($profile['buy_again_rate'])?>%</strong></div><div><span>Bewertete Produkte</span><strong><?=e($profile['count'])?></strong></div></section>
  <div class="profile-columns">
    <section><p class="eyebrow">Kategorien</p><h2>Was bei dir punktet</h2><ol class="data-ranking"><?php foreach($profile['categories'] as $row):?><li><span><?=e($row['name'])?><small><?=e($row['count'])?> Bewertungen</small></span><strong><?=e($row['average'])?></strong></li><?php endforeach;?></ol></section>
    <section><p class="eyebrow">Marken</p><h2>Deine besten Treffer</h2><ol class="data-ranking"><?php foreach($profile['brands'] as $row):?><li><span><?=e($row['name'])?><small><?=e($row['count'])?> Bewertungen</small></span><strong><?=e($row['average'])?></strong></li><?php endforeach;?></ol></section>
  </div>
  <section class="tag-cloud"><p class="eyebrow">Häufige Tags</p><?php foreach($profile['tags'] as $tag):?><span><?=e($tag['label'])?> <b><?=e($tag['uses'])?></b></span><?php endforeach;?></section>
<?php endif;?>
<section class="app-narrow ai-insight">
  <p class="eyebrow">Optional · lokale GPT-OSS-Bridge</p>
  <h2>Muster in Worte fassen</h2>
  <?php if(!empty($aiInsight)):?><blockquote><?=nl2br(e($aiInsight['result']))?></blockquote><small>Erstellt <?=e(date('d.m.Y',strtotime((string)$aiInsight['created_at'])))?> mit <?=e($aiInsight['model'])?> aus aggregierten Profildaten.</small><?php endif;?>
  <?php if(empty($aiAvailable)):?>
    <p>Die private KI-Verbindung ist derzeit nicht freigeschaltet. Alle normalen Profilwerte funktionieren unabhängig davon.</p>
  <?php elseif(empty($aiOptedIn)):?>
    <p>Diese Funktion bleibt aus, bis du sie in den <a href="<?=u('/app/settings')?>">Einstellungen ausdrücklich aktivierst</a>.</p>
  <?php elseif((int)$profile['count']<3):?>
    <p>Nach mindestens drei Bewertungen kann eine sinnvolle Zusammenfassung entstehen.</p>
  <?php else:?>
    <form method="post" action="<?=u('/app/taste-profile/ai')?>"><?=$csrf?><button class="button button-secondary" type="submit"><?=empty($aiInsight)?'Private Auswertung erstellen':'Auswertung aktualisieren'?></button></form>
  <?php endif;?>
</section>
