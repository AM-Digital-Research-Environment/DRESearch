import { getContext, setContext } from 'svelte';
import { resultCount, t } from './i18n';

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

/**
 * Quiet the results live region needs before it speaks. The search is already
 * debounced, but a fast typist still settles several searches in a row, and a
 * region that changes three times in a second is read as an interruption of
 * itself: 600 ms of quiet means one sentence per pause (IwacSearch's figure).
 */
export const ANNOUNCE_DELAY_MS = 600;

export interface ResultAnnouncementInput {
  /** A request is in flight: nothing is settled, so nothing new is said. */
  loading: boolean;
  failed: boolean;
  response: { available: boolean; found: number } | null;
  /** What an empty result says: a search that matched nothing, or an empty corpus. */
  emptyMessage: string;
}

/**
 * The sentence the results live region reads once the surface settles: the
 * result count, the empty message or the failure — or null while a request
 * is in flight, which leaves the region as it was. It used to say "Loading…"
 * on every fetch, so each keystroke's search was read out twice, and a
 * response superseded a moment later still had its count spoken.
 *
 * Plurals come from the locale's rules (resultCount), never `found === 1`.
 */
export function resultAnnouncement(input: ResultAnnouncementInput): string | null {
  if (input.loading) return null;
  if (input.failed) return t('search_unavailable');
  const response = input.response;
  if (!response || !response.available) return '';
  return response.found === 0 ? input.emptyMessage : resultCount(response.found);
}
