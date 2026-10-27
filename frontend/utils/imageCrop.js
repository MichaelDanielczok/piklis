/**
 * Rechnen für Zuschnitt und Großansicht der Produktbilder, ohne Browser-APIs
 * (getestet in tests/js/imageCrop.test.js).
 *
 * Der Ausschnitt ist ein Quadrat in Bildpixeln: Mittelpunkt `x`/`y` und
 * Zoom `zoom` (1 = die kürzere Bildseite füllt das Quadrat). So bleibt er
 * gleich, wenn sich die Anzeigegröße ändert (Drehen, Fenster).
 */

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 5;

/** Großansicht: so weit lässt sich hineinzoomen */
export const VIEWER_MAX_ZOOM = 4;

/**
 * Seitenlänge des Ausschnitts in Bildpixeln.
 *
 * @param {{ width: number, height: number }} image
 * @param {number} zoom
 */
export function cropSide(image, zoom) {
    return Math.min(image.width, image.height) / zoom;
}

/** Ausschnitt in der Bildmitte, so groß wie möglich. */
export function initialCrop(image) {
    return { x: image.width / 2, y: image.height / 2, zoom: MIN_ZOOM };
}

/**
 * Hält Zoom und Mittelpunkt im Bild: Der Ausschnitt ragt nie darüber hinaus.
 *
 * @param {{ width: number, height: number }} image
 * @param {{ x: number, y: number, zoom: number }} crop
 * @returns {{ x: number, y: number, zoom: number }}
 */
export function clampCrop(image, crop) {
    const zoom = clamp(crop.zoom, MIN_ZOOM, MAX_ZOOM);
    const half = cropSide(image, zoom) / 2;

    return {
        zoom,
        x: clamp(crop.x, half, image.width - half),
        y: clamp(crop.y, half, image.height - half)
    };
}

/**
 * Lage und Größe des Bilds im Quadrat mit der Seitenlänge `viewport`
 * (CSS-Pixel), für die Vorschau beim Zuschneiden.
 */
export function imageLayout(image, crop, viewport) {
    const scale = viewport / cropSide(image, crop.zoom);

    return {
        scale,
        width: image.width * scale,
        height: image.height * scale,
        left: viewport / 2 - crop.x * scale,
        top: viewport / 2 - crop.y * scale
    };
}

/**
 * Quellrechteck des Ausschnitts für `drawImage()`.
 *
 * @returns {{ sx: number, sy: number, side: number }}
 */
export function cropSource(image, crop) {
    const side = cropSide(image, crop.zoom);

    return { sx: crop.x - side / 2, sy: crop.y - side / 2, side };
}

/**
 * Größe nach dem Verkleinern auf höchstens `maxPixels` an der längsten
 * Seite; kleinere Bilder bleiben, wie sie sind.
 */
export function fitWithin(width, height, maxPixels) {
    const longest = Math.max(width, height);

    if (longest <= maxPixels) {
        return { width, height };
    }

    const factor = maxPixels / longest;

    return {
        width: Math.max(1, Math.round(width * factor)),
        height: Math.max(1, Math.round(height * factor))
    };
}

/**
 * Großansicht: Verschiebung `x`/`y` (CSS-Pixel, vom Mittelpunkt) so
 * begrenzen, dass das gezoomte Bild den Rahmen nicht freigibt.
 *
 * @param {{ width: number, height: number }} fitted Bildgröße bei Zoom 1 im Rahmen
 * @param {{ width: number, height: number }} frame
 * @param {{ x: number, y: number, zoom: number }} view
 */
export function clampView(fitted, frame, view) {
    const zoom = clamp(view.zoom, 1, VIEWER_MAX_ZOOM);
    const maxX = Math.max(0, (fitted.width * zoom - frame.width) / 2);
    const maxY = Math.max(0, (fitted.height * zoom - frame.height) / 2);

    return {
        zoom,
        x: clamp(view.x, -maxX, maxX),
        y: clamp(view.y, -maxY, maxY)
    };
}

/**
 * Zoom um einen Punkt (CSS-Pixel relativ zur Rahmenmitte): Der Punkt unter
 * dem Finger bzw. Mauszeiger bleibt an seiner Stelle.
 */
export function zoomAt(view, zoom, point) {
    const ratio = zoom / view.zoom;

    return {
        zoom,
        x: point.x - (point.x - view.x) * ratio,
        y: point.y - (point.y - view.y) * ratio
    };
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}
