import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Loader = typeof import('../../src/svelte/lib/maplibreLoader');

// The loader caches its promise at module scope; each test gets a fresh copy.
async function freshLoader(): Promise<Loader> {
  vi.resetModules();
  return import('../../src/svelte/lib/maplibreLoader');
}

describe('MapLibre loader', () => {
  beforeEach(() => {
    document.head.innerHTML = '';
    delete (window as unknown as { maplibregl?: unknown }).maplibregl;
    delete (window as unknown as { RV_LIBS?: unknown }).RV_LIBS;
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reuses a copy already on the page', async () => {
    const existing = { Map: function Map() {} };
    (window as unknown as { maplibregl: unknown }).maplibregl = existing;
    const { loadMapLibre } = await freshLoader();
    await expect(loadMapLibre()).resolves.toBe(existing);
    expect(document.head.children).toHaveLength(0);
  });

  it('pins every CDN file to a sha384 hash', async () => {
    const { CDN_INTEGRITY } = await freshLoader();
    expect(Object.keys(CDN_INTEGRITY).sort()).toEqual([
      'maplibre-gl-shared.mjs',
      'maplibre-gl-worker.mjs',
      'maplibre-gl.css',
      'maplibre-gl.mjs',
    ]);
    for (const hash of Object.values(CDN_INTEGRITY)) {
      expect(hash).toMatch(/^sha384-[A-Za-z0-9+/]{64}$/);
    }
  });

  it('fetches the CDN floor with integrity and gives up cleanly when it does not match', async () => {
    // A tampered file makes fetch() reject — that is how SRI fails.
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
    vi.stubGlobal('fetch', fetchMock);
    const { loadMapLibre, CDN_INTEGRITY } = await freshLoader();

    await expect(loadMapLibre()).rejects.toThrow('Failed to fetch');
    const requested = fetchMock.mock.calls.map(([url, init]) => [
      String(url).split('/').pop(),
      (init as RequestInit).integrity,
    ]);
    expect(requested.sort()).toEqual(
      (['maplibre-gl-shared.mjs', 'maplibre-gl-worker.mjs', 'maplibre-gl.mjs'] as const).map(
        (file) => [file, CDN_INTEGRITY[file]],
      ),
    );
    for (const [url] of fetchMock.mock.calls) {
      expect(String(url)).toMatch(
        /^https:\/\/cdn\.jsdelivr\.net\/npm\/maplibre-gl@6\.1\.0\/dist\//,
      );
    }

    const css = document.head.querySelector<HTMLLinkElement>('link[rel="stylesheet"]');
    expect(css?.integrity).toBe(CDN_INTEGRITY['maplibre-gl.css']);
    expect(css?.crossOrigin).toBe('anonymous');

    // The failure is not cached: a later call tries again.
    await expect(loadMapLibre()).rejects.toThrow();
    expect(fetchMock).toHaveBeenCalledTimes(6);
  });
});
