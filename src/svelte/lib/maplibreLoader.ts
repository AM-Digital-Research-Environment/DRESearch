/**
 * Load MapLibre, preferring a copy that is already on the page or vendored
 * same-origin, and only reaching a CDN as a last resort.
 *
 * WHY THE ORDER MATTERS. This is a University of Bayreuth (EU) deployment whose
 * theme self-hosts its fonts specifically to avoid third-party requests, and
 * whose sibling module (DRE-Visualizations) vendors MapLibre same-origin and
 * documents that as a virtue. Fetching a second copy from jsDelivr — plus Carto
 * basemap tiles — put two more third-party origins on the page and shipped a
 * duplicate renderer whenever both modules rendered together. Keep VERSION in
 * step with the copy DRE-Visualizations vendors; there is no reason to fetch it
 * twice.
 *
 * DRE-Visualizations publishes its vendored URLs on `window.RV_LIBS` and its
 * basemap configuration on `window.RV_MAP_CONFIG` (both emitted by its
 * DashboardAssets helper). Reading them is loose coupling, not a dependency:
 * every step degrades, and the CDN remains the floor for a host that has
 * neither module's assets.
 *
 * MapLibre 6 ships ES modules only — an entry and a worker — and no browser
 * global, so every copy is loaded with import() and published as
 * `window.maplibregl`, the shape DRE-Visualizations reads too. (The CDN floor
 * used to request `dist/maplibre-gl.js`, the 5.x UMD file, which 6.x no longer
 * has: it was a 404.)
 */
const VERSION = '6.13.0';
const CDN = `https://cdn.jsdelivr.net/npm/maplibre-gl@${VERSION}/dist/`;

/**
 * Subresource Integrity for the pinned CDN files: sha384 of jsDelivr's copies
 * of maplibre-gl@6.13.0 (whose sha256 matched jsDelivr's published hashes).
 * Changing VERSION means recomputing every one, e.g.
 *   curl -s <CDN>maplibre-gl.mjs | openssl dgst -sha384 -binary | openssl base64 -A
 * and checking the build layout still holds (see loadFromCdn).
 */
export const CDN_INTEGRITY = {
  'maplibre-gl.mjs': 'sha384-tG0qQdL7veKvVoyu6PbnmddDls/OVkTjaK9oVY5eq9afBA6zYptFui69Dx57duAh',
  'maplibre-gl-worker.mjs':
    'sha384-vHxGRAh5VPNspVXjsLuI5wxrF/Jbzfyct4wTbGl6bu89rV2rGJm3cOzMTWIfHK0y',
  'maplibre-gl.css': 'sha384-yFNc3bs28S14jOJo1ZO9SnLiAmtyY2a3H9be8GkLMpDGlO5r1zZNP8fYu+fOCE/w',
} as const;

/** A static import of a relative path, which a module loaded from a blob: URL cannot resolve. */
const RELATIVE_IMPORT = /(?:\bfrom|\bimport)\s*["']\.{1,2}\//;

/** Carto styles — the fallback when no self-hosted basemap is configured. */
export const LIGHT_STYLE = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';
export const DARK_STYLE = 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json';

interface RvLibs {
  maplibre?: string;
  maplibreCss?: string;
  maplibreWorker?: string;
}
interface RvMapConfig {
  lightStyle?: string;
  darkStyle?: string;
}

function rvLibs(): RvLibs {
  return (window as unknown as { RV_LIBS?: RvLibs }).RV_LIBS ?? {};
}

/**
 * The basemap style URL for the given mode.
 *
 * Prefers whatever the deployment configured (DRE-Visualizations' own
 * self-hosted style, when that module is installed) over Carto's CDN.
 */
export function basemapStyle(dark: boolean): string {
  const config = (window as unknown as { RV_MAP_CONFIG?: RvMapConfig }).RV_MAP_CONFIG ?? {};
  const configured = dark
    ? (config.darkStyle ?? config.lightStyle)
    : (config.lightStyle ?? config.darkStyle);
  return configured ?? (dark ? DARK_STYLE : LIGHT_STYLE);
}

export interface MapLike {
  on(event: string, layerOrHandler: unknown, handler?: unknown): void;
  once(event: string, layerOrHandler: unknown, handler?: unknown): void;
  addSource(id: string, source: unknown): void;
  addLayer(layer: unknown): void;
  addControl(control: unknown, position?: string): void;
  getSource(id: string): unknown;
  getCanvas(): HTMLCanvasElement;
  setPaintProperty(layer: string, property: string, value: unknown): void;
  setStyle(style: string): void;
  easeTo(options: unknown): void;
  fitBounds(bounds: [[number, number], [number, number]], options?: unknown): void;
  remove(): void;
}
interface PopupLike {
  setLngLat(value: [number, number]): PopupLike;
  setDOMContent(value: Node): PopupLike;
  addTo(map: MapLike): PopupLike;
}
export interface MapLibreGlobal {
  Map: new (options: unknown) => MapLike;
  Popup: new (options?: unknown) => PopupLike;
  NavigationControl: new (options?: unknown) => unknown;
  setWorkerUrl?: (url: string) => void;
}

function addStylesheet(href: string, integrity?: string): void {
  if (document.querySelector(`link[href="${CSS.escape(href)}"]`)) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  if (integrity) {
    link.integrity = integrity;
    link.crossOrigin = 'anonymous';
  }
  link.href = href;
  document.head.append(link);
}

type Global = { maplibregl?: MapLibreGlobal };

function isMapLibre(value: unknown): value is MapLibreGlobal {
  return typeof (value as Partial<MapLibreGlobal> | null)?.Map === 'function';
}

/** 2. The copy DRE-Visualizations vendors same-origin (an ES module, like ours). */
async function loadVendored(libs: RvLibs & { maplibre: string }): Promise<MapLibreGlobal> {
  if (libs.maplibreCss) addStylesheet(libs.maplibreCss);
  const namespace: unknown = await import(/* @vite-ignore */ libs.maplibre);
  // An older vendored UMD build sets the global instead of exporting.
  const lib = isMapLibre(namespace) ? namespace : (window as unknown as Global).maplibregl;
  if (!isMapLibre(lib)) {
    throw new Error('MapLibre loaded without exposing its browser API.');
  }
  // The vendored build renames its worker chunk, which it then cannot locate on
  // its own from a module asset path.
  if (libs.maplibreWorker && typeof lib.setWorkerUrl === 'function') {
    lib.setWorkerUrl(libs.maplibreWorker);
  }
  return lib;
}

async function fetchVerified(file: keyof typeof CDN_INTEGRITY): Promise<string> {
  // A response that does not match its hash rejects here, before any of it runs.
  const response = await fetch(CDN + file, {
    integrity: CDN_INTEGRITY[file],
    credentials: 'omit',
  });
  if (!response.ok) throw new Error(`MapLibre: ${file} answered ${response.status}.`);
  return response.text();
}

function moduleUrl(code: string): string {
  return URL.createObjectURL(new Blob([code], { type: 'text/javascript' }));
}

/**
 * 3. The CDN floor, with every file checked against CDN_INTEGRITY.
 *
 * A dynamic import() cannot carry an integrity hash. So the entry and the
 * worker are fetched with `integrity` instead, and only the verified text runs,
 * from blob: URLs. In 6.13 both are self-contained (6.1 split out a shared
 * chunk). A build that splits them again would import a relative path that a
 * blob: module cannot resolve, so it is refused up front instead of failing
 * inside MapLibre. The worker URL is handed over explicitly, as for the
 * vendored copy.
 */
async function loadFromCdn(): Promise<MapLibreGlobal> {
  addStylesheet(CDN + 'maplibre-gl.css', CDN_INTEGRITY['maplibre-gl.css']);
  const [entry, worker] = await Promise.all([
    fetchVerified('maplibre-gl.mjs'),
    fetchVerified('maplibre-gl-worker.mjs'),
  ]);
  if (RELATIVE_IMPORT.test(entry) || RELATIVE_IMPORT.test(worker)) {
    throw new Error('MapLibre: unexpected build layout.');
  }
  const lib: unknown = await import(/* @vite-ignore */ moduleUrl(entry));
  if (!isMapLibre(lib) || typeof lib.setWorkerUrl !== 'function') {
    throw new Error('MapLibre loaded without exposing its browser API.');
  }
  lib.setWorkerUrl(moduleUrl(worker));
  return lib;
}

let promise: Promise<MapLibreGlobal> | null = null;

export function loadMapLibre(): Promise<MapLibreGlobal> {
  promise ??= (async () => {
    // 1. Already on the page — the sibling module loaded it, or we did.
    const existing = (window as unknown as Global).maplibregl;
    if (existing) return existing;

    // 2. Vendored same-origin by DRE-Visualizations; 3. the CDN floor.
    const libs = rvLibs();
    const lib = libs.maplibre
      ? await loadVendored({ ...libs, maplibre: libs.maplibre })
      : await loadFromCdn();
    (window as unknown as Global).maplibregl = lib;
    return lib;
  })().catch((error: unknown) => {
    promise = null;
    throw error instanceof Error ? error : new Error('MapLibre could not be loaded.');
  });
  return promise;
}
