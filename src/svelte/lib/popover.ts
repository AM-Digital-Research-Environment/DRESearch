/**
 * Disclosure popovers — the export menu, the per-record "Cite" menu and the
 * mobile "Share and export" disclosure — close the same way, as the shared
 * contract asks (DRE-theme docs/DESIGN-INTEGRATION.md, "Shared widgets"):
 * Escape closes the open popover and returns focus to its trigger; a press
 * outside closes it without moving focus. The export menu did this on its
 * own; the two <details> menus did neither, so one Escape closed one menu and
 * not the other.
 */

/**
 * While a popover is open, close it on Escape (`returnFocus` true) or on a
 * pointer press outside `root` (`returnFocus` false). Returns the cleanup.
 */
export function dismissOnEscapeOrOutside(
  root: HTMLElement,
  close: (returnFocus: boolean) => void,
): () => void {
  const onPointerDown = (event: PointerEvent): void => {
    if (!root.contains(event.target as Node)) close(false);
  };
  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') close(true);
  };
  window.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('keydown', onKeydown);
  return () => {
    window.removeEventListener('pointerdown', onPointerDown);
    window.removeEventListener('keydown', onKeydown);
  };
}

/**
 * Svelte action for a `<details>` popover. `enabled: false` leaves it alone —
 * the results toolbar's disclosure is pinned open on wide screens, where its
 * summary is hidden and there is nothing to dismiss.
 */
export function detailsPopover(
  node: HTMLDetailsElement,
  options: { enabled?: boolean } = {},
): { update(next: { enabled?: boolean }): void; destroy(): void } {
  let enabled = options.enabled ?? true;
  let stop: (() => void) | null = null;
  const sync = (): void => {
    stop?.();
    stop = null;
    if (!node.open || !enabled) return;
    stop = dismissOnEscapeOrOutside(node, (returnFocus) => {
      node.open = false;
      if (returnFocus) node.querySelector('summary')?.focus();
    });
  };
  node.addEventListener('toggle', sync);
  sync();
  return {
    update(next) {
      enabled = next.enabled ?? true;
      sync();
    },
    destroy() {
      node.removeEventListener('toggle', sync);
      stop?.();
    },
  };
}
