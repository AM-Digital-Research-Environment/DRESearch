const VERSION = '6.1.0';
const JS_URL = `https://cdn.jsdelivr.net/npm/maplibre-gl@${VERSION}/dist/maplibre-gl.js`;
const CSS_URL = `https://cdn.jsdelivr.net/npm/maplibre-gl@${VERSION}/dist/maplibre-gl.css`;
export const LIGHT_STYLE = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';
export const DARK_STYLE = 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json';

export interface MapLike {
  on(event: string, layerOrHandler: unknown, handler?: unknown): void;
  addSource(id: string, source: unknown): void;
  addLayer(layer: unknown): void;
  addControl(control: unknown, position?: string): void;
  getSource(id: string): unknown;
  getCanvas(): HTMLCanvasElement;
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
}

let promise: Promise<MapLibreGlobal> | null = null;
export function loadMapLibre(): Promise<MapLibreGlobal> {
  promise ??= new Promise((resolve, reject) => {
    const global = (window as unknown as Record<string, unknown>).maplibregl;
    if (global) {
      resolve(global as MapLibreGlobal);
      return;
    }
    if (!document.querySelector(`link[href="${CSS_URL}"]`)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = CSS_URL;
      document.head.append(link);
    }
    const script = document.createElement('script');
    script.src = JS_URL;
    script.defer = true;
    script.onload = () => {
      const loaded = (window as unknown as Record<string, unknown>).maplibregl;
      if (loaded) resolve(loaded as MapLibreGlobal);
      else {
        promise = null;
        reject(new Error('MapLibre loaded without exposing its browser API.'));
      }
    };
    script.onerror = () => {
      promise = null;
      reject(new Error('MapLibre could not be loaded.'));
    };
    document.head.append(script);
  });
  return promise;
}
