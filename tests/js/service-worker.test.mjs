import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile('public/sw.js', 'utf8');
const listeners = new Map();
const deleted = [];
let claimed = false;

const context = {
  URL,
  location: { origin: 'https://julian-neumann.org' },
  fetch: async () => { throw new Error('network must not be used by activate test'); },
  caches: {
    keys: async () => [
      'sq-v1.0.2-static',
      'sq-v1.0.2-public',
      'sq-v1.1.0-static',
      'sq-v1.1.0-public',
      'cp-v1.5.0-static',
      'rz-v1.3.0-static',
      'unrelated-cache',
    ],
    delete: async (key) => {
      deleted.push(key);
      return true;
    },
    open: async () => { throw new Error('cache open must not be used by activate test'); },
    match: async () => null,
  },
  self: {
    addEventListener(type, handler) {
      listeners.set(type, handler);
    },
    clients: {
      async claim() {
        claimed = true;
      },
    },
    skipWaiting: async () => {},
  },
};

vm.runInNewContext(source, context, { filename: 'public/sw.js' });

let activation;
listeners.get('activate')({
  waitUntil(promise) {
    activation = promise;
  },
});
await activation;

assert.deepEqual(
  deleted.sort(),
  ['sq-v1.0.2-public', 'sq-v1.0.2-static'],
  'activate must delete only obsolete SnackQuest-owned caches',
);
assert.equal(claimed, true, 'activate must claim clients after scoped cleanup');

console.log('PASS service worker preserves foreign app caches');
