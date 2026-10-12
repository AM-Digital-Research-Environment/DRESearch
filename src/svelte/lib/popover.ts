/**
 * Disclosure popovers — the export menu and the per-record "Cite" menu —
 * close the same way, as the shared contract asks (DRE-theme
 * docs/DESIGN-INTEGRATION.md, "Shared widgets"): Escape closes the open popover
 * and returns focus to its trigger; a press outside closes it without moving
 * focus. The export menu did this on its own; the <details> menus did neither,
 * so one Escape closed one menu and not the other.
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

/** Svelte action for a `<details>` popover. */
export function detailsPopover(node: HTMLDetailsElement): { destroy(): void } {
  let stop: (() => void) | null = null;
  const sync = (): void => {
    stop?.();
    stop = null;
    if (!node.open) return;
    stop = dismissOnEscapeOrOutside(node, (returnFocus) => {
      node.open = false;
      if (returnFocus) node.querySelector('summary')?.focus();
    });
  };
  node.addEventListener('toggle', sync);
  sync();
  return {
    destroy() {
      node.removeEventListener('toggle', sync);
      stop?.();
    },
  };
}
