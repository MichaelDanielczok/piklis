/**
 * Prüfung einer gewählten Bilddatei vor dem Zuschnitt (getestet in
 * tests/js/imageCrop.test.js). Die Grenzen kommen aus den
 * Projekteinstellungen (`GET /config`, siehe composables/useConfig.js).
 */

/** JPEG, PNG und WebP; das iPhone liefert HEIC beim Hochladen als JPEG */
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Für `<input type="file" accept>` */
export const IMAGE_ACCEPT = IMAGE_TYPES.join(',');

/**
 * @param {{ type: string, size: number }} file
 * @param {{ maxBytes: number }} limits
 * @returns {null|'type'|'size'} Fehler oder null
 */
export function checkImageFile(file, limits) {
    // ohne Typ (manche Zwischenablagen): der Browser versucht es beim Laden
    if (file.type && !IMAGE_TYPES.includes(file.type)) {
        return 'type';
    }

    if (file.size > limits.maxBytes) {
        return 'size';
    }

    return null;
}

/**
 * Byte als Megabyte für Meldungen („15 MB“, „23,4 MB“).
 *
 * @param {number} bytes
 * @param {string} locale
 */
export function formatMegabytes(bytes, locale) {
    const megabytes = bytes / (1024 * 1024);

    return new Intl.NumberFormat(locale, { maximumFractionDigits: megabytes < 10 ? 1 : 0 }).format(megabytes) + ' MB';
}

/**
 * Erstes Bild aus einem Einfügen-Ereignis (`paste`) oder null.
 *
 * @param {{ files?: ArrayLike<File>, items?: ArrayLike<{ kind: string, type: string, getAsFile(): File|null }> }|null} clipboardData
 */
export function imageFromClipboard(clipboardData) {
    if (!clipboardData) {
        return null;
    }

    for (const file of Array.from(clipboardData.files ?? [])) {
        if (file.type.startsWith('image/')) {
            return file;
        }
    }

    for (const item of Array.from(clipboardData.items ?? [])) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
            return item.getAsFile();
        }
    }

    return null;
}
