/**
 * Advisories `npm run check:audit` lets through at high or critical severity.
 *
 * An entry is an admission, not a fix: it has to say why no upgrade clears the
 * advisory and why the vulnerable code cannot be reached. The check retires
 * entries on its own — it fails as soon as npm can fix an excepted advisory in
 * range, and as soon as one stops appearing — so this list cannot outlive its
 * reason the way a hand-kept ignore list would.
 *
 * Keyed by the GitHub advisory id, the last segment of the advisory's URL:
 *
 *   'GHSA-xxxx-xxxx-xxxx': {
 *     package: 'name',
 *     since: 'YYYY-MM-DD',
 *     reason: 'why no release fixes it, and why the code is unreachable',
 *   },
 *
 * Empty on purpose: `npm audit` reported nothing at high or critical when the
 * gate was ported from IwacSearch (2026-10-29; two low advisories, deepmerge
 * through @sveltejs/vite-plugin-svelte, sit below the gate). Every npm
 * dependency here is a dev dependency — the release zip carries the compiled
 * bundle, never node_modules — but that is a reason for an entry's text, never
 * for weakening the gate with `--omit=dev`, which would turn it off entirely.
 */

export default {};
