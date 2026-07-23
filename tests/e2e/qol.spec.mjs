import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const password = 'SnackQuest-2026-Test';
const mailLog = path.resolve('logs/mails.log');
const sq = (url) => `/snackquest${url}`;

async function latestVerificationUrl(email) {
  await expect.poll(() => {
    let content = '';
    try { content = readFileSync(mailLog, 'utf8'); } catch { return ''; }
    const block = content.split(/^=== /m).filter((part) => part.includes(`to=${email}`)).at(-1);
    return block?.match(/https?:\/\/[^\s]+\/verify\?token=[a-f0-9]{64}/)?.[0] ?? '';
  }, { timeout: 10_000 }).not.toBe('');
  const content = readFileSync(mailLog, 'utf8');
  const block = content.split(/^=== /m).filter((part) => part.includes(`to=${email}`)).at(-1);
  return block.match(/https?:\/\/[^\s]+\/verify\?token=[a-f0-9]{64}/)[0];
}

async function createAccount(page, email) {
  await page.goto(sq('/register'));
  await page.getByLabel('Anzeigename').fill('QoL Snack-Fan');
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(password);
  await page.getByRole('button', { name: 'Konto erstellen' }).click();
  await page.goto(await latestVerificationUrl(email));
  await page.getByLabel('E-Mail').fill(email);
  await page.getByLabel('Passwort').fill(password);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await page.locator('.chip-picker label').filter({ hasText: 'Knusprig' }).click();
  await page.locator('input[type="checkbox"][required]').check();
  await page.getByRole('button', { name: 'Profil starten' }).click();
}

async function createCustom(page, name) {
  await page.goto(sq('/app/add-custom'));
  await page.getByLabel('Produktname').fill(name);
  await page.getByLabel('Marke').fill('QoL Test');
  await page.getByRole('button', { name: 'Privates Produkt anlegen' }).click();
  await expect(page.getByRole('heading', { name })).toBeVisible();
}

async function fillReview(page, rating, note) {
  await page.locator('.rating-picker label').filter({ hasText: String(rating) }).click();
  await page.locator('.segmented label').filter({ hasText: 'Ja' }).click();
  await page.getByLabel('Notiz').fill(note);
}

test('review drafts, offline custom sync, active navigation and snack picker work together', async ({ page, context }, testInfo) => {
  test.setTimeout(90_000);
  const slug = `${testInfo.project.name.replace(/[^a-z0-9]/gi, '-').toLowerCase()}-${Date.now()}`;
  await createAccount(page, `qol-${slug}@example.test`);

  await createCustom(page, `Draft Crunch ${slug}`);
  await fillReview(page, 9, 'Dieser lokale Entwurf muss einen Reload überleben.');
  await expect(page.locator('[data-sync-status]')).toContainText('Entwurf lokal gespeichert');
  await page.reload();
  await expect(page.getByLabel('Notiz')).toHaveValue('Dieser lokale Entwurf muss einen Reload überleben.');
  await expect(page.locator('input[name="overall_rating"][value="9"]')).toBeChecked();
  await expect(page.locator('[data-sync-status]')).toContainText('wiederhergestellt');
  await page.getByLabel('Favorit').check();
  await page.getByRole('button', { name: 'Bewertung speichern' }).click();
  await page.getByRole('link', { name: 'Bewertung bearbeiten' }).click();
  await expect(page.getByLabel('Favorit')).toBeChecked();
  await page.getByLabel('Favorit').uncheck();
  await page.locator('.segmented label').filter({ hasText: 'Vielleicht' }).click();
  await expect(page.locator('[data-sync-status]')).toContainText('Entwurf lokal gespeichert');
  await page.reload();
  await expect(page.getByLabel('Favorit')).not.toBeChecked();
  await expect(page.locator('input[name="buy_again"][value="maybe"]')).toBeChecked();
  await page.getByRole('button', { name: 'Bewertung aktualisieren' }).click();

  await createCustom(page, `Offline Bite ${slug}`);
  await context.setOffline(true);
  await fillReview(page, 8, 'Wird sofort als Custom-Produkt offline synchronisiert.');
  const offlineReviewKey = await page.locator('[data-offline-review]').getAttribute('data-review-key');
  await page.getByRole('button', { name: 'Bewertung speichern' }).click();
  await expect(page.locator('[data-sync-status]')).toContainText('offline vorgemerkt');
  await expect(page.getByLabel('Notiz')).toBeDisabled();
  await expect(page.locator('.review-submit-bar button')).toHaveText('Offline vorgemerkt');
  await context.setOffline(false);
  await expect(page.locator('[data-sync-status]')).toContainText('sicher synchronisiert', { timeout: 15_000 });
  await expect(page.getByLabel('Notiz')).toBeEnabled();
  await expect(page.locator('.review-submit-bar button')).toHaveText('Bewertung speichern');
  const staleOfflineDraft = await page.evaluate(async ({ offlineReviewKey }) => {
    const userId = document.body.dataset.userId;
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open(`snackquest-offline-${userId}`, 1);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const rows = await new Promise((resolve, reject) => {
      const request = database.transaction('drafts').objectStore('drafts').getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
    database.close();
    return rows.some((row) => row.reviewKey === offlineReviewKey);
  }, { offlineReviewKey });
  expect(staleOfflineDraft).toBe(false);

  await createCustom(page, `Picker Bar ${slug}`);
  await fillReview(page, 7, 'Dritter positiver Kandidat.');
  await page.getByRole('button', { name: 'Bewertung speichern' }).click();

  await page.goto(sq('/app/library'));
  await expect(page.locator('.bottom-nav a[aria-current="page"]')).toContainText('Bibliothek');

  const picks = [];
  for (let index = 0; index < 4; index += 1) {
    await page.goto(sq('/app?pick=1'));
    const href = await page.getByRole('link', { name: 'Snack öffnen' }).getAttribute('href');
    picks.push(href);
  }
  expect(new Set(picks.slice(0, 3)).size).toBe(3);
  expect(picks[3]).not.toBe(picks[2]);
});

test('offline drafts remain isolated across an unclean account switch', async ({ page, context }, testInfo) => {
  test.setTimeout(90_000);
  const slug = `${testInfo.project.name.replace(/[^a-z0-9]/gi, '-').toLowerCase()}-isolation-${Date.now()}`;
  await createAccount(page, `isolation-a-${slug}@example.test`);
  const firstUser = await page.locator('body').getAttribute('data-user-id');
  expect(firstUser).toMatch(/^\d+$/);

  await page.evaluate(async ({ firstUser }) => {
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open(`snackquest-offline-${firstUser}`, 1);
      request.onupgradeneeded = () => request.result.createObjectStore('drafts', { keyPath: 'id' });
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    await new Promise((resolve, reject) => {
      const transaction = database.transaction('drafts', 'readwrite');
      transaction.objectStore('drafts').put({
        id: 'working:isolation-sentinel',
        kind: 'working',
        ownerUserId: firstUser,
        reviewKey: 'isolation-sentinel',
        at: Date.now(),
        data: { note: 'Privater Entwurf von Konto A' },
      });
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
    database.close();
  }, { firstUser });

  // Simulate session expiry/browser cookie loss instead of the clean logout path.
  await context.clearCookies();
  await createAccount(page, `isolation-b-${slug}@example.test`);
  const secondUser = await page.locator('body').getAttribute('data-user-id');
  expect(secondUser).toMatch(/^\d+$/);
  expect(secondUser).not.toBe(firstUser);

  const leaked = await page.evaluate(async ({ secondUser }) => {
    const database = await new Promise((resolve, reject) => {
      const request = indexedDB.open(`snackquest-offline-${secondUser}`, 1);
      request.onupgradeneeded = () => request.result.createObjectStore('drafts', { keyPath: 'id' });
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const row = await new Promise((resolve, reject) => {
      const request = database.transaction('drafts').objectStore('drafts').get('working:isolation-sentinel');
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
    database.close();
    return row;
  }, { secondUser });
  expect(leaked).toBeNull();
});
