import { render, screen } from '@testing-library/svelte';
import { describe, expect, it } from 'vitest';
import MatchedIn from '../../src/svelte/components/MatchedIn.svelte';
import { HL_END, HL_START, RESTRICTED_TEXT_FIELD } from '../../src/svelte/lib/highlight';
import type { Doc } from '../../src/svelte/lib/types';

const marked = (before: string, word: string, after: string) =>
  `${before}${HL_START}${word}${HL_END}${after}`;

describe('matched-in line', () => {
  it('labels a restricted full-text excerpt apart from the other matches', () => {
    const doc: Doc = {
      id: '1',
      title: 'Record',
      _highlights: {
        subject_ss: [marked('', 'Music', '')],
        [RESTRICTED_TEXT_FIELD]: [marked('…the ', 'mbira', ' was tuned…')],
      },
    };
    const { container } = render(MatchedIn, { doc });

    const lines = container.querySelectorAll('p');
    expect(lines).toHaveLength(2);
    expect(lines[0]).toHaveTextContent('Matched in Subject: Music');
    expect(lines[0]).not.toHaveTextContent('mbira');
    expect(lines[1]).toHaveTextContent('Matched in restricted full text');
    expect(lines[1]).toHaveTextContent('The complete text is not publicly accessible.');
    expect(screen.getByText('mbira').tagName).toBe('MARK');
  });

  it('shows nothing for a record matched only in its metadata', () => {
    const { container } = render(MatchedIn, {
      doc: { id: '2', title: 'Record', _highlights: { title: [marked('', 'Record', '')] } },
      exclude: ['title'],
    });
    expect(container.querySelectorAll('p')).toHaveLength(0);
  });
});
