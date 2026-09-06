/**
 * Генерация производных изображений: WebP и JPEG в нескольких ширинах.
 *
 * Делается на сборке, а не на лету, потому что в PHP этого окружения не
 * подключены ни gd, ни imagick (DLL php_gd.dll в каталоге ext есть —
 * достаточно раскомментировать extension=gd в php.ini). Когда появится
 * загрузка картинок из админки, обработку надо будет перенести на PHP,
 * но шаблоны от этого не изменятся: они читают manifest.json.
 *
 * Запуск: npm run images
 */

import { readdir, mkdir, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const SOURCE_DIR = 'public/img';
const OUTPUT_DIR = 'public/img/derived';
const MANIFEST = path.join(OUTPUT_DIR, 'manifest.json');

// Ширины под сетку каталога: карточка ~600, карточка товара ~900, hero — во всю ширину.
const WIDTHS = [400, 800, 1200, 1600];
const SOURCE_EXTENSIONS = new Set(['.jpg', '.jpeg', '.png']);

async function collectSources(dir) {
    const found = [];

    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);

        // Сами производные не обрабатываем повторно.
        if (entry.isDirectory()) {
            if (full.replaceAll('\\', '/') === OUTPUT_DIR) continue;
            found.push(...(await collectSources(full)));
            continue;
        }

        if (SOURCE_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) {
            found.push(full);
        }
    }

    return found;
}

async function isStale(source, target) {
    if (!existsSync(target)) return true;

    const [a, b] = await Promise.all([stat(source), stat(target)]);

    return a.mtimeMs > b.mtimeMs;
}

const sources = await collectSources(SOURCE_DIR);
const manifest = {};
let written = 0;
let skipped = 0;

for (const source of sources) {
    const relative = path.relative(SOURCE_DIR, source).replaceAll('\\', '/');
    const key = `img/${relative}`;
    const base = relative.replace(/\.[^.]+$/, '');
    const image = sharp(source);
    const meta = await image.metadata();

    const entry = { width: meta.width, height: meta.height, avif: [], webp: [], jpg: [] };

    // Апскейл не делаем: он только раздувает вес без выигрыша в качестве.
    // Но и обрезать до предыдущей ступени нельзя — картинка шириной 1464
    // отдавалась бы вариантом на 1200 и растягивалась в вёрстке.
    // Поэтому ступень выше исходника подменяется самим исходником.
    const widths = [...new Set(WIDTHS.map((w) => (meta.width ? Math.min(w, meta.width) : w)))];

    for (const width of widths) {

        for (const [format, options] of [
            // AVIF первым: он весит меньше всех, но кодируется медленно —
            // терпимо, потому что это сборка, а не рантайм.
            ['avif', { quality: 55 }],
            ['webp', { quality: 78 }],
            ['jpg', { quality: 80, mozjpeg: true }],
        ]) {
            const outRelative = `${base}-${width}.${format}`;
            const outPath = path.join(OUTPUT_DIR, outRelative);

            await mkdir(path.dirname(outPath), { recursive: true });

            if (await isStale(source, outPath)) {
                const pipeline = sharp(source).resize({ width, withoutEnlargement: true });
                const encoded = format === 'avif'
                    ? pipeline.avif(options)
                    : format === 'webp'
                        ? pipeline.webp(options)
                        : pipeline.jpeg(options);

                await encoded.toFile(outPath);
                written++;
            } else {
                skipped++;
            }

            entry[format].push({ width, src: `img/derived/${outRelative}` });
        }
    }

    manifest[key] = entry;
}

await writeFile(MANIFEST, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

console.log(
    `изображений: ${sources.length}, создано файлов: ${written}, пропущено (актуальны): ${skipped}`,
);
