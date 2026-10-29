/**
 * The search-box combobox: the typed text, the suggestion request, the open
 * listbox and its highlighted option — one implementation for the block's
 * SearchBox and the theme header's SearchBar, which used to carry two copies
 * of it that drifted apart one fix at a time.
 *
 * The components keep what genuinely differs: what a suggestion is (one
 * corpus's titles, or every corpus's grouped), what Enter does with the
 * highlighted option (run a recent search, open a record, go to the results
 * page), the search starters of an empty box, and the header bar's
 * collapsible overlay. They pass those in as callbacks; everything the two
 * boxes share lives here, as plain state the template reads.
 *
 * The rules this carries, each of which regressed at least once:
 *
 *  - Every keystroke invalidates the suggestions on screen AND the request in
 *    flight, at once: a list for "islam" must never come back over "isla", nor
 *    reappear after the box was cleared (tests/frontend/autocomplete-races.test.ts).
 *  - A request settles only if it is still the latest, its signal was not
 *    aborted, and the box still holds the text it was made for — a server that
 *    ignores the abort cannot restore an obsolete list.
 *  - The list opens only while the box has focus; blur closes it a moment
 *    later, so a click on an option lands before the list unmounts.
 *  - Enter that confirms an IME composition (Japanese, Chinese, Korean input)
 *    is not a search.
 *
 * No `$effect` in here, so the module is driven directly by its tests; the
 * components own the effects (scrolling the highlighted option into view,
 * the teardown that calls {@link Typeahead.destroy}).
 */

/** Debounce after the last keystroke before the box asks for suggestions. */
export const TYPEAHEAD_DEBOUNCE_MS = 250;
/** Fewer characters than this ask for nothing. */
export const TYPEAHEAD_MIN_CHARS = 2;
/** Grace period so a click on an option registers before the list closes. */
export const BLUR_CLOSE_MS = 150;

export interface TypeaheadOptions<T> {
  /** The box's starting text. */
  initial?: string;
  /** Suggestions for `q`. A failure or an abort should resolve to an empty list. */
  fetch: (q: string, signal: AbortSignal) => Promise<T[]>;
  /** How many options the arrow keys cycle through (may include rows that are not suggestions). */
  optionCount: () => number;
  /** Whether the listbox has anything for the arrow keys and Enter. Defaults to `optionCount() > 0`. */
  hasOptions?: () => boolean;
  /** After the debounce, before the request: SearchBox commits the typed query here. */
  onSettle?: (text: string) => void;
  /** Enter: the highlighted option's index, or -1 when none is (search the typed text). */
  onEnter: (index: number) => void;
  /** Escape closed the list (the header bar collapses its overlay). */
  onEscape?: () => void;
  /** The deferred blur close ran (the header bar collapses an empty overlay). */
  onBlurClose?: () => void;
  debounceMs?: number;
  minChars?: number;
}

export interface Typeahead<T> {
  /** The text in the box (the input's `value`). */
  readonly text: string;
  readonly items: T[];
  readonly open: boolean;
  readonly focused: boolean;
  /** The highlighted option, or -1. */
  readonly activeIndex: number;
  /** A keystroke: drop what is on screen and in flight, then ask again after the debounce. */
  input(value: string): void;
  /** Put `value` in the box from outside (a removed query chip, Back), without asking for anything. */
  setText(value: string): void;
  /** Empty the box. */
  clear(): void;
  /** Drop the suggestions, the pending request and the highlight; close the list. */
  cancel(): void;
  close(): void;
  focus(): void;
  blur(): void;
  /** Open the list if there is now something to show (e.g. search starters that just loaded). */
  refresh(): void;
  keydown(e: KeyboardEvent): void;
  /** Abort and clear timers (the component is unmounting). */
  destroy(): void;
}

export function createTypeahead<T>(opts: TypeaheadOptions<T>): Typeahead<T> {
  const debounceMs = opts.debounceMs ?? TYPEAHEAD_DEBOUNCE_MS;
  const minChars = opts.minChars ?? TYPEAHEAD_MIN_CHARS;
  const hasOptions = opts.hasOptions ?? (() => opts.optionCount() > 0);

  let text = $state(opts.initial ?? '');
  let items = $state<T[]>([]);
  let open = $state(false);
  let focused = $state(false);
  let activeIndex = $state(-1);
  let timer: ReturnType<typeof setTimeout> | null = null;
  let blurTimer: ReturnType<typeof setTimeout> | null = null;
  let controller: AbortController | null = null;

  function clearTimer(): void {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  }

  function cancel(): void {
    controller?.abort();
    controller = null;
    clearTimer();
    items = [];
    open = false;
    activeIndex = -1;
  }

  async function request(q: string): Promise<void> {
    if (q.trim().length < minChars) {
      items = [];
      open = false;
      return;
    }
    controller?.abort();
    const mine = new AbortController();
    controller = mine;
    const results = await opts.fetch(q, mine.signal);
    if (mine.signal.aborted || controller !== mine || text !== q) return;
    items = results;
    activeIndex = -1;
    open = focused && results.length > 0;
  }

  return {
    get text() {
      return text;
    },
    get items() {
      return items;
    },
    get open() {
      return open;
    },
    get focused() {
      return focused;
    },
    get activeIndex() {
      return activeIndex;
    },
    input(value: string): void {
      cancel();
      text = value;
      timer = setTimeout(() => {
        timer = null;
        opts.onSettle?.(value);
        void request(value);
      }, debounceMs);
    },
    setText(value: string): void {
      cancel();
      text = value;
    },
    clear(): void {
      cancel();
      text = '';
    },
    cancel,
    close(): void {
      open = false;
      activeIndex = -1;
    },
    focus(): void {
      // Focus came back before a pending blur closed the list: keep it.
      if (blurTimer !== null) clearTimeout(blurTimer);
      blurTimer = null;
      focused = true;
      open = hasOptions();
    },
    blur(): void {
      focused = false;
      // Defer so a click on a suggestion lands before the list unmounts.
      if (blurTimer !== null) clearTimeout(blurTimer);
      blurTimer = setTimeout(() => {
        blurTimer = null;
        open = false;
        opts.onBlurClose?.();
      }, BLUR_CLOSE_MS);
    },
    refresh(): void {
      if (focused && hasOptions()) open = true;
    },
    keydown(e: KeyboardEvent): void {
      // An IME uses Enter to confirm the composed characters; that is not a search.
      if (e.isComposing || e.keyCode === 229) return;
      const count = opts.optionCount();
      const has = open && hasOptions();
      switch (e.key) {
        case 'ArrowDown':
          if (has && count > 0) {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % count;
          }
          break;
        case 'ArrowUp':
          if (has && count > 0) {
            e.preventDefault();
            activeIndex = (activeIndex - 1 + count) % count;
          }
          break;
        case 'Enter':
          e.preventDefault();
          opts.onEnter(has && activeIndex >= 0 && activeIndex < count ? activeIndex : -1);
          break;
        case 'Escape':
          open = false;
          activeIndex = -1;
          opts.onEscape?.();
          break;
      }
    },
    destroy(): void {
      controller?.abort();
      clearTimer();
      if (blurTimer !== null) clearTimeout(blurTimer);
      blurTimer = null;
    },
  };
}
