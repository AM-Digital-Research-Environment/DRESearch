import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

/**
 * scripts/check-bundle-size.mjs — the gzip budget at the end of `npm run build`,
 * run against a synthetic asset/dist so each verdict can be provoked. The real
 * build is checked by `npm run build` itself.
 */

type Chunk = {
  file: string;
  name?: string;
  isEntry?: boolean;
  isDynamicEntry?: boolean;
  imports?: string[];
  dynamicImports?: string[];
  css?: string[];
};

let dist: string;

/** A file of `size` random base-36 characters, which gzip cannot shrink much. */
function file(path: string, size: number): void {
  let text = '';
  while (text.length < size) text += Math.random().toString(36).slice(2);
  mkdirSync(join(dist, path, '..'), { recursive: true });
  writeFileSync(join(dist, path), text.slice(0, size));
}

function build(manifest: Record<string, Chunk>): void {
  writeFileSync(join(dist, 'manifest.json'), JSON.stringify(manifest));
  for (const chunk of Object.values(manifest)) {
    for (const path of [chunk.file, ...(chunk.css ?? [])]) file(path, 1000);
  }
}

function run(): { status: number | null; out: string } {
  const result = spawnSync(process.execPath, ['scripts/check-bundle-size.mjs', '--dist', dist], {
    encoding: 'utf8',
  });
  return { status: result.status, out: `${result.stdout}${result.stderr}` };
}

/** Today's shape: entry → shared runtime, App and FederatedApp behind import(). */
const SHAPE: Record<string, Chunk> = {
  '_runtime.js': { file: 'chunks/runtime-a.js', name: 'runtime' },
  'src/svelte/App.svelte': {
    file: 'chunks/App-b.js',
    name: 'App',
    isDynamicEntry: true,
    imports: ['_runtime.js'],
    css: ['chunks/App-b.css'],
  },
  'src/svelte/components/FederatedApp.svelte': {
    file: 'chunks/FederatedApp-c.js',
    name: 'FederatedApp',
    isDynamicEntry: true,
    imports: ['_runtime.js', 'src/svelte/App.svelte'],
    css: ['chunks/FederatedApp-c.css'],
  },
  'src/svelte/main.ts': {
    file: 'dre-search.js',
    name: 'dre-search',
    isEntry: true,
    imports: ['_runtime.js'],
    dynamicImports: ['src/svelte/App.svelte', 'src/svelte/components/FederatedApp.svelte'],
    css: ['dre-search.css'],
  },
};

describe('check-bundle-size', () => {
  beforeEach(() => {
    dist = mkdtempSync(join(tmpdir(), 'dre-dist-'));
  });
  afterEach(() => rmSync(dist, { recursive: true, force: true }));

  it('passes the current shape, budgeting each role once', () => {
    build(SHAPE);
    const { status, out } = run();
    expect(out).toContain('entry imports');
    expect(out).toMatch(/FederatedApp {6}.*chunks\/FederatedApp-c\.js\n/);
    expect(status).toBe(0);
  });

  it('fails when the entry imports App statically', () => {
    build({
      ...SHAPE,
      'src/svelte/main.ts': { ...SHAPE['src/svelte/main.ts']!, imports: ['src/svelte/App.svelte'] },
    });
    const { status, out } = run();
    expect(status).toBe(1);
    expect(out).toContain('App is imported statically by the entry');
  });

  it('fails a lazily loaded chunk that has no budget', () => {
    build({
      ...SHAPE,
      'src/svelte/components/Extra.svelte': {
        file: 'chunks/Extra-d.js',
        name: 'Extra',
        isDynamicEntry: true,
      },
    });
    const { status, out } = run();
    expect(status).toBe(1);
    expect(out).toContain('chunks/Extra-d.js (Extra) has no budget');
  });

  it('fails a role over its budget, naming the file', () => {
    build(SHAPE);
    file('dre-search.js', 40_000);
    const { status, out } = run();
    expect(status).toBe(1);
    expect(out).toMatch(/entry is \d+ B gzipped, over its budget of 4400 B \(dre-search\.js\)/);
  });
});
