/**
 * Skala der Halten-Geste (Lineal und Zählwerk, Vorlage:
 * design/piklis-halten-geste). Die Position ist ein Index als Fließkommazahl,
 * Stufe i entspricht `i × quantityStep(unit)`, Stufe 0 heißt „entfernen“. Alle
 * Zahlen sind am Gerät abgestimmt, deshalb in px und nicht in Tokens.
 *
 * Die Obergrenze steht nicht in den Daten: Sie ergibt sich aus der Menge, damit
 * eine große Menge nie abgeschnitten wird. Skala wenigstens 20 Stufen, sonst das
 * Doppelte der Menge, höchstens MAX_AMOUNT.
 *
 * Ohne Vue und ohne DOM (`node --test`).
 */
import { MAX_AMOUNT, quantityStep } from './quantity.js';

export const HOLD = Object.freeze({
    holdMs: 380, // Haltezeit bis zur Mengen-Ebene
    slopPx: 10, // Bewegung davor gilt als Scrollen oder Wischen
    moveCommitPx: 6, // ab hier zählt es als Ziehen, nicht als Halten und Loslassen
    rulerGap: 64, // px je Stufe auf dem Lineal, Finger 1 : 1
    counterGap: 56, // px Fingerweg je Stufe im Zählwerk
    snapMs: 300, // Einrasten nach dem Loslassen
    fadeMs: 160, // Ebene ausblenden
    panelMs: 240, // Sheet und Dialog
    rubber: 0.35, // Widerstand hinter dem Ende
    rubberMax: 0.45, // höchstens so viele Stufen Überstand
    sheetCloseDrag: 96, // Sheet am Griff: ab hier schließt es
    vibrateHold: 10,
    vibrateStep: 6
});

export const RULER = Object.freeze({
    scale: [1, 0.56, 0.5, 0.48],
    opacity: [1, 0.9, 0.75, 0.6, 0.5]
});

export const COUNTER = Object.freeze({
    offset: [0, 108, 168, 212, 248, 280],
    scale: [1, 0.43, 0.31, 0.25, 0.21, 0.19],
    opacity: [1, 0.85, 0.55, 0.3, 0.12, 0],
    reach: 4.6
});

const MIN_STEPS = 20;
const SCALE = 1000;

function stepOf(unit) {
    return Math.round(quantityStep(unit) * SCALE);
}

/** Letzte Stufe der Skala für diese Menge. */
export function scaleMaxIndex(amount, unit = null) {
    const step = stepOf(unit);
    const wanted = Math.max(MIN_STEPS, Math.ceil(2 * Math.round(amount * SCALE) / step));

    return Math.min(wanted, Math.floor(MAX_AMOUNT * SCALE / step));
}

/** Stufe, auf der die Menge liegt; freie Mengen wie 120 g zeigen die nächste. */
export function scaleIndex(amount, unit = null) {
    return Math.min(Math.round(amount * SCALE / stepOf(unit)), scaleMaxIndex(amount, unit));
}

export function amountAt(index, unit = null) {
    return index * stepOf(unit) / SCALE;
}

export function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/** Hinter dem ersten und letzten Wert bremst die Skala. */
export function rubberBand(position, maxIndex) {
    if (position < 0) {
        return -Math.min(HOLD.rubberMax, -position * HOLD.rubber);
    }

    if (position > maxIndex) {
        return maxIndex + Math.min(HOLD.rubberMax, (position - maxIndex) * HOLD.rubber);
    }

    return position;
}

/** Stückweise linear über |d|, Stützstellen bei 0, 1, 2, … */
export function lerpKnots(knots, distance) {
    const away = Math.abs(distance);

    for (let index = 0; index < knots.length - 1; index++) {
        if (away <= index + 1) {
            return knots[index] + (knots[index + 1] - knots[index]) * (away - index);
        }
    }

    return knots[knots.length - 1];
}

export function easeOutCubic(progress) {
    return 1 - (1 - progress) ** 3;
}

export function hexToRgb(hex) {
    const value = hex.trim().replace('#', '');
    const full = value.length === 3 ? value.replace(/./g, '$&$&') : value;

    return [0, 2, 4].map((start) => parseInt(full.slice(start, start + 2), 16));
}

export function mixColor(from, to, amount) {
    return 'rgb(' + from.map((value, index) => Math.round(value + (to[index] - value) * amount)).join(',') + ')';
}

/**
 * Sichtbarer Bereich einer Skala, deren Elemente wiederverwendet werden: Platz
 * `slot` von `slots` zeigt Stufe `first + …` oder gar nichts (null). So liegen
 * auch bei 9999 Stufen nur die sichtbaren im DOM.
 */
export function slotIndex(slot, slots, first, last) {
    const index = first + (((slot - first) % slots) + slots) % slots;

    return index <= last ? index : null;
}
