<script lang="ts">
  import type { Doc } from '../lib/types';
  import ResultItem from './ResultItem.svelte';
  import ProjectCard from './ProjectCard.svelte';
  import PublicationCard from './PublicationCard.svelte';
  import PodcastCard from './PodcastCard.svelte';
  import VideoCard from './VideoCard.svelte';
  import PersonCard from './PersonCard.svelte';
  import SectionCard from './SectionCard.svelte';
  import OrganisationCard from './OrganisationCard.svelte';
  import TermCard from './TermCard.svelte';
  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onHandoff: (profile: string, field?: string, value?: string) => void;
  }
  const { doc, itemUrlBase, onHandoff }: Props = $props();
  const profile = $derived(doc._profile ?? '');
  const add = (field: string, value: string): void => onHandoff(profile, field, value);
</script>

<div class="dre-mixed">
  <button type="button" class="dre-mixed__source" onclick={() => onHandoff(profile)}
    >{doc._profile_label ?? profile}</button
  >
  {#if doc._kind === 'project'}<ProjectCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'publication'}<PublicationCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'podcast'}<PodcastCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'video'}<VideoCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'person'}<PersonCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'section'}<SectionCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'organisation'}<OrganisationCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else if doc._kind === 'term'}<TermCard {doc} {itemUrlBase} onAddFilter={add} />
  {:else}<ResultItem {doc} {itemUrlBase} onAddFilter={add} />{/if}
</div>

<style>
  .dre-mixed {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-mixed__source {
    align-self: flex-end;
    margin: 0;
    padding: 0.16rem 0.5rem;
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface-sunken, #f1ede6);
    color: var(--primary, #007a50);
    font: inherit;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 700;
    cursor: pointer;
  }
  .dre-mixed__source:hover {
    background: var(--surface-sunken, #f1ede6);
  }
</style>
