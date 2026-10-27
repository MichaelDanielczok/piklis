/**
 * Für `node --test`: löst `@/…` wie Vite nach `frontend/…` auf, ohne
 * zusätzliche Bibliothek (Node `module.registerHooks`).
 */
import { registerHooks } from 'node:module';

const FRONTEND = new URL('../../frontend/', import.meta.url);

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.startsWith('@/')) {
            return nextResolve(new URL(specifier.slice(2), FRONTEND).href, context);
        }

        return nextResolve(specifier, context);
    }
});
