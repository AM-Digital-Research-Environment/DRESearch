import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  BLUR_CLOSE_MS,
  TYPEAHEAD_DEBOUNCE_MS,
  createTypeahead,
  type Typeahead,
  type TypeaheadOptions,
} from '../../src/svelte/lib/typeahead.svelte';

/**
 * The combobox SearchBox and SearchBar share, driven directly. The components'
 * own behaviour over it (starters, grouped suggestions, the collapsible bar) is
 * covered by SearchBox.test.ts, autocomplete-races.test.ts and the browser suite.
 */

type Pending = { q: string; signal: AbortSignal; resolve: (items: string[]) => void };

function make(over: Partial<TypeaheadOptions<string>> = {}) {
  const pending: Pending[] = [];
  const onEnter = vi.fn();
  const onSettle = vi.fn();
  const onEscape = vi.fn();
  const onBlurClose = vi.fn();
  const box: Typeahead<string> = createTypeahead<string>({
    fetch: (q, signal) => new Promise((resolve) => pending.push({ q, signal, resolve })),
    optionCount: () => box.items.length,
    onEnter,
    onSettle,
    onEscape,
    onBlurClose,
    ...over,
  });
  return { box, pending, onEnter, onSettle, onEscape, onBlurClose };
}

const key = (k: string, init: KeyboardEventInit = {}) =>
  new KeyboardEvent('keydown', { key: k, cancelable: true, ...init });

beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
});

describe('createTypeahead', () => {
  it('settles the text and asks for suggestions once the box is still', async () => {
    const { box, pending, onSettle } = make();
    box.focus();
    box.input('isl');
    box.input('islam');
    expect(box.text).toBe('islam');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS - 1);
    expect(pending).toHaveLength(0);
    await vi.advanceTimersByTimeAsync(1);
    expect(onSettle).toHaveBeenCalledExactlyOnceWith('islam');
    expect(pending.map((p) => p.q)).toEqual(['islam']);
    pending[0]!.resolve(['Islam in Kenya']);
    await vi.advanceTimersByTimeAsync(0);
    expect(box.items).toEqual(['Islam in Kenya']);
    expect(box.open).toBe(true);
  });

  it('asks for nothing under two characters, and closes', async () => {
    const { box, pending, onSettle } = make();
    box.focus();
    box.input('i');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    expect(onSettle).toHaveBeenCalledWith('i');
    expect(pending).toHaveLength(0);
    expect(box.open).toBe(false);
  });

  it('drops the list and the request in flight on the next keystroke', async () => {
    const { box, pending } = make();
    box.focus();
    box.input('old query');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    pending[0]!.resolve(['Old']);
    await vi.advanceTimersByTimeAsync(0);
    box.input('old querx');
    expect(box.items).toEqual([]);
    expect(box.open).toBe(false);

    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    box.input('new query');
    expect(pending[1]!.signal.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    pending[2]!.resolve(['Current']);
    await vi.advanceTimersByTimeAsync(0);
    // The superseded request answers last, as a server that ignores the abort would.
    pending[1]!.resolve(['Obsolete']);
    await vi.advanceTimersByTimeAsync(0);
    expect(box.items).toEqual(['Current']);
  });

  it('never restores a list after the box was cleared or set from outside', async () => {
    const { box, pending } = make();
    box.focus();
    box.input('kenya');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    box.clear();
    pending[0]!.resolve(['Kenya']);
    await vi.advanceTimersByTimeAsync(0);
    expect(box.text).toBe('');
    expect(box.open).toBe(false);

    box.input('lagos');
    box.setText('bayreuth');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    expect(pending).toHaveLength(1);
    expect(box.text).toBe('bayreuth');
  });

  it('opens only while focused', async () => {
    const { box, pending } = make();
    box.input('islam');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    pending[0]!.resolve(['Islam']);
    await vi.advanceTimersByTimeAsync(0);
    expect(box.items).toEqual(['Islam']);
    expect(box.open).toBe(false);
    box.focus();
    expect(box.open).toBe(true);
  });

  it('cycles the highlight with the arrow keys and hands Enter the option', async () => {
    const { box, pending, onEnter } = make();
    box.focus();
    box.input('islam');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    pending[0]!.resolve(['a', 'b', 'c']);
    await vi.advanceTimersByTimeAsync(0);

    const down = key('ArrowDown');
    box.keydown(down);
    expect(down.defaultPrevented).toBe(true);
    expect(box.activeIndex).toBe(0);
    box.keydown(key('ArrowUp'));
    expect(box.activeIndex).toBe(2);
    box.keydown(key('ArrowDown'));
    expect(box.activeIndex).toBe(0);
    box.keydown(key('Enter'));
    expect(onEnter).toHaveBeenLastCalledWith(0);

    box.keydown(key('Escape'));
    expect(box.open).toBe(false);
    expect(box.activeIndex).toBe(-1);
    box.keydown(key('Enter'));
    expect(onEnter).toHaveBeenLastCalledWith(-1);
  });

  it('leaves the arrows to the caret when the list has nothing', () => {
    const { box, onEnter } = make();
    box.focus();
    const down = key('ArrowDown');
    box.keydown(down);
    expect(down.defaultPrevented).toBe(false);
    box.keydown(key('Enter'));
    expect(onEnter).toHaveBeenCalledWith(-1);
  });

  it('ignores the Enter that confirms an IME composition', () => {
    const { box, onEnter } = make();
    box.focus();
    box.keydown(key('Enter', { isComposing: true }));
    box.keydown(key('Enter', { keyCode: 229 }));
    expect(onEnter).not.toHaveBeenCalled();
  });

  it('closes a moment after blur, unless focus comes back first', async () => {
    const { box, pending, onBlurClose } = make();
    box.focus();
    box.input('islam');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    pending[0]!.resolve(['Islam']);
    await vi.advanceTimersByTimeAsync(0);

    box.blur();
    expect(box.open).toBe(true); // a click on an option still lands
    box.focus();
    await vi.advanceTimersByTimeAsync(BLUR_CLOSE_MS);
    expect(box.open).toBe(true);
    expect(onBlurClose).not.toHaveBeenCalled();

    box.blur();
    await vi.advanceTimersByTimeAsync(BLUR_CLOSE_MS);
    expect(box.open).toBe(false);
    expect(onBlurClose).toHaveBeenCalledOnce();
  });

  it('uses hasOptions for what may open, and refresh() for late arrivals', () => {
    let starters: string[] = [];
    const { box } = make({
      optionCount: () => starters.length,
      hasOptions: () => starters.length > 0,
    });
    box.focus();
    expect(box.open).toBe(false);
    starters = ['recent search'];
    box.refresh();
    expect(box.open).toBe(true);
  });

  it('aborts what is in flight when destroyed', async () => {
    const { box, pending, onSettle } = make();
    box.input('islam');
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    box.input('islamic');
    box.destroy();
    await vi.advanceTimersByTimeAsync(TYPEAHEAD_DEBOUNCE_MS);
    expect(pending[0]!.signal.aborted).toBe(true);
    expect(onSettle).toHaveBeenCalledTimes(1);
  });
});
