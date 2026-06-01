/**
 * Auto-mount entry point.
 *
 * Walks every [data-dre-search-root] element, reads its sibling JSON state
 * script (#dre-search-state-{blockId}), and mounts an App into it. Multiple
 * blocks per page each get their own bootstrap + state. Idempotent against a
 * double load (defer + manual call) via the data-dre-mounted guard.
 *
 * Mount contract — kept in sync with view/common/block-layout/dre-search-block.phtml.
 */

import { mount } from 'svelte';
import App from './App.svelte';
import type { Bootstrap } from './lib/types';

function readBootstrap(root: HTMLElement): Bootstrap | null {
  const blockId = root.getAttribute('data-dre-block-id') ?? '';
  const stateEl = document.getElementById(`dre-search-state-${blockId}`);
  if (!stateEl?.textContent) {
    console.error('[dre-search] no state script for block', blockId);
    return null;
  }
  try {
    return JSON.parse(stateEl.textContent) as Bootstrap;
  } catch (err) {
    console.error('[dre-search] malformed state JSON for block', blockId, err);
    return null;
  }
}

function mountAll(): void {
  document.querySelectorAll<HTMLElement>('[data-dre-search-root]').forEach((root) => {
    if (root.dataset.dreMounted === '1') {
      return;
    }
    const bootstrap = readBootstrap(root);
    if (!bootstrap) {
      return;
    }
    root.innerHTML = ''; // drop the server-rendered skeleton
    root.dataset.dreMounted = '1';
    mount(App, { target: root, props: { bootstrap } });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
