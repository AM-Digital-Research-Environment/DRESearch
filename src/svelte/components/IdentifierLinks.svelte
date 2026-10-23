<script lang="ts">
  import { hasMark, type ExternalId } from '../lib/identifiers';
  import { t } from '../lib/i18n';
  import RegisterMark from './RegisterMark.svelte';
  import '../styles/card.css';

  /**
   * The registers a record is reconciled with, as a row of marked links:
   *
   *   [iD] 0000-0002-1825-0097 ↗   [|||] Q1234567 ↗   [GND] 132450585 ↗
   *
   * Each opens the record in that register. The mark identifies the register
   * at a glance; its name is in the link text for whoever cannot see it, and in
   * the tooltip for whoever does not know the Wikidata barcode. A register
   * without a mark of its own (VIAF, ROR…) names itself visibly: a link glyph
   * beside a bare number says nothing. Renders nothing without an identifier.
   */
  interface Props {
    ids: ExternalId[];
  }
  const { ids }: Props = $props();
</script>

{#if ids.length > 0}
  <ul class="dre-shell__ids" aria-label={t('identifiers_label')}>
    {#each ids as ext (ext.href)}
      <li>
        <a
          class="dre-shell__id"
          href={ext.href}
          target="_blank"
          rel="noopener noreferrer"
          title="{ext.label} {ext.id}"
          ><RegisterMark key={ext.key} /><span
            class={hasMark(ext.key) ? 'dre-shell__sr' : 'dre-shell__id-register'}
            >{`${ext.label} `}</span
          ><span class="dre-shell__id-value">{ext.id}</span></a
        >
      </li>
    {/each}
  </ul>
{/if}
