/**
 * Auto-mount entry point for all three DRE Search surfaces:
 *   - [data-dre-search-root]    → App          (a per-page faceted search block)
 *   - [data-dre-search-bar]     → SearchBar    (the theme header federated bar)
 *   - [data-dre-federated-root] → FederatedApp (the grouped-by-type results page)
 *
 * Each root carries an id attribute and has a sibling JSON <script> holding its
 * bootstrap (see the matching PHP: block template, SearchBar / FederatedSearch
 * view helpers). Idempotent against a double load via the data-dre-mounted guard.
 */

import { mount } from 'svelte';
import SearchBar from './components/SearchBar.svelte';
import type { Bootstrap, FederatedBootstrap, SearchBarBootstrap } from './lib/types';

/**
 * Walk every not-yet-mounted root matching `selector`, read its sibling state
 * script (`${statePrefix}${root[idAttr]}`), clear the skeleton, and hand the
 * parsed bootstrap to `mountInto`.
 */
function mountRoots(
  selector: string,
  idAttr: string,
  statePrefix: string,
  mountInto: (root: HTMLElement, bootstrap: unknown) => void | Promise<void>,
): void {
  document.querySelectorAll<HTMLElement>(selector).forEach((root) => {
    if (root.dataset.dreMounted) {
      return;
    }
    const id = root.getAttribute(idAttr) ?? '';
    const stateEl = document.getElementById(`${statePrefix}${id}`);
    if (!stateEl?.textContent) {
      console.error('[dre-search] no state script for', selector, id);
      return;
    }
    let bootstrap: unknown;
    try {
      bootstrap = JSON.parse(stateEl.textContent);
    } catch (err) {
      console.error('[dre-search] malformed state JSON for', selector, id, err);
      return;
    }
    root.dataset.dreMounted = 'loading';
    const fallback = root.innerHTML;
    Promise.resolve()
      .then(() => mountInto(root, bootstrap))
      .then(() => {
        root.dataset.dreMounted = '1';
      })
      .catch((err: unknown) => {
        root.innerHTML = fallback;
        delete root.dataset.dreMounted;
        console.error('[dre-search] could not mount', selector, id, err);
        // The restored skeleton is decorative (aria-hidden): without a message
        // a failed chunk (e.g. a stale cached entry after an upgrade) looked
        // like a search that never finished loading.
        const notice = document.createElement('p');
        notice.className = 'dre-search-mount-error';
        notice.setAttribute('role', 'alert');
        notice.textContent = 'Search could not be loaded. ';
        const reload = document.createElement('a');
        reload.href = window.location.href;
        reload.textContent = 'Reload the page';
        notice.append(reload);
        root.prepend(notice);
      });
  });
}

function mountAll(): void {
  mountRoots(
    '[data-dre-search-root]',
    'data-dre-block-id',
    'dre-search-state-',
    async (root, bootstrap) => {
      const { default: App } = await import('./App.svelte');
      root.innerHTML = '';
      mount(App, { target: root, props: { bootstrap: bootstrap as Bootstrap } });
    },
  );
  mountRoots(
    '[data-dre-search-bar]',
    'data-dre-bar-id',
    'dre-search-bar-state-',
    (root, bootstrap) => {
      root.innerHTML = '';
      mount(SearchBar, { target: root, props: { bootstrap: bootstrap as SearchBarBootstrap } });
    },
  );
  mountRoots(
    '[data-dre-federated-root]',
    'data-dre-fed-id',
    'dre-federated-state-',
    async (root, bootstrap) => {
      const { default: FederatedApp } = await import('./components/FederatedApp.svelte');
      root.innerHTML = '';
      mount(FederatedApp, { target: root, props: { bootstrap: bootstrap as FederatedBootstrap } });
    },
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
