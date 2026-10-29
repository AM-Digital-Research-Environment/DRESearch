import { afterEach, describe, expect, it, vi } from 'vitest';
import { SearchApi, searchAll, searchUnion, suggestAll } from '../../src/svelte/lib/api';
import {
  BULK_REQUEST_TIMEOUT_MS,
  REQUEST_TIMEOUT_MS,
  SeqGuard,
  isAbortError,
  withDeadline,
} from '../../src/svelte/lib/transport';

/**
 * Request deadlines: a hung proxy must end in the surface's normal failure
 * state ("Try again"), never in "Loading…" for good — and a superseded request
 * must still end silently.
 */

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

const endpoints = {
  search: '/dre-search/api/search',
  export: '/dre-search/api/export',
  suggest: '/dre-search/api/suggest',
  map: '/dre-search/api/map',
  facet: '/dre-search/api/facet',
};

const request = {
  q: 'islam',
  page: 1,
  per_page: 20,
  sort: 'relevance' as const,
  filters: {},
  facets: [],
};

/**
 * Stand in for AbortSignal.timeout with signals the test fires by hand, and for
 * fetch with one that hangs until its signal aborts — a proxy that never answers.
 */
function hangingFetch() {
  const deadlines: Array<{ ms: number; fire: () => void }> = [];
  vi.spyOn(AbortSignal, 'timeout').mockImplementation((ms: number) => {
    const controller = new AbortController();
    deadlines.push({
      ms,
      fire: () => controller.abort(new DOMException('The request timed out.', 'TimeoutError')),
    });
    return controller.signal;
  });
  const signals: AbortSignal[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(
      (_url: string, init?: RequestInit) =>
        new Promise<Response>((_resolve, reject) => {
          const signal = init?.signal;
          if (!signal) return;
          signals.push(signal);
          signal.addEventListener('abort', () => reject(signal.reason as Error), { once: true });
        }),
    ),
  );
  return { deadlines, signals };
}

describe('withDeadline', () => {
  it('times out with a TimeoutError, which is not an abort', async () => {
    const signal = withDeadline(undefined, 10);
    await new Promise<void>((resolve) => signal.addEventListener('abort', () => resolve()));
    expect((signal.reason as DOMException).name).toBe('TimeoutError');
    expect(isAbortError(signal.reason)).toBe(false);
  });

  it("still passes the caller's abort through, as an AbortError", () => {
    const caller = new AbortController();
    const signal = withDeadline(caller.signal, 60_000);
    caller.abort();
    expect(signal.aborted).toBe(true);
    expect(isAbortError(signal.reason)).toBe(true);
  });

  it('joins the two by hand where AbortSignal.any is missing', () => {
    vi.stubGlobal(
      'AbortSignal',
      Object.assign(Object.create(AbortSignal) as typeof AbortSignal, {
        any: undefined,
        timeout: AbortSignal.timeout.bind(AbortSignal),
      }),
    );
    const caller = new AbortController();
    const signal = withDeadline(caller.signal, 60_000);
    expect(signal.aborted).toBe(false);
    caller.abort();
    expect(signal.aborted).toBe(true);
    expect(isAbortError(signal.reason)).toBe(true);
  });
});

describe('API deadlines', () => {
  it('ends a hung search in a TimeoutError', async () => {
    const { deadlines } = hangingFetch();
    const pending = new SearchApi(endpoints, 'research_items').search(request);
    expect(deadlines.map((d) => d.ms)).toEqual([REQUEST_TIMEOUT_MS]);
    deadlines[0]!.fire();
    await expect(pending).rejects.toMatchObject({ name: 'TimeoutError' });
  });

  it('gives the bulk pagers longer, and every other call the normal deadline', async () => {
    const { deadlines } = hangingFetch();
    const api = new SearchApi({ ...endpoints, popular: '/popular' }, 'research_items');
    const caller = new AbortController();
    const calls = [
      api.search(request, caller.signal),
      api.facet(request, 'type_s', 'te', caller.signal),
      api.map({ q: '', sort: 'relevance', filters: {} }, caller.signal),
      api.export({ q: '', sort: 'relevance', filters: {} }),
      searchAll('/all', { profile: 'research_items', q: '' }, caller.signal),
      searchUnion('/union', { q: '' }, caller.signal),
    ];
    // Suggestions and popular searches swallow every failure: no list, the box still works.
    const quiet = [api.suggest('isl', caller.signal), suggestAll('/sa', 'isl'), api.popular()];
    expect(deadlines.map((d) => d.ms)).toEqual([
      REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
      BULK_REQUEST_TIMEOUT_MS,
      BULK_REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
      REQUEST_TIMEOUT_MS,
    ]);
    for (const deadline of deadlines) deadline.fire();
    for (const call of calls) await expect(call).rejects.toMatchObject({ name: 'TimeoutError' });
    expect(await Promise.all(quiet)).toEqual([[], [], []]);
  });

  it('keeps a superseded request an abort, not a failure', async () => {
    hangingFetch();
    const caller = new AbortController();
    const pending = new SearchApi(endpoints, 'research_items').search(request, caller.signal);
    caller.abort();
    const reason = await pending.catch((e: unknown) => e);
    expect(isAbortError(reason)).toBe(true);
  });
});

describe('SeqGuard', () => {
  it('leaves only the latest ticket fresh', () => {
    const seq = new SeqGuard();
    const first = seq.start();
    const second = seq.start();
    expect(seq.isStale(first)).toBe(true);
    expect(seq.isStale(second)).toBe(false);
  });
});
