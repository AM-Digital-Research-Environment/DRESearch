<script lang="ts">
  import type { Doc } from '../lib/types';
  import { provideCardFilters } from '../lib/cardFilters';
  import { filterFieldLabel } from '../lib/i18n';
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
  // The merged list has no filters of its own: a card value is never pressed,
  // and pressing one opens its corpus's tab filtered by it.
  provideCardFilters({
    isActive: () => false,
    toggle: (field, value) => onHandoff(profile, field, value),
    label: filterFieldLabel,
  });
</script>

<div class="dre-mixed">
  <button type="button" class="dre-mixed__source" onclick={() => onHandoff(profile)}
    >{doc._profile_label ?? profile}</button
  >
  {#if doc._kind === 'project'}<ProjectCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'publication'}<PublicationCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'podcast'}<PodcastCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'video'}<VideoCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'person'}<PersonCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'section'}<SectionCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'organisation'}<OrganisationCard {doc} {itemUrlBase} />
  {:else if doc._kind === 'term'}<TermCard {doc} {itemUrlBase} {profile} />
  {:else}<ResultItem {doc} {itemUrlBase} />{/if}
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
    min-height: 1.5rem;
    padding: 0 var(--space-2, 0.5rem);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface-sunken, #f3f0eb);
    color: var(--primary, #007a50);
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    cursor: pointer;
  }
  .dre-mixed__source:hover {
    background: var(--surface-sunken, #f3f0eb);
  }
  .dre-mixed__source:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
</style>
