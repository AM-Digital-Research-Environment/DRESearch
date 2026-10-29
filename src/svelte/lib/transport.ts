/**
 * Request plumbing shared by every surface that fetches: the "latest request
 * wins" ticket and the test for a superseded (aborted) request. Kept out of
 * api.ts on purpose: the component tests replace that module wholesale with
 * vi.mock(), and the state modules (searchResults.svelte.ts,
 * federatedResults.svelte.ts) must keep these when they do.
 */

/**
 * Suppress stale completions. Abort cancels the network; this ticket covers
 * everything abort cannot reach — a response that resolved in the same tick,
 * a server that ignores the abort, a `.then` already queued — so only the
 * request issued last may settle the surface.
 */
export class SeqGuard {
  private seq = 0;

  /** Open a new request; every earlier ticket becomes stale. */
  start(): number {
    return ++this.seq;
  }

  isStale(ticket: number): boolean {
    return ticket !== this.seq;
  }
}

/**
 * True when `e` is what a superseded fetch rejects with. Callers swallow it:
 * an aborted request means "a newer one is in flight", never "show the error
 * state". A request that ran out of time rejects with a TimeoutError instead,
 * which is a real failure (see api.ts).
 */
export function isAbortError(e: unknown): boolean {
  return (
    typeof e === 'object' &&
    e !== null &&
    'name' in e &&
    (e as { name: unknown }).name === 'AbortError'
  );
}

/**
 * How long one request may take before the surface gives up on it. The proxy
 * answers a search in well under a second and bounds each Typesense call at
 * five (config `search_timeout`), so a request still open after fifteen means
 * a hung proxy or network; without a deadline the surface said "Loading…"
 * for good.
 */
export const REQUEST_TIMEOUT_MS = 15_000;

/**
 * The bulk pagers (export, map) make up to four Typesense calls of their own
 * server-side, each bounded at five seconds, so they get twice as long.
 */
export const BULK_REQUEST_TIMEOUT_MS = 30_000;

/**
 * `signal` (the caller's "superseded" abort), plus a deadline. A request that
 * runs out of time rejects with a `TimeoutError`, NOT an `AbortError`, so the
 * surfaces show their normal translated failure and "Try again" for it, while
 * a superseded request stays silent ({@link isAbortError}).
 *
 * `AbortSignal.any` is recent (Safari 17.4, Firefox 124); without it the two
 * are joined by hand.
 */
export function withDeadline(
  signal: AbortSignal | undefined,
  ms: number = REQUEST_TIMEOUT_MS,
): AbortSignal {
  const deadline = timeoutSignal(ms);
  if (!signal) return deadline;
  if (typeof AbortSignal.any === 'function') return AbortSignal.any([signal, deadline]);
  const joined = new AbortController();
  for (const source of [signal, deadline]) {
    if (source.aborted) {
      joined.abort(source.reason);
      break;
    }
    source.addEventListener('abort', () => joined.abort(source.reason), { once: true });
  }
  return joined.signal;
}

function timeoutSignal(ms: number): AbortSignal {
  if (typeof AbortSignal.timeout === 'function') return AbortSignal.timeout(ms);
  const controller = new AbortController();
  setTimeout(
    () => controller.abort(new DOMException('The request timed out.', 'TimeoutError')),
    ms,
  );
  return controller.signal;
}
