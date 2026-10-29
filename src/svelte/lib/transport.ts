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
