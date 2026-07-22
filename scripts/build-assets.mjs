import sharp from 'sharp';
import { copyFile, mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const brandSource = 'src/brand/snackquest-mark-master.png';
const ivory = '#fffdf5';
const ink = '#181816';

await Promise.all([
  mkdir('public/assets/brand', { recursive: true }),
  mkdir('public/assets/icons', { recursive: true }),
  mkdir('public/assets/images', { recursive: true }),
  mkdir('public/assets/fonts', { recursive: true }),
]);

const source = await readFile(brandSource);
const sourceMeta = await sharp(source).metadata();
if (!sourceMeta.width || !sourceMeta.height || sourceMeta.width < 8 || sourceMeta.height < 8) {
  throw new Error('Brand master is too small or unreadable.');
}
// Chroma-key cleanup can leave a faint one-pixel canvas seam. Remove two
// boundary pixels before trimming so it never appears in small app icons.
const croppedSource = await sharp(source)
  .extract({ left: 2, top: 2, width: sourceMeta.width - 4, height: sourceMeta.height - 4 })
  .png()
  .toBuffer();
const trimmedMark = await sharp(croppedSource)
  .trim({ threshold: 8 })
  .png()
  .toBuffer();

async function placedMark(size, ratio, tint = null) {
  const inner = Math.round(size * ratio);
  let pipeline = sharp(trimmedMark).resize(inner, inner, {
    fit: 'contain',
    background: { r: 0, g: 0, b: 0, alpha: 0 },
  });
  if (tint) pipeline = pipeline.tint(tint);
  const mark = await pipeline.png().toBuffer();
  const offset = Math.round((size - inner) / 2);
  return sharp({
    create: { width: size, height: size, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
  }).composite([{ input: mark, left: offset, top: offset }]).png().toBuffer();
}

async function appIcon(size, ratio, output) {
  const mark = await placedMark(size, ratio);
  await sharp({ create: { width: size, height: size, channels: 4, background: ivory } })
    .composite([{ input: mark, left: 0, top: 0 }])
    .png()
    .toFile(output);
}

await sharp(await placedMark(256, 0.94)).png().toFile('public/assets/brand/snackquest-mark-256.png');
await sharp(await placedMark(512, 0.94)).png().toFile('public/assets/brand/snackquest-mark-512.png');

for (const size of [180, 192, 512, 1024]) {
  await appIcon(size, 0.76, `public/assets/icons/icon-${size}.png`);
}
await appIcon(512, 0.62, 'public/assets/icons/icon-maskable-512.png');
await sharp(await placedMark(512, 0.68, ink)).png().toFile('public/assets/icons/icon-monochrome-512.png');
await appIcon(32, 0.84, 'public/assets/icons/favicon-32.png');
await appIcon(64, 0.84, 'public/assets/icons/favicon-64.png');

const ogBase = Buffer.from(`
  <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630">
    <rect width="1200" height="630" fill="${ivory}"/>
    <circle cx="1100" cy="90" r="145" fill="#c9f26c" opacity=".72"/>
    <circle cx="1060" cy="560" r="120" fill="#e9508f" opacity=".22"/>
    <path d="M70 145V65h80M70 485v80h80" fill="none" stroke="${ink}" stroke-width="18"/>
    <text x="96" y="205" fill="${ink}" font-family="Arial, sans-serif" font-size="34" font-weight="800" letter-spacing="5">SNACKQUEST</text>
    <text x="92" y="330" fill="${ink}" font-family="Georgia, serif" font-size="82" font-weight="700">Scannen.</text>
    <text x="92" y="420" fill="#ff7442" font-family="Georgia, serif" font-size="82" font-weight="700">Bewerten.</text>
    <text x="96" y="490" fill="#68645c" font-family="Arial, sans-serif" font-size="28">Dein persönliches Snack-Gedächtnis.</text>
    <rect x="700" y="78" width="410" height="478" rx="42" fill="#75cdf3" stroke="${ink}" stroke-width="8"/>
    <rect x="716" y="94" width="410" height="478" rx="42" fill="none" stroke="${ink}" stroke-width="8"/>
  </svg>
`);
const ogMark = await placedMark(390, 0.92);
const ogImage = await sharp(ogBase)
  .composite([{ input: ogMark, left: 717, top: 118 }])
  .png()
  .toBuffer();
await sharp(ogImage)
  .toFile('public/assets/images/og-snackquest.png');
await sharp({ create: { width: 1280, height: 640, channels: 4, background: ivory } })
  .composite([{ input: ogImage, left: 40, top: 5 }])
  .png()
  .toFile('public/assets/images/github-social-preview.png');

const find = async (pkg, file) => {
  const base = path.dirname(fileURLToPath(import.meta.resolve(`${pkg}/package.json`)));
  await copyFile(path.join(base, file), path.join('public/assets/fonts', path.basename(file)));
};
await find('@fontsource-variable/manrope', 'files/manrope-latin-wght-normal.woff2');
await find('@fontsource-variable/fraunces', 'files/fraunces-latin-wght-normal.woff2');
