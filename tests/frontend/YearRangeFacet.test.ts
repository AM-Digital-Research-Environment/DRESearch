import { fireEvent, render, screen } from '@testing-library/svelte';
import { describe, expect, it, vi } from 'vitest';
import YearRangeFacet from '../../src/svelte/components/YearRangeFacet.svelte';

describe('typed years', () => {
  const facet = () => {
    const onChange = vi.fn();
    render(YearRangeFacet, { min: 1500, max: 2026, from: 1500, to: 2026, onChange });
    return {
      from: screen.getByRole('spinbutton', { name: 'From' }) as HTMLInputElement,
      to: screen.getByRole('spinbutton', { name: 'To' }) as HTMLInputElement,
      onChange,
    };
  };

  it('commits a typed year at once', async () => {
    const { from, onChange } = facet();
    await fireEvent.change(from, { target: { value: '1990' } });
    expect(onChange).toHaveBeenCalledWith(1990, 2026);
  });

  it('clamps to the bounds and keeps the range in order', async () => {
    const { from, to, onChange } = facet();
    await fireEvent.change(to, { target: { value: '3000' } });
    expect(onChange).toHaveBeenLastCalledWith(1500, 2026);
    await fireEvent.change(to, { target: { value: '1900' } });
    await fireEvent.change(from, { target: { value: '1950' } });
    expect(onChange).toHaveBeenLastCalledWith(1900, 1900);
    expect(from.value).toBe('1900');
  });

  it('restores the current year for an empty entry without searching', async () => {
    const { from, onChange } = facet();
    await fireEvent.change(from, { target: { value: '' } });
    expect(onChange).not.toHaveBeenCalled();
    expect(from.value).toBe('1500');
  });
});
