import { getContext, setContext } from 'svelte';

/**
 * Copy feedback, per the shared contract (DRE-theme
 * docs/DESIGN-INTEGRATION.md, "Shared widgets" and "Theme JavaScript API"):
 * the button reads "Copied" for {@link COPY_FEEDBACK_MS}, and the message is
 * spoken through the theme's one shared status region when the theme is
 * there. Without it (an isolated preview, another theme) the message goes to
 * the surface's own persistent status node, so a surface still has exactly
 * one; a widget rendered outside any surface keeps a node of its own.
 */

export const COPY_FEEDBACK_MS = 2000;

type Announcer = (message: string) => void;

interface DREUtils {
  announce?: Announcer;
}

const KEY = Symbol('dre-surface-announcer');

/** Route fallback announcements to this surface's status node (call during init). */
export function provideAnnouncer(announcer: Announcer): void {
  setContext(KEY, announcer);
}

/** The enclosing surface's announcer, if any (call during init). */
export function surfaceAnnouncer(): Announcer | undefined {
  return getContext<Announcer | undefined>(KEY);
}

/**
 * Speak `message` through `window.DREUtils.announce()`, else through the
 * surface's announcer. Returns false when neither exists, so the caller fills
 * a status node of its own.
 */
export function announce(message: string, surface?: Announcer): boolean {
  const utils = (window as unknown as { DREUtils?: DREUtils }).DREUtils;
  if (typeof utils?.announce === 'function') {
    utils.announce(message);
    return true;
  }
  if (surface) {
    surface(message);
    return true;
  }
  return false;
}
