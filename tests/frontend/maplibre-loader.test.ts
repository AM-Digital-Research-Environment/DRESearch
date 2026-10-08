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
      (['maplibre-gl-worker.mjs', 'maplibre-gl.mjs'] as const).map((file) => [
        file,
        CDN_INTEGRITY[file],
      ]),
    );
    for (const [url] of fetchMock.mock.calls) {
      expect(String(url)).toMatch(
        /^https:\/\/cdn\.jsdelivr\.net\/npm\/maplibre-gl@6\.13\.0\/dist\//,
      );
    }

    const css = document.head.querySelector<HTMLLinkElement>('link[rel="stylesheet"]');
    expect(css?.integrity).toBe(CDN_INTEGRITY['maplibre-gl.css']);
    expect(css?.crossOrigin).toBe('anonymous');

    // The failure is not cached: a later call tries again.
    await expect(loadMapLibre()).rejects.toThrow();
    expect(fetchMock).toHaveBeenCalledTimes(4);
  });

  it('refuses a CDN build whose modules import each other by relative path', async () => {
    // A blob: module cannot resolve "./chunk.mjs"; fail with a clear reason instead.
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(new Response('import{a}from"./maplibre-gl-shared.mjs";'))),
    );
    const createObjectURL = vi.fn(() => 'blob:never');
    const original = URL.createObjectURL;
    URL.createObjectURL = createObjectURL;
    try {
      const { loadMapLibre } = await freshLoader();
      await expect(loadMapLibre()).rejects.toThrow('unexpected build layout');
      expect(createObjectURL).not.toHaveBeenCalled();
    } finally {
      URL.createObjectURL = original;
    }
  });
});

describe('basemapStyle', () => {
  type MapConfigWindow = { RV_MAP_CONFIG?: { lightStyle?: string; darkStyle?: string } };
  const setConfig = (config?: { lightStyle?: string; darkStyle?: string }) => {
    if (config === undefined) delete (window as unknown as MapConfigWindow).RV_MAP_CONFIG;
    else (window as unknown as MapConfigWindow).RV_MAP_CONFIG = config;
  };
  afterEach(() => setConfig(undefined));

  it('falls back to Carto when no configuration is published', async () => {
    setConfig(undefined);
    const { basemapStyle, LIGHT_STYLE, DARK_STYLE } = await freshLoader();
    expect(basemapStyle(false)).toBe(LIGHT_STYLE);
    expect(basemapStyle(true)).toBe(DARK_STYLE);
  });

  it('falls back to Carto when both keys are missing', async () => {
    setConfig({});
    const { basemapStyle, LIGHT_STYLE, DARK_STYLE } = await freshLoader();
    expect(basemapStyle(false)).toBe(LIGHT_STYLE);
    expect(basemapStyle(true)).toBe(DARK_STYLE);
  });

  it('never returns an empty-string style as the URL', async () => {
    // DRE-Visualizations used to emit unset styles as "" — with `??` that
    // became the style URL and the map rendered without a basemap.
    setConfig({ lightStyle: '', darkStyle: '' });
    const { basemapStyle, LIGHT_STYLE, DARK_STYLE } = await freshLoader();
    expect(basemapStyle(false)).toBe(LIGHT_STYLE);
    expect(basemapStyle(true)).toBe(DARK_STYLE);
  });

  it('skips an empty mode-specific style for the other configured one', async () => {
    setConfig({ lightStyle: '/light.json', darkStyle: '' });
    const { basemapStyle } = await freshLoader();
    expect(basemapStyle(true)).toBe('/light.json');
    setConfig({ lightStyle: '', darkStyle: '/dark.json' });
    expect(basemapStyle(false)).toBe('/dark.json');
  });

  it('uses the one configured style for both modes when only one key is set', async () => {
    const { basemapStyle } = await freshLoader();
    setConfig({ lightStyle: '/light.json' });
    expect(basemapStyle(false)).toBe('/light.json');
    expect(basemapStyle(true)).toBe('/light.json');
    setConfig({ darkStyle: '/dark.json' });
    expect(basemapStyle(false)).toBe('/dark.json');
    expect(basemapStyle(true)).toBe('/dark.json');
  });

  it('uses each mode its own style when both are set', async () => {
    setConfig({ lightStyle: '/light.json', darkStyle: '/dark.json' });
    const { basemapStyle } = await freshLoader();
    expect(basemapStyle(false)).toBe('/light.json');
    expect(basemapStyle(true)).toBe('/dark.json');
  });
});

describe('mapLocale', () => {
  it('routes every MapLibre control label through the translator', async () => {
    const { mapLocale } = await freshLoader();
    const locale = mapLocale((key) => `T:${key}`);
    expect(Object.keys(locale).sort()).toEqual([
      'AttributionControl.ToggleAttribution',
      'CooperativeGesturesHandler.MacHelpText',
      'CooperativeGesturesHandler.MobileHelpText',
      'CooperativeGesturesHandler.WindowsHelpText',
      'FullscreenControl.Enter',
      'FullscreenControl.Exit',
      'NavigationControl.ResetBearing',
      'NavigationControl.ZoomIn',
      'NavigationControl.ZoomOut',
      'Popup.Close',
    ]);
    for (const value of Object.values(locale)) expect(value).toMatch(/^T:map_/);
  });

  it('has an English string for every key it asks for', async () => {
    const { mapLocale } = await freshLoader();
    const { t } = await import('../../src/svelte/lib/i18n');
    for (const value of Object.values(mapLocale(t))) expect(value).not.toMatch(/^map_/);
  });

  it('uses the shared navigation-control preset', async () => {
    const { NAVIGATION_CONTROL_OPTIONS } = await freshLoader();
    expect(NAVIGATION_CONTROL_OPTIONS).toEqual({ showCompass: false });
  });
});
