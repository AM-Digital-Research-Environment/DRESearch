import { render, screen } from '@testing-library/svelte';
import { describe, expect, it } from 'vitest';
import RecordLink from '../../src/svelte/components/RecordLink.svelte';

describe('record links', () => {
  it('links to the record with a named target, or renders nothing', () => {
    const { container } = render(RecordLink, {
      itemUrlBase: '/s/amira/item',
      id: '42',
      name: 'Vierke, Ulf',
    });
    const link = screen.getByRole('link', { name: 'Open Vierke, Ulf' });
    expect(link).toHaveAttribute('href', '/s/amira/item/42');
    render(RecordLink, { itemUrlBase: '/s/amira/item', id: undefined, name: 'Nobody' });
    expect(container.querySelectorAll('a')).toHaveLength(1);
  });
});
