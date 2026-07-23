(() => {
  'use strict';

  const qs = (selector, context = document) => context.querySelector(selector);
  const qsa = (selector, context = document) => [...context.querySelectorAll(selector)];
  const nav = qs('.site-nav');
  const toggle = qs('.nav-toggle');

  if (nav && toggle) {
    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
  }

  qsa('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!confirm(form.dataset.confirm || 'Wirklich fortfahren?')) event.preventDefault();
  }));

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/snackquest/sw.js', { scope: '/snackquest/' }).catch(() => {});
  }

  let installPrompt = null;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    const visits = Number(localStorage.getItem('sq_visits') || 0) + 1;
    localStorage.setItem('sq_visits', String(visits));
    if (visits < 3 || qs('[data-install]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'install-toast';
    button.dataset.install = '';
    button.setAttribute('aria-label', 'SnackQuest als App installieren');
    button.textContent = 'SnackQuest installieren';
    button.addEventListener('click', async () => {
      if (!installPrompt) return;
      await installPrompt.prompt();
      installPrompt = null;
      button.remove();
    });
    document.body.append(button);
  });

  const gentleHaptic = () => {
    if (!window.matchMedia('(prefers-reduced-motion: no-preference)').matches) return;
    if (navigator.vibrate) navigator.vibrate(18);
  };
  qsa('.rating-picker input,.segmented input,.chip-picker input,.battle-card').forEach((control) => {
    control.addEventListener('change', gentleHaptic);
    if (control.matches('.battle-card')) control.addEventListener('pointerup', gentleHaptic);
  });

  qsa('form.battle-stage').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = '1';
      form.setAttribute('aria-busy', 'true');
      const submitter = event.submitter;
      if (submitter?.name) {
        const submittedValue = document.createElement('input');
        submittedValue.type = 'hidden';
        submittedValue.name = submitter.name;
        submittedValue.value = submitter.value;
        form.append(submittedValue);
      }
      qsa('button[type="submit"]', form).forEach((button) => {
        button.disabled = true;
      });
    });
  });

  const scanner = qs('[data-scanner]');
  if (scanner) {
    const start = qs('[data-scan-start]', scanner);
    const stop = qs('[data-scan-stop]', scanner);
    const video = qs('video', scanner);
    const status = qs('.scanner-status', scanner);
    const image = qs('[data-scan-image]', scanner);
    const base = scanner.dataset.base || '/snackquest';
    let instance = null;
    let redirecting = false;
    let scannerGeneration = 0;
    const resetScanner = (message = 'Kamera ist aus.') => {
      scannerGeneration += 1;
      const active = instance;
      instance = null;
      active?.stop();
      start.hidden = false;
      start.disabled = false;
      stop.hidden = true;
      status.textContent = message;
    };
    const found = (code) => {
      if (redirecting) return;
      redirecting = true;
      status.textContent = `Code erkannt: ${code}`;
      if (navigator.vibrate) navigator.vibrate(40);
      setTimeout(() => location.assign(`${base}/app/product/${encodeURIComponent(code)}`), 250);
    };
    start?.addEventListener('click', async () => {
      if (!window.SnackQuestScanner) {
        status.textContent = 'Scanner wird noch geladen. Bitte kurz warten.';
        return;
      }
      start.disabled = true;
      status.textContent = 'Kamera wird gestartet …';
      const generation = ++scannerGeneration;
      try {
        const nextInstance = new window.SnackQuestScanner();
        instance = nextInstance;
        await nextInstance.start(video, found, (text) => {
          if (generation === scannerGeneration) status.textContent = text;
        });
        if (generation !== scannerGeneration || document.hidden) {
          nextInstance.stop();
          if (instance === nextInstance) instance = null;
          return;
        }
        start.hidden = true;
        stop.hidden = false;
      } catch (error) {
        if (generation !== scannerGeneration) return;
        instance = null;
        status.textContent = error?.name === 'NotAllowedError'
          ? 'Kamerazugriff wurde abgelehnt. Nutze den manuellen Code oder erlaube die Kamera in den Browser-Einstellungen.'
          : 'Kamera konnte nicht gestartet werden. Nutze den manuellen Barcode.';
        start.disabled = false;
      }
    });
    stop?.addEventListener('click', () => resetScanner());
    image?.addEventListener('change', async () => {
      const file = image.files?.[0];
      if (!file) return;
      if (!window.SnackQuestScanner) {
        status.textContent = 'Scanner noch nicht bereit.';
        return;
      }
      try {
        instance ??= new window.SnackQuestScanner();
        status.textContent = 'Bild wird lokal ausgewertet …';
        found(await instance.decodeImage(file));
      } catch {
        status.textContent = 'Auf diesem Bild wurde kein unterstützter Barcode erkannt.';
      } finally {
        image.value = '';
      }
    });
    window.addEventListener('pagehide', () => resetScanner('Kamera ist pausiert.'));
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) resetScanner('Kamera ist pausiert. Tippe zum erneuten Starten auf „Kamera starten“.');
    });
  }

  const userId = document.body.dataset.userId || 'public';
  const DB_NAME = `snackquest-offline-${userId}`;
  const STORE = 'drafts';
  const REVIEW_SYNC_URL = '/snackquest/api/reviews/sync';

  function openDb() {
    return new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) {
        reject(new Error('IndexedDB unavailable'));
        return;
      }
      const request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = () => {
        if (!request.result.objectStoreNames.contains(STORE)) {
          request.result.createObjectStore(STORE, { keyPath: 'id' });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function putDraft(data) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction(STORE, 'readwrite');
      transaction.objectStore(STORE).put({ ...data, ownerUserId: userId });
      transaction.oncomplete = () => {
        db.close();
        resolve();
      };
      transaction.onerror = () => {
        db.close();
        reject(transaction.error);
      };
    });
  }

  async function getDraft(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const request = db.transaction(STORE).objectStore(STORE).get(id);
      request.onsuccess = () => {
        const result = request.result || null;
        db.close();
        if (result && result.ownerUserId !== userId) {
          removeDraft(id).catch(() => {});
          resolve(null);
          return;
        }
        resolve(result);
      };
      request.onerror = () => {
        db.close();
        reject(request.error);
      };
    });
  }

  async function allDrafts() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const request = db.transaction(STORE).objectStore(STORE).getAll();
      request.onsuccess = () => {
        const rows = request.result || [];
        const owned = rows.filter((row) => row.ownerUserId === userId);
        const foreignOrLegacy = rows.filter((row) => row.ownerUserId !== userId);
        db.close();
        foreignOrLegacy.forEach((row) => {
          if (typeof row.id === 'string') removeDraft(row.id).catch(() => {});
        });
        resolve(owned);
      };
      request.onerror = () => {
        db.close();
        reject(request.error);
      };
    });
  }

  async function removeDraft(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction(STORE, 'readwrite');
      transaction.objectStore(STORE).delete(id);
      transaction.oncomplete = () => {
        db.close();
        resolve();
      };
      transaction.onerror = () => {
        db.close();
        reject(transaction.error);
      };
    });
  }

  async function removeDraftIfWriteToken(id, writeToken) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction(STORE, 'readwrite');
      const store = transaction.objectStore(STORE);
      const request = store.get(id);
      request.onsuccess = () => {
        if (request.result?.ownerUserId === userId && request.result?.writeToken === writeToken) {
          store.delete(id);
        }
      };
      transaction.oncomplete = () => {
        db.close();
        resolve();
      };
      transaction.onerror = () => {
        db.close();
        reject(transaction.error);
      };
    });
  }

  function deleteOfflineDb() {
    return new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) {
        resolve();
        return;
      }
      const request = indexedDB.deleteDatabase(DB_NAME);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error || new Error('Offline-Speicher konnte nicht gelöscht werden.'));
      request.onblocked = () => reject(new Error('Offline-Speicher wird noch verwendet.'));
    });
  }

  function clearLocalAppState() {
    try {
      Object.keys(localStorage)
        .filter((key) => key.startsWith('sq_'))
        .forEach((key) => localStorage.removeItem(key));
    } catch {
      // Private browsing can make localStorage unavailable.
    }
  }

  const workingDraftId = (reviewKey) => `working:${reviewKey}`;
  const reviewClearMarkerId = (reviewKey) => `sq_review_clear:${reviewKey}`;

  function rememberReviewClear(reviewKey, beforeOrAt) {
    try {
      sessionStorage.setItem(reviewClearMarkerId(reviewKey), String(beforeOrAt));
    } catch {
      // Session storage may be unavailable in hardened/private browser modes.
    }
  }

  function reviewClearedBefore(reviewKey) {
    try {
      return Number(sessionStorage.getItem(reviewClearMarkerId(reviewKey)) || 0);
    } catch {
      return 0;
    }
  }

  function collectValues(form, includeHidden = false) {
    const values = {};
    qsa('[name]', form).forEach((field) => {
      const type = (field.getAttribute('type') || '').toLowerCase();
      if (type === 'file' || (!includeHidden && type === 'hidden')) return;
      if (!includeHidden && field.name === '_csrf') return;
      if (type === 'checkbox' || type === 'radio') {
        if (!Object.hasOwn(values, field.name)) values[field.name] = [];
        if (field.checked) values[field.name].push(field.value);
        return;
      }
      const value = field.value;
      if (Object.hasOwn(values, field.name)) {
        values[field.name] = Array.isArray(values[field.name])
          ? [...values[field.name], value]
          : [values[field.name], value];
      } else {
        values[field.name] = value;
      }
    });
    return values;
  }

  function restoreValues(form, values) {
    Object.entries(values || {}).forEach(([name, stored]) => {
      const fields = qsa('[name]', form).filter((field) => field.name === name);
      const wanted = Array.isArray(stored) ? stored.map(String) : [String(stored)];
      fields.forEach((field) => {
        const type = (field.getAttribute('type') || '').toLowerCase();
        if (type === 'checkbox' || type === 'radio') field.checked = wanted.includes(field.value);
        else if (type !== 'file' && type !== 'hidden') field.value = wanted[0] || '';
      });
    });
  }

  function toUrlSearchParams(values) {
    const params = new URLSearchParams();
    Object.entries(values).forEach(([name, value]) => {
      if (Array.isArray(value)) value.forEach((item) => params.append(name, item));
      else params.append(name, value);
    });
    return params;
  }

  async function clearDraftsForReview(reviewKey, beforeOrAt = Number.POSITIVE_INFINITY) {
    if (!reviewKey) return;
    const related = (await allDrafts()).filter((draft) => (
      draft.reviewKey === reviewKey || draft.id === workingDraftId(reviewKey)
    ) && Number(draft.at || 0) <= beforeOrAt);
    await Promise.all(related.map((draft) => removeDraft(draft.id)));
  }

  function setReviewLocked(form, locked) {
    form.dataset.pendingQueue = locked ? '1' : '0';
    if (!locked) form.dataset.submitting = '0';
    const submit = qs('[type="submit"]', form);
    if (submit && !submit.dataset.originalText) {
      submit.dataset.originalText = submit.textContent || 'Bewertung speichern';
    }
    qsa('input,select,textarea,button', form).forEach((control) => {
      control.disabled = locked;
    });
    if (!locked && submit?.dataset.originalText) {
      submit.textContent = submit.dataset.originalText;
    }
  }

  const clearReviewKey = document.body.dataset.clearReviewDraft || '';
  const clearReviewBefore = Number(document.body.dataset.clearReviewBefore || 0);
  if (clearReviewKey && clearReviewBefore > 0) {
    rememberReviewClear(clearReviewKey, clearReviewBefore);
    clearDraftsForReview(clearReviewKey, clearReviewBefore).catch(() => {});
  }

  qsa('[data-offline-review]').forEach((form) => {
    const reviewKey = form.dataset.reviewKey || '';
    const draftId = workingDraftId(reviewKey);
    const status = qs('[data-sync-status]', form);
    let saveTimer = 0;
    let saveGeneration = 0;

    const showStatus = (text, state = '') => {
      if (!status) return;
      status.textContent = text;
      status.dataset.state = state;
    };

    getDraft(draftId).then(async (draft) => {
      if (!draft) {
        const pending = (await allDrafts())
          .filter((item) => ['queue', 'failed', 'conflict'].includes(item.kind) && item.reviewKey === reviewKey)
          .sort((a, b) => Number(b.at || 0) - Number(a.at || 0))[0];
        if (pending) {
          restoreValues(form, pending.data);
          setReviewLocked(form, pending.kind === 'queue');
          showStatus(
            pending.kind === 'conflict'
              ? 'Konflikt: Auf dem Server liegt eine neuere Bewertung. Prüfe deinen lokalen Entwurf und speichere bewusst erneut.'
              : pending.kind === 'queue'
                ? 'Diese Bewertung wartet lokal auf eine sichere Synchronisierung.'
                : 'Ein Offline-Entwurf braucht deine Prüfung. Kontrolliere die Angaben und speichere erneut.',
            pending.kind === 'queue' ? 'queued' : 'error',
          );
        }
        return;
      }
      const serverUpdated = Number(form.dataset.serverUpdated || 0) * 1000;
      const clearedBefore = reviewClearedBefore(reviewKey);
      const expired = Date.now() - Number(draft.at || 0) > 7 * 24 * 60 * 60 * 1000;
      if (expired || Number(draft.at || 0) <= Math.max(serverUpdated, clearedBefore)) {
        await removeDraft(draftId);
        return;
      }
      restoreValues(form, draft.data);
      showStatus('Dein lokaler Entwurf wurde wiederhergestellt.', 'restored');
    }).catch(() => {
      showStatus('Lokale Zwischenspeicherung ist in diesem Browser nicht verfügbar.', 'error');
    });

    const scheduleDraftSave = () => {
      if (form.dataset.pendingQueue === '1') return;
      window.clearTimeout(saveTimer);
      const generation = ++saveGeneration;
      showStatus('Änderungen werden lokal gespeichert …', 'saving');
      saveTimer = window.setTimeout(async () => {
        const writeToken = crypto.randomUUID();
        const draftAt = Date.now();
        if (generation !== saveGeneration || form.dataset.pendingQueue === '1') return;
        try {
          await putDraft({
            id: draftId,
            kind: 'working',
            reviewKey,
            at: draftAt,
            writeToken,
            data: collectValues(form),
          });
          if (generation !== saveGeneration || form.dataset.pendingQueue === '1') {
            await removeDraftIfWriteToken(draftId, writeToken);
            return;
          }
          showStatus(`Entwurf lokal gespeichert · ${new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`, 'saved');
        } catch {
          if (generation !== saveGeneration || form.dataset.pendingQueue === '1') return;
          showStatus('Lokale Zwischenspeicherung ist in diesem Browser nicht verfügbar.', 'error');
        }
      }, 350);
    };
    form.addEventListener('input', scheduleDraftSave);
    form.addEventListener('change', scheduleDraftSave);

    form.addEventListener('submit', async (event) => {
      const submit = qs('[type="submit"]', form);
      window.clearTimeout(saveTimer);
      saveGeneration += 1;
      if (navigator.onLine) {
        if (form.dataset.submitting === '1') {
          event.preventDefault();
          return;
        }
        let clientDraftAt = qs('input[name="_client_draft_at"]', form);
        if (!clientDraftAt) {
          clientDraftAt = document.createElement('input');
          clientDraftAt.type = 'hidden';
          clientDraftAt.name = '_client_draft_at';
          form.append(clientDraftAt);
        }
        clientDraftAt.value = String(Date.now());
        form.dataset.submitting = '1';
        if (submit) {
          submit.disabled = true;
          submit.textContent = 'Wird gespeichert …';
        }
        showStatus('Bewertung wird sicher gespeichert …', 'saving');
        return;
      }
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      form.dataset.submitting = '1';
      if (submit) submit.disabled = true;
      const hasPhoto = Boolean(qs('input[type="file"]', form)?.files?.length);
      try {
        const syncId = crypto.randomUUID();
        const data = collectValues(form, true);
        data._sync_id = syncId;
        data._base_updated_at = form.dataset.serverUpdated || '0';
        await clearDraftsForReview(reviewKey);
        await putDraft({
          id: `queue:${syncId}`,
          kind: 'queue',
          reviewKey,
          at: Date.now(),
          url: REVIEW_SYNC_URL,
          missingPhoto: hasPhoto,
          data,
        });
        setReviewLocked(form, true);
        if (submit) submit.textContent = 'Offline vorgemerkt';
        showStatus(
          hasPhoto
            ? 'Bewertung offline vorgemerkt. Das Foto kann nicht offline gespeichert werden und muss später erneut gewählt werden.'
            : 'Bewertung offline vorgemerkt. Sie wird automatisch gesendet, sobald du wieder online bist.',
          'queued',
        );
      } catch {
        form.dataset.submitting = '0';
        if (submit) submit.disabled = false;
        showStatus('Offline-Speichern ist hier nicht verfügbar. Bitte bleib auf der Seite und versuche es mit Verbindung erneut.', 'error');
      }
    });
  });

  let syncing = false;
  async function syncDrafts() {
    if (syncing || !navigator.onLine || userId === 'public') return;
    syncing = true;
    try {
      const queued = (await allDrafts())
        .filter((draft) => (draft.kind || 'queue') === 'queue')
        .sort((a, b) => Number(a.at || 0) - Number(b.at || 0));
      for (const draft of queued) {
        try {
          const currentCsrf = qs('input[name="_csrf"]')?.value || draft.data?._csrf || '';
          const params = toUrlSearchParams(draft.data || {});
          params.set('_csrf', currentCsrf);
          const response = await fetch(REVIEW_SYNC_URL, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-CSRF-Token': currentCsrf,
            },
            body: params,
          });
          if (response.ok) {
            const result = await response.json().catch(() => null);
            if (!result || result.ok !== true || !Number.isInteger(Number(result.entry_id))) {
              break;
            }
            if (draft.reviewKey) await clearDraftsForReview(draft.reviewKey, Number(draft.at || 0));
            else await removeDraft(draft.id);
            document.dispatchEvent(new CustomEvent('snackquest:review-synced', {
              detail: {
                reviewKey: draft.reviewKey,
                serverUpdatedAt: Number(result.server_updated_at || 0),
                photoNeedsReselect: Boolean(draft.missingPhoto),
              },
            }));
          } else if (response.status === 401 || response.status === 419) {
            break;
          } else if (response.status === 409) {
            draft.kind = 'conflict';
            draft.conflictAt = Date.now();
            await putDraft(draft);
            const conflictForm = draft.reviewKey ? qs(`[data-review-key="${CSS.escape(draft.reviewKey)}"]`) : null;
            const conflictStatus = conflictForm ? qs('[data-sync-status]', conflictForm) : null;
            if (conflictForm) setReviewLocked(conflictForm, false);
            if (conflictStatus) {
              conflictStatus.textContent = 'Konflikt: Die Serverbewertung ist neuer. Dein lokaler Entwurf bleibt erhalten und wird nicht überschrieben.';
              conflictStatus.dataset.state = 'error';
            }
          } else if (response.status === 422) {
            draft.kind = 'failed';
            draft.failedAt = Date.now();
            await putDraft(draft);
            const failedForm = draft.reviewKey ? qs(`[data-review-key="${CSS.escape(draft.reviewKey)}"]`) : null;
            if (failedForm) setReviewLocked(failedForm, false);
            const failedStatus = failedForm ? qs('[data-sync-status]', failedForm) : null;
            if (failedStatus) {
              failedStatus.textContent = 'Die Offline-Bewertung enthält ungültige oder unvollständige Angaben. Dein Entwurf bleibt erhalten – bitte prüfe ihn und speichere erneut.';
              failedStatus.dataset.state = 'error';
            }
          } else {
            break;
          }
        } catch {
          break;
        }
      }
    } finally {
      syncing = false;
    }
  }

  document.addEventListener('snackquest:review-synced', (event) => {
    const form = qs(`[data-review-key="${CSS.escape(event.detail.reviewKey || '')}"]`);
    const status = form ? qs('[data-sync-status]', form) : null;
    if (form) {
      setReviewLocked(form, false);
      if (Number(event.detail.serverUpdatedAt) > 0) {
        form.dataset.serverUpdated = String(Number(event.detail.serverUpdatedAt));
      }
    }
    if (status) {
      status.textContent = event.detail.photoNeedsReselect
        ? 'Bewertung synchronisiert. Das Foto war nicht im Offline-Entwurf und muss bei Bedarf erneut ausgewählt werden.'
        : 'Offline-Bewertung wurde sicher synchronisiert.';
      status.dataset.state = 'saved';
    }
  });
  window.addEventListener('online', () => syncDrafts().catch(() => {}));
  syncDrafts().catch(() => {});

  qsa('form[action$="/logout"]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (event.defaultPrevented || form.dataset.submitting === '1') return;
      event.preventDefault();
      form.dataset.submitting = '1';
      const button = qs('[type="submit"]', form);
      const status = qs('[data-logout-status]', form);
      if (button) button.disabled = true;
      if (status) status.textContent = 'Abmeldung wird bestätigt …';
      try {
        const csrf = qs('input[name="_csrf"]', form)?.value || '';
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: new FormData(form),
          credentials: 'same-origin',
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || result?.ok !== true) {
          throw new Error('Serverabmeldung nicht bestätigt');
        }
        let cleanupFailed = false;
        try {
          await deleteOfflineDb();
        } catch {
          cleanupFailed = true;
        }
        clearLocalAppState();
        if (cleanupFailed) {
          if (status) status.textContent = 'Server-Abmeldung bestätigt. Der lokale Offline-Speicher konnte nicht vollständig bereinigt werden.';
          window.setTimeout(() => location.assign(result.redirect || '/snackquest/'), 1200);
        } else {
          location.assign(result.redirect || '/snackquest/');
        }
      } catch {
        form.dataset.submitting = '0';
        if (button) button.disabled = false;
        if (status) {
          status.textContent = navigator.onLine
            ? 'Abmeldung konnte nicht bestätigt werden. Deine Offline-Daten bleiben sicher erhalten.'
            : 'Du bist offline. Deine Entwürfe bleiben erhalten; melde dich mit Verbindung erneut ab.';
        }
      }
    });
  });

  qsa('form[data-local-account-delete]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (event.defaultPrevented || form.dataset.submitting === '1') return;
      event.preventDefault();
      form.dataset.submitting = '1';
      const button = qs('[type="submit"]', form);
      const status = qs('[data-account-delete-status]', form);
      if (button) button.disabled = true;
      if (status) status.textContent = 'Kontolöschung wird sicher ausgeführt …';
      try {
        const csrf = qs('input[name="_csrf"]', form)?.value || '';
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: new FormData(form),
          credentials: 'same-origin',
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || result?.ok !== true) {
          throw new Error(result?.error || 'Kontolöschung nicht bestätigt');
        }
        let cleanupFailed = false;
        try {
          await deleteOfflineDb();
        } catch {
          cleanupFailed = true;
        }
        clearLocalAppState();
        if (cleanupFailed) {
          if (status) status.textContent = 'Konto serverseitig gelöscht. Der lokale Offline-Speicher konnte nicht vollständig bereinigt werden.';
          window.setTimeout(() => location.assign(result.redirect || '/snackquest/'), 1200);
        } else {
          location.assign(result.redirect || '/snackquest/');
        }
      } catch {
        form.dataset.submitting = '0';
        if (button) button.disabled = false;
        if (status) {
          status.textContent = navigator.onLine
            ? 'Die Löschung wurde nicht bestätigt. Lokale und serverseitige Daten wurden nicht als gelöscht behandelt.'
            : 'Du bist offline. Das Konto und deine lokalen Entwürfe bleiben erhalten.';
        }
      }
    });
  });

  const librarySearch = qs('.filter-bar input[type="search"]');
  if (librarySearch) {
    document.addEventListener('keydown', (event) => {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '');
      if (event.key === '/' && !typing) {
        event.preventDefault();
        librarySearch.focus();
      }
      if (event.key === 'Escape' && document.activeElement === librarySearch && librarySearch.value !== '') {
        librarySearch.value = '';
        librarySearch.focus();
      }
    });
  }
})();
