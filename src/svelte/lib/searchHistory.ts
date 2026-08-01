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
  try {
    localStorage.setItem(
      KEY,
      JSON.stringify([q, ...recentSearches().filter((old) => old !== q)].slice(0, LIMIT)),
    );
  } catch {
    // Browsing/searching must still work when storage is blocked.
  }
}
