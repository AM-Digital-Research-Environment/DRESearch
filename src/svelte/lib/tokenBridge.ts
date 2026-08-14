/**
 * Read the DRE design tokens from JavaScript.
 *
 * WHY. The token layer is a CSS API, and the moment this client paints to a
 * WebGL map the tokens stop being reachable: MapLibre cannot parse `var(--x)`,
 * let alone the `oklch()` / `color-mix(in oklab, …)` the theme actually authors.
 * The map therefore used to carry raw brand hexes — which made it the one
 * surface on the site that ignored both the theme toggle and the admin's brand
 * colour, and that painted `#fff` strokes, the literal DRE-theme retired
 * `--white` to prevent.
 *
 * DRE-theme publishes the canonical bridge as `window.DRETokens`
 * (asset/js/dre-token-bridge.js). This module prefers it and falls back to an
 * equivalent local implementation, so the client still resolves tokens when it
 * is mounted in a host that does not ship the theme.
 *
 * The technique either way: a hidden probe parented to <body> inherits the live
 * `[data-theme]` cascade, and a 1×1 canvas rasterises whatever the browser
 * computed into a plain `rgb()` string.
 */

interface DRETokensGlobal {
  toRGB(color: string): string;
  cssColor(name: string, fallback?: string): string;
  cssFont(name: string, fallback?: string): string;
  isDark(): boolean;
  onThemeChange(handler: (dark: boolean) => void): () => void;
}

function themeBridge(): DRETokensGlobal | null {
  const global = (window as unknown as { DRETokens?: DRETokensGlobal }).DRETokens;
  return global && typeof global.cssColor === 'function' ? global : null;
}

let probe: HTMLSpanElement | null = null;
let ctx: CanvasRenderingContext2D | null = null;

function getProbe(): HTMLSpanElement {
  if (!probe) {
    probe = document.createElement('span');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText =
      'position:absolute;left:-9999px;top:-9999px;width:0;height:0;pointer-events:none';
  }
  const host = document.body || document.documentElement;
  if (probe.parentNode !== host) host.appendChild(probe);
  return probe;
}

/** Rasterise any browser-parseable colour to a plain rgb()/rgba() string. */
export function toRGB(color: string): string {
  const bridge = themeBridge();
  if (bridge) return bridge.toRGB(color);
  if (!ctx) {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    ctx = canvas.getContext('2d', { willReadFrequently: true });
  }
  if (!ctx) return color;
  ctx.clearRect(0, 0, 1, 1);
  ctx.fillStyle = '#000';
  ctx.fillStyle = color;
  ctx.fillRect(0, 0, 1, 1);
  const d = ctx.getImageData(0, 0, 1, 1).data;
  if (d[3] === 0) return 'rgba(0,0,0,0)';
  if (d[3] === 255) return `rgb(${d[0]},${d[1]},${d[2]})`;
  return `rgba(${d[0]},${d[1]},${d[2]},${(d[3] / 255).toFixed(3)})`;
}

/**
 * Resolve a custom property to a plain rgb()/rgba() colour.
 *
 * `fallback` is only reached on a host without the theme. Take its value from
 * DRE-theme's generated asset/css/dre-tokens-fallback.json rather than typing a
 * hex — that is the rule the whole fallback table exists to make checkable.
 */
export function cssColor(name: string, fallback = '#000'): string {
  const bridge = themeBridge();
  if (bridge) return bridge.cssColor(name, fallback);
  try {
    const el = getProbe();
    el.style.color = '';
    el.style.color = `var(${name}, ${fallback})`;
    return toRGB(window.getComputedStyle(el).color || fallback) || fallback;
  } catch {
    return fallback;
  }
}

/** Resolve a custom property holding a font stack. */
export function cssFont(name: string, fallback = 'system-ui, sans-serif'): string {
  const bridge = themeBridge();
  if (bridge) return bridge.cssFont(name, fallback);
  try {
    const el = getProbe();
    el.style.fontFamily = '';
    el.style.fontFamily = `var(${name}, ${fallback})`;
    return window.getComputedStyle(el).fontFamily || fallback;
  } catch {
    return fallback;
  }
}

/**
 * Whether the active theme is dark.
 *
 * [data-theme] is the answer, NOT `prefers-color-scheme`. The theme's head
 * script resolves the mode — stored choice first, OS preference only as the
 * default — and writes it to <html> and <body> before first paint. Reading the
 * OS directly inverts for every visitor who overrode their system setting, and
 * that was exactly the bug here: a system-dark visitor who chose light got a
 * light page carrying a dark-matter basemap.
 */
export function isDark(): boolean {
  const bridge = themeBridge();
  if (bridge) return bridge.isDark();
  const body = document.body?.getAttribute('data-theme');
  if (body === 'dark') return true;
  if (body === 'light') return false;
  const root = document.documentElement.getAttribute('data-theme');
  if (root === 'dark') return true;
  if (root === 'light') return false;
  return !!window.matchMedia?.('(prefers-color-scheme: dark)').matches;
}

/**
 * Call `handler` whenever the theme mode changes. Returns an unsubscribe
 * function. A canvas that resolved its colours once must re-read them here, or
 * it freezes the mode it was first painted in.
 */
export function onThemeChange(handler: (dark: boolean) => void): () => void {
  const bridge = themeBridge();
  if (bridge) return bridge.onThemeChange(handler);
  if (!window.MutationObserver) return () => {};
  const observer = new MutationObserver(() => handler(isDark()));
  const options = { attributes: true, attributeFilter: ['data-theme'] };
  observer.observe(document.documentElement, options);
  if (document.body) observer.observe(document.body, options);
  return () => observer.disconnect();
}
