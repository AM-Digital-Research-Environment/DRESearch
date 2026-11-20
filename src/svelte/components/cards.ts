import type { Component } from 'svelte';
import type { CardKind, Doc, ViewMode } from '../lib/types';
import ResultItem from './ResultItem.svelte';
import ProjectCard from './ProjectCard.svelte';
import PublicationCard from './PublicationCard.svelte';
import PodcastCard from './PodcastCard.svelte';
import VideoCard from './VideoCard.svelte';
import ChronicleCard from './ChronicleCard.svelte';
import PersonCard from './PersonCard.svelte';
import SectionCard from './SectionCard.svelte';
import OrganisationCard from './OrganisationCard.svelte';
import TermCard from './TermCard.svelte';

/**
 * What any card may be given. Each card reads the props it declares: a term
 * card its corpus (`profile`, for its entity hue), a research item its view
 * and whether its thumbnail loads eagerly.
 */
export interface CardProps {
  doc: Doc;
  itemUrlBase: string;
  profile?: string;
  view?: ViewMode;
  eager?: boolean;
}

/**
 * The one kind → card map, read by the corpus results list and the federated
 * merged list. A Record over CardKind, so a kind added to CARD_KINDS (which
 * `npm run lint:schema` keeps equal to the mapper registry) fails the type
 * check until it has a card: the two lists used to be if-chains that fell back
 * silently to the research-item card.
 */
export const CARD_COMPONENTS: Record<CardKind, Component<CardProps>> = {
  item: ResultItem,
  project: ProjectCard,
  publication: PublicationCard,
  podcast: PodcastCard,
  video: VideoCard,
  chronicle: ChronicleCard,
  person: PersonCard,
  section: SectionCard,
  organisation: OrganisationCard,
  term: TermCard,
};

/** The card for a kind; an unknown one (a stale document) gets the item card. */
export function cardFor(kind: string | undefined): Component<CardProps> {
  return kind !== undefined && kind in CARD_COMPONENTS
    ? CARD_COMPONENTS[kind as CardKind]
    : ResultItem;
}
