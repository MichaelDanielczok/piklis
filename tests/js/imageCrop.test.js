/**
 * Zuschnitt, Großansicht und Prüfung der Bilddateien.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_ZOOM,
    clampCrop,
    clampView,
    cropSide,
    cropSource,
    fitWithin,
    imageLayout,
    initialCrop,
    zoomAt
} from '@/utils/imageCrop.js';
import { checkImageFile, formatMegabytes, imageFromClipboard } from '@/utils/imageUpload.js';

const photo = { width: 4032, height: 3024 };

test('initial crop is the largest centred square', () => {
    const crop = initialCrop(photo);

    assert.deepEqual(crop, { x: 2016, y: 1512, zoom: 1 });
    assert.equal(cropSide(photo, crop.zoom), 3024);
    assert.deepEqual(cropSource(photo, crop), { sx: 504, sy: 0, side: 3024 });
});

test('crop never leaves the image and zoom stays in range', () => {
    assert.deepEqual(clampCrop(photo, { x: 0, y: 0, zoom: 1 }), { x: 1512, y: 1512, zoom: 1 });
    assert.deepEqual(clampCrop(photo, { x: 9999, y: 9999, zoom: 2 }), { x: 4032 - 756, y: 3024 - 756, zoom: 2 });
    assert.equal(clampCrop(photo, { x: 2016, y: 1512, zoom: 99 }).zoom, MAX_ZOOM);
    assert.equal(clampCrop(photo, { x: 2016, y: 1512, zoom: 0.2 }).zoom, 1);
});

test('layout places the crop exactly in the viewport', () => {
    const layout = imageLayout(photo, { x: 2016, y: 1512, zoom: 1 }, 300);

    assert.equal(layout.height, 300);
    assert.equal(layout.width, 400);
    assert.equal(layout.left, -50);
    assert.equal(layout.top, 0);
});

test('original is reduced to the longest side', () => {
    assert.deepEqual(fitWithin(4032, 3024, 2048), { width: 2048, height: 1536 });
    assert.deepEqual(fitWithin(3024, 4032, 2048), { width: 1536, height: 2048 });
    assert.deepEqual(fitWithin(1200, 800, 2048), { width: 1200, height: 800 });
});

test('viewer pans only as far as the zoomed image reaches', () => {
    const fitted = { width: 400, height: 400 };
    const frame = { width: 400, height: 700 };

    assert.deepEqual(clampView(fitted, frame, { x: 50, y: 50, zoom: 1 }), { x: 0, y: 0, zoom: 1 });
    assert.deepEqual(clampView(fitted, frame, { x: 999, y: 999, zoom: 2 }), { x: 200, y: 50, zoom: 2 });
    assert.equal(clampView(fitted, frame, { x: 0, y: 0, zoom: 9 }).zoom, 4);
});

test('zooming keeps the point under the finger', () => {
    const view = zoomAt({ x: 0, y: 0, zoom: 1 }, 2, { x: 100, y: 0 });

    // Punkt bei 100 px: vorher Bildpunkt 100, nachher (100 - x) / 2 = 100
    assert.deepEqual(view, { zoom: 2, x: -100, y: 0 });
});

test('file check uses the project limits', () => {
    const limits = { maxBytes: 15 * 1024 * 1024 };

    assert.equal(checkImageFile({ type: 'image/jpeg', size: 3_000_000 }, limits), null);
    assert.equal(checkImageFile({ type: 'image/png', size: 16 * 1024 * 1024 }, limits), 'size');
    assert.equal(checkImageFile({ type: 'image/gif', size: 1000 }, limits), 'type');
    assert.equal(checkImageFile({ type: '', size: 1000 }, limits), null);
    assert.equal(formatMegabytes(15 * 1024 * 1024, 'de'), '15 MB');
    assert.equal(formatMegabytes(2.5 * 1024 * 1024, 'de'), '2,5 MB');
});

test('pasted image is found in files or items', () => {
    const image = { type: 'image/png', name: 'image.png' };

    assert.equal(imageFromClipboard({ files: [image] }), image);
    assert.equal(imageFromClipboard({ files: [], items: [{ kind: 'file', type: 'image/png', getAsFile: () => image }] }), image);
    assert.equal(imageFromClipboard({ files: [], items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }] }), null);
    assert.equal(imageFromClipboard(null), null);
});
