/**
 * Bilder im Browser laden und neu kodieren, ohne Bibliothek. Das Neukodieren
 * über ein Canvas entfernt EXIF mit Ort und Kameradaten und spart mobile
 * Daten; die Ausrichtung aus EXIF wenden die Browser beim Zeichnen selbst an.
 */
import { cropSource, fitWithin } from './imageCrop.js';

const CROP_QUALITY = 0.88;
const ORIGINAL_QUALITY = 0.86;

/**
 * Lädt eine Datei oder einen Blob als Bild. Safari wandelt HEIC beim Wählen
 * in JPEG; was der Browser nicht lesen kann, wirft `image_unreadable`.
 *
 * @param {Blob} blob
 * @returns {Promise<{ image: HTMLImageElement, url: string, width: number, height: number }>}
 *   url später mit URL.revokeObjectURL freigeben
 */
export function loadImage(blob) {
    const url = URL.createObjectURL(blob);
    const image = new Image();

    image.decoding = 'async';

    return new Promise((resolve, reject) => {
        image.onload = () => resolve({ image, url, width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('image_unreadable'));
        };
        image.src = url;
    });
}

/**
 * Zeichnet den quadratischen Ausschnitt mit `size` Pixeln als JPEG.
 * Durchsichtige Bereiche (PNG) werden weiß.
 *
 * @param {HTMLImageElement} image
 * @param {{ x: number, y: number, zoom: number }} crop
 * @param {number} size
 * @returns {Promise<Blob>}
 */
export function renderCrop(image, crop, size) {
    const { sx, sy, side } = cropSource({ width: image.naturalWidth, height: image.naturalHeight }, crop);
    const canvas = createCanvas(size, size);

    canvas.context.drawImage(image, sx, sy, side, side, 0, 0, size, size);

    return toJpeg(canvas.element, CROP_QUALITY);
}

/**
 * Das ganze Bild, auf höchstens `maxPixels` an der längsten Seite
 * verkleinert, als JPEG (zum späteren neuen Zuschneiden).
 *
 * @param {HTMLImageElement} image
 * @param {number} maxPixels
 * @returns {Promise<Blob>}
 */
export function renderOriginal(image, maxPixels) {
    const { width, height } = fitWithin(image.naturalWidth, image.naturalHeight, maxPixels);
    const canvas = createCanvas(width, height);

    canvas.context.drawImage(image, 0, 0, width, height);

    return toJpeg(canvas.element, ORIGINAL_QUALITY);
}

function createCanvas(width, height) {
    const element = document.createElement('canvas');

    element.width = width;
    element.height = height;

    const context = element.getContext('2d');

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';

    return { element, context };
}

function toJpeg(canvas, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                // Speicher sofort freigeben (iOS begrenzt die Canvas-Fläche insgesamt)
                canvas.width = 0;
                canvas.height = 0;

                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('render_failed'));
                }
            },
            'image/jpeg',
            quality
        );
    });
}
