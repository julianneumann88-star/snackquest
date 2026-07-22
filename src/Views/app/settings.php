<section class="app-narrow">
  <p class="eyebrow">Privatsphäre und Komfort</p>
  <h1>Einstellungen</h1>
  <form method="post" action="<?=u('/app/settings')?>" class="form-stack">
    <?=$csrf?>
    <label class="toggle-row">
      <span>
        <strong>Lokale KI-Auswertung</strong>
        <small>Optional und standardmäßig aus. Nur begrenzte, aggregierte Bewertungsmuster werden an die private lokale KI-Bridge gesendet – niemals E-Mail, Notizen, Preise, Fotos oder Produktkennungen. Die App funktioniert vollständig ohne KI.<?=empty($aiAvailable)?' Die Bridge ist momentan nicht freigeschaltet.':''?></small>
      </span>
      <input type="checkbox" name="ai_opt_in" value="1" <?=!empty($prefs['ai_opt_in'])?'checked':''?> <?=empty($aiAvailable)?'disabled':''?>>
    </label>
    <button class="button" type="submit">Einstellungen speichern</button>
  </form>
  <hr>
  <p><a href="<?=u('/app/shares')?>">Aktive Freigabelinks verwalten →</a></p>
  <a href="<?=u('/app/account')?>">Export und Kontolöschung →</a>
</section>
