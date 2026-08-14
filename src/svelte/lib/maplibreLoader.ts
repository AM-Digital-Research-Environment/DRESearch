/**
 * Load MapLibre, preferring a copy that is already on the page or vendored
 * same-origin, and only reaching a CDN as a last resort.
 *
 * WHY THE ORDER MATTERS. This is a University of Bayreuth (EU) deployment whose
 * theme self-hosts its fonts specifically to avoid third-party requests, and
 * whose sibling module (DRE-Visualizations) already vendors MapLibre 6.1.0
 * same-origin and documents that as a virtue. Fetching a second copy from
 * jsDelivr — plus Carto basemap tiles — put two more third-party origins on the
 * page and shipped a duplicate renderer whenever both modules rendered
 * together. The vendored copy is the same version; there was never a reason to
 * fetch it twice.
 *
 * DRE-Visualizations publishes its vendored URLs on `window.RV_LIBS` and its
 * basemap configuration on `window.RV_MAP_CONFIG` (both emitted by its
 * DashboardAssets helper). Reading them is loose coupling, not a dependency:
 * every step degrades, and the CDN remains the floor for a host that has
 * neither module's assets.
 */
const VERSION = '6.1.0';
const CDN_JS = `https://cdn.jsdelivr.net/npm/maplibre-gl@${VERSION}/dist/maplibre-gl.js`;
const CDN_CSS = `https://cdn.jsdelivr.net/npm/maplibre-gl@${VERSION}/dist/maplibre-gl.css`;

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

function addStylesheet(href: string): void {
  if (document.querySelector(`link[href="${CSS.escape(href)}"]`)) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  document.head.append(link);
}

let promise: Promise<MapLibreGlobal> | null = null;

export function loadMapLibre(): Promise<MapLibreGlobal> {
  promise ??= new Promise((resolve, reject) => {
    // 1. Already on the page — the sibling module loaded it, or we did.
    const existing = (window as unknown as { maplibregl?: MapLibreGlobal }).maplibregl;
    if (existing) {
      resolve(existing);
      return;
    }

    // 2. Vendored same-origin by DRE-Visualizations; 3. the CDN floor.
    const libs = rvLibs();
    const jsUrl = libs.maplibre ?? CDN_JS;
    addStylesheet(libs.maplibreCss ?? CDN_CSS);

    const script = document.createElement('script');
    script.src = jsUrl;
    script.defer = true;
    script.onload = () => {
      const loaded = (window as unknown as { maplibregl?: MapLibreGlobal }).maplibregl;
      if (!loaded) {
        promise = null;
        reject(new Error('MapLibre loaded without exposing its browser API.'));
        return;
      }
      // The vendored build splits its worker into a separate file, which it
      // cannot locate on its own when loaded from a module asset path.
      if (libs.maplibreWorker && typeof loaded.setWorkerUrl === 'function') {
        loaded.setWorkerUrl(libs.maplibreWorker);
      }
      resolve(loaded);
    };
    script.onerror = () => {
      promise = null;
      reject(new Error('MapLibre could not be loaded.'));
    };
    document.head.append(script);
  });
  return promise;
}
