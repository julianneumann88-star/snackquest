import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const password = 'SnackQuest-2026-Test';
const mailLog = path.resolve('logs/mails.log');
const fixtureImage = path.resolve('public/assets/icons/icon-192.png');
const sq = (url) => `/snackquest${url}`;

async function latestVerificationUrl(email) {
  return expect.poll(() => {
    let content = '';
    try { content = readFileSync(mailLog, 'utf8'); } catch { return ''; }
    const blocks = content.split(/^=== /m).filter((block) => block.includes(`to=${email}`));
    const match = blocks.at(-1)?.match(/https?:\/\/[^\s]+\/verify\?token=[a-f0-9]{64}/);
    return match?.[0] ?? '';
  }, { timeout: 10_000 }).not.toBe('').then(() => {
    const content = readFileSync(mailLog, 'utf8');
    const blocks = content.split(/^=== /m).filter((block) => block.includes(`to=${email}`));
    return blocks.at(-1).match(/https?:\/\/[^\s]+\/verify\?token=[a-f0-9]{64}/)[0];
  });
}

async function addCustomAndReview(page, name, rating) {
  await page.goto(sq('/app/add-custom'));
  await page.getByLabel('Produktname').fill(name);
  await page.getByLabel('Marke').fill('Testküche');
  await page.getByLabel('Kategorie').fill('Knuspertest');
  await page.getByLabel('Menge').fill('100 g');
  await page.getByLabel('Notiz').fill('Nur in der isolierten E2E-Datenbank.');
  await page.getByLabel('Frontfoto').setInputFiles(fixtureImage);
  await page.getByRole('button', { name: 'Privates Produkt anlegen' }).click();
  await expect(page.getByRole('heading', { name })).toBeVisible();
  await page.locator('.rating-picker label').filter({ hasText: String(rating) }).click();
  await page.locator('.segmented label').filter({ hasText: 'Ja' }).click();
  await page.getByLabel('Geschmack 1–10').fill(String(rating));
  await page.getByLabel('Textur 1–10').fill(String(Math.max(1, rating - 1)));
  await page.getByLabel('Preis-Leistung 1–10').fill('8');
  await page.getByLabel('Preis in €').fill('2,49');
  await page.getByLabel('Kaufort').fill('E2E Markt');
  await page.getByLabel('Favorit').check();
  await page.locator('input[name="movie_night"]').check();
  await page.getByLabel('Notiz').fill('Knusprig, klar bewertet und privat gespeichert.');
  await page.getByLabel('Eigenes Bewertungsfoto').setInputFiles(fixtureImage);
  await page.getByRole('button', { name: 'Bewertung speichern' }).click();
  await expect(page.getByRole('heading', { name })).toBeVisible();
}

test('real account lifecycle and signed-in core flows', async ({ page }, testInfo) => {
  test.setTimeout(90_000);
  const slug = testInfo.project.name.replace(/[^a-z0-9]/gi, '-').toLowerCase();
  const email = `e2e-${slug}-${Date.now()}@example.test`;
  const first = `Citrus Crunch ${slug}`;
  const second = `Berry Bite ${slug}`;

  await page.goto(sq('/register'));
  await page.getByLabel('Anzeigename').fill('E2E Snack-Fan');
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(password);
  await page.getByRole('button', { name: 'Konto erstellen' }).click();
  await expect(page.getByRole('heading', { name: 'Bestätige deine E-Mail-Adresse.' })).toBeVisible();

  const verifyUrl = await latestVerificationUrl(email);
  await page.goto(verifyUrl);
  await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible();
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(password);
  await page.getByRole('button', { name: 'Anmelden' }).click();

  await expect(page.getByRole('heading', { name: 'Worauf springt dein Geschmack an?' })).toBeVisible();
  await page.locator('.chip-picker label').filter({ hasText: 'Süß' }).click();
  await page.locator('.chip-picker label').filter({ hasText: 'Knusprig' }).click();
  await page.locator('input[type="checkbox"][required]').check();
  await page.getByRole('button', { name: 'Profil starten' }).click();
  await expect(page.getByRole('heading', { name: 'Barcode in den Rahmen.' })).toBeVisible();

  await addCustomAndReview(page, first, 9);
  await addCustomAndReview(page, second, 7);

  await page.goto(sq('/app/library'));
  await expect(page.getByText(first, { exact: true })).toBeVisible();
  await expect(page.getByText(second, { exact: true })).toBeVisible();

  await page.goto(sq('/app/collections'));
  await page.getByLabel('Name').fill('Filmabend');
  await page.getByLabel('Beschreibung').fill('Private Favoriten für den nächsten Film.');
  await page.getByRole('button', { name: 'Sammlung anlegen' }).click();
  await page.getByLabel('Snack wählen').selectOption({ label: `${first} · 9/10` });
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.getByText(first, { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Sammlungslink erzeugen' }).click();
  await expect(page.getByRole('heading', { name: 'Dein widerrufbarer Link ist bereit.' })).toBeVisible();
  const shareUrl = await page.locator('[data-share-url]').inputValue();
  const sharedPage = await page.context().newPage();
  await sharedPage.goto(shareUrl);
  await expect(sharedPage.getByRole('heading', { name: 'Filmabend' })).toBeVisible();
  await expect(sharedPage.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex,nofollow');
  await sharedPage.close();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Link sofort widerrufen' }).click();
  await expect(page.getByRole('heading', { name: 'Deine aktiven Freigaben' })).toBeVisible();
  const revokedPage = await page.context().newPage();
  await revokedPage.goto(shareUrl);
  await expect(revokedPage.getByRole('heading', { name: 'Diese Tüte ist leer.' })).toBeVisible();
  await revokedPage.close();

  await page.goto(sq('/app/battles'));
  await expect(page.locator('.battle-card')).toHaveCount(2);
  await page.locator('.battle-card').first().click();
  await expect(page.getByText(/Duell gewertet/)).toBeVisible();

  await page.goto(sq('/app/taste-profile'));
  await expect(page.getByRole('heading', { name: 'Dein Geschmacksprofil' })).toBeVisible();
  await page.screenshot({ path: `reports/visual/app-profile-${slug}.png`, fullPage: true });

  await page.goto(sq('/app/account'));
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'JSON exportieren' }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/^snackquest-export-\d{4}-\d{2}-\d{2}\.json$/);
  const exportPath = await download.path();
  const exported = JSON.parse(readFileSync(exportPath, 'utf8'));
  expect(exported.profile.email).toBe(email);
  expect(exported.reviews).toHaveLength(2);
  expect(exported.collection_items).toHaveLength(1);
  expect(exported.battle_pairs).toHaveLength(1);

  page.on('dialog', (dialog) => dialog.accept());
  await page.getByLabel(/Zur Bestätigung/).fill('KONTO LÖSCHEN');
  await page.getByRole('button', { name: 'Konto endgültig löschen' }).click();
  await expect(page.getByRole('heading', { name: /Guter Snack/ })).toBeVisible();
  await page.goto(sq('/app'));
  await expect(page).toHaveURL(/\/snackquest\/login$/);
});
