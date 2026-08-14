<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import {
    basemapStyle,
    loadMapLibre,
    type MapLibreGlobal,
    type MapLike,
  } from '../lib/maplibreLoader';
  import { cssColor, isDark, onThemeChange } from '../lib/tokenBridge';

  /**
   * The map's palette, resolved from the theme's tokens at paint time.
   *
   * These were raw hexes — clusters #007a50, points #d57912, strokes and labels
   * #fff — which had three consequences: changing the brand colour in theme
   * settings re-tinted the whole site except this map; the point colour was the
   * raw Braun pigment rather than --accent; and the white stroke was the exact
   * literal DRE-theme retired --white to prevent. Resolving through the bridge
   * fixes all three, and re-resolving on toggle keeps them right afterwards.
   *
   * The fallbacks are the theme's own generated values (see DRE-theme's
   * asset/css/dre-tokens-fallback.json) and are reached only without the theme.
   */
  function palette() {
    return {
      cluster: cssColor('--primary', '#007a50'),
      point: cssColor('--accent', '#ca7210'),
      stroke: cssColor('--surface', '#fdfcf9'),
      label: cssColor('--primary-contrast', '#fcfcf9'),
    };
  }
  interface Props {
    docs: Doc[];
    loading: boolean;
    capped: boolean;
    itemUrlBase: string;
  }
  const { docs, loading, capped, itemUrlBase }: Props = $props();
  let container = $state<HTMLDivElement>();
  let map: MapLike | null = null;
  let lib: MapLibreGlobal | null = null;
  let ready = $state(false);
  let error = $state('');
  let unsubscribeTheme: (() => void) | null = null;
  const source = 'dre-locations';
  const geojson = $derived({
    type: 'FeatureCollection' as const,
    features: docs
      .filter((d) => Array.isArray(d.geo))
      .map((d) => ({
        type: 'Feature' as const,
        properties: { id: d.id, title: d.title, type: d.type_s ?? '', count: d.item_count ?? 0 },
        geometry: {
          type: 'Point' as const,
          coordinates: [d.geo![1], d.geo![0]] as [number, number],
        },
      })),
  });
  $effect(() => {
    const el = container;
    if (!el) return;
    let cancelled = false;
    loadMapLibre()
      .then((loaded) => {
        if (cancelled) return;
        lib = loaded;
        // The THEME's mode, not the OS's. Asking matchMedia here meant a
        // system-dark visitor who chose light got a light page carrying a
        // dark-matter basemap — the one surface that ignored the toggle.
        map = new loaded.Map({
          container: el,
          style: basemapStyle(isDark()),
          center: [2, 10],
          zoom: 3.2,
          cooperativeGestures: true,
          attributionControl: { compact: true },
        });
        map.addControl(new loaded.NavigationControl({ visualizePitch: false }), 'top-right');
        // The clustered source and its three layers, with colour resolved from
        // the tokens at call time. Factored out because setStyle() discards
        // everything the outgoing style owned — custom layers included — so a
        // basemap swap has to re-add them.
        const addDataLayers = () => {
          if (!map) return;
          const ink = palette();
          map.addSource(source, {
            type: 'geojson',
            data: geojson,
            cluster: true,
            clusterRadius: 44,
            clusterMaxZoom: 11,
          });
          map.addLayer({
            id: 'dre-clusters',
            type: 'circle',
            source,
            filter: ['has', 'point_count'],
            paint: {
              'circle-color': ink.cluster,
              'circle-radius': ['step', ['get', 'point_count'], 15, 25, 21, 100, 27],
              'circle-stroke-color': ink.stroke,
              'circle-stroke-width': 2,
            },
          });
          map.addLayer({
            id: 'dre-cluster-count',
            type: 'symbol',
            source,
            filter: ['has', 'point_count'],
            layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-size': 12 },
            paint: { 'text-color': ink.label },
          });
          map.addLayer({
            id: 'dre-point',
            type: 'circle',
            source,
            filter: ['!', ['has', 'point_count']],
            paint: {
              'circle-color': ink.point,
              'circle-radius': ['interpolate', ['linear'], ['get', 'count'], 0, 6, 100, 11],
              'circle-stroke-color': ink.stroke,
              'circle-stroke-width': 1.5,
            },
          });
        };

        map.on('load', () => {
          if (!map) return;
          addDataLayers();
          map.on('click', 'dre-clusters', (raw: unknown) => {
            const event = raw as {
              features?: Array<{
                properties: { cluster_id: number };
                geometry: { coordinates: [number, number] };
              }>;
            };
            const feature = event.features?.[0];
            const src = map?.getSource(source) as
              { getClusterExpansionZoom(id: number): Promise<number> } | undefined;
            if (feature && src)
              void src
                .getClusterExpansionZoom(feature.properties.cluster_id)
                .then((zoom) => map?.easeTo({ center: feature.geometry.coordinates, zoom }));
          });
          map.on('click', 'dre-point', (raw: unknown) => {
            const event = raw as {
              features?: Array<{
                properties: { id: string; title: string; type: string };
                geometry: { coordinates: [number, number] };
              }>;
            };
            const feature = event.features?.[0];
            if (!feature || !map || !lib) return;
            const body = document.createElement('div');
            const link = document.createElement('a');
            link.href = `${itemUrlBase}/${encodeURIComponent(feature.properties.id)}`;
            link.textContent = feature.properties.title || t('untitled');
            body.append(link);
            if (feature.properties.type) {
              const meta = document.createElement('div');
              meta.textContent = feature.properties.type;
              body.append(meta);
            }
            new lib.Popup({ maxWidth: '20rem' })
              .setLngLat(feature.geometry.coordinates)
              .setDOMContent(body)
              .addTo(map);
          });
          for (const layer of ['dre-clusters', 'dre-point']) {
            map.on('mouseenter', layer, () => {
              if (map) map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', layer, () => {
              if (map) map.getCanvas().style.cursor = '';
            });
          }
          ready = true;
        });

        // Follow the theme toggle. A WebGL map resolves its colours once, so
        // without this it would hold whichever mode it was first painted in
        // while the rest of the page switched around it.
        unsubscribeTheme = onThemeChange((dark) => {
          const target = map;
          if (!target || !ready) return;
          // `once`, not `on`: styledata fires on every style mutation, and a
          // persistent handler would re-add the layers on each of them.
          target.once('styledata', () => addDataLayers());
          target.setStyle(basemapStyle(dark));
        });
      })
      .catch((reason: Error) => {
        if (!cancelled) error = reason.message;
      });
    return () => {
      cancelled = true;
      unsubscribeTheme?.();
      unsubscribeTheme = null;
      map?.remove();
      map = null;
      ready = false;
    };
  });
  $effect(() => {
    const data = geojson;
    if (!map || !ready) return;
    const src = map.getSource(source) as { setData(value: unknown): void } | undefined;
    src?.setData(data);
    if (data.features.length) {
      const lng = data.features.map((f) => f.geometry.coordinates[0]);
      const lat = data.features.map((f) => f.geometry.coordinates[1]);
      map.fitBounds(
        [
          [Math.min(...lng), Math.min(...lat)],
          [Math.max(...lng), Math.max(...lat)],
        ],
        { padding: 48, maxZoom: 8 },
      );
    }
  });
</script>

<section class="dre-map" aria-label={t('map_label')}>
  <div class="dre-map__canvas" bind:this={container}></div>
  {#if error}<p class="dre-map__status" role="alert">
      {t('map_error')}
      {error}
    </p>{:else if loading || !ready}<p class="dre-map__status">
      {t('map_loading')}
    </p>{:else if geojson.features.length === 0}<p class="dre-map__status">
      {t('map_empty')}
    </p>{:else if capped}<p class="dre-map__note">{t('map_capped')}</p>{/if}
</section>

<style>
  .dre-map {
    position: relative;
    min-height: 32rem;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-lg, 0.75rem);
    overflow: hidden;
    background: var(--surface-sunken, #f3f0eb);
  }
  .dre-map__canvas {
    position: absolute;
    inset: 0;
  }
  .dre-map__status,
  .dre-map__note {
    position: absolute;
    z-index: 1;
    inset-inline: 1rem;
    bottom: 1rem;
    margin: 0;
    padding: 0.55rem 0.75rem;
    border-radius: 0.375rem;
    background: var(--surface, #fdfcf9);
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(42, 28, 16, 0.14),
      0 2px 4px -2px rgba(52, 37, 26, 0.07)
    );
    color: var(--ink, #3c342d);
  }
  .dre-map__note {
    font-size: var(--text-xs, 0.8125rem);
  }
  @media (max-width: 40rem) {
    .dre-map {
      min-height: 24rem;
    }
  }
</style>
