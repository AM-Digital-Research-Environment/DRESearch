export function installSlashFocus(input: () => HTMLInputElement | undefined): () => void {
  const listener = (event: KeyboardEvent): void => {
    if (
      event.defaultPrevented ||
      event.key !== '/' ||
      event.ctrlKey ||
      event.metaKey ||
      event.altKey
    )
      return;
    const target = event.target as HTMLElement | null;
    if (target?.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target?.tagName ?? ''))
      return;
    event.preventDefault();
    input()?.focus();
  };
  window.addEventListener('keydown', listener);
  return () => window.removeEventListener('keydown', listener);
}
