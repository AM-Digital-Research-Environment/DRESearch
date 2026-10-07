const KEY = 'dre-search:recent';
const LIMIT = 6;

export function recentSearches(): string[] {
  try {
    const value: unknown = JSON.parse(localStorage.getItem(KEY) ?? '[]');
    return Array.isArray(value)
      ? value.filter((q): q is string => typeof q === 'string').slice(0, LIMIT)
      : [];
  } catch {
    return [];
  }
}

export function rememberSearch(query: string): void {
  const q = query.trim();
  if (!q) return;
  const folded = q.toLocaleLowerCase();
  try {
    // A search runs at every typing pause, so "col", "colon", "colonial" all
    // arrive here: the longer query supersedes its prefixes rather than all
    // three filling the short list.
    const kept = recentSearches().filter((old) => {
      const prior = old.toLocaleLowerCase();
      return prior !== folded && !folded.startsWith(prior);
    });
    localStorage.setItem(KEY, JSON.stringify([q, ...kept].slice(0, LIMIT)));
  } catch {
    // Browsing/searching must still work when storage is blocked.
  }
}
