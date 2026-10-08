/**
 * The entity type a corpus holds, named as in DRE-theme's `--entity-*` token
 * family. A type keeps one hue across search, charts, maps and networks, so a
 * card's type tag takes the hue of its corpus's entity (styles/card.css,
 * `.dre-shell__tag--<entity>`). Corpora without a sub-type tag are absent.
 */
const PROFILE_ENTITIES: Record<string, string> = {
  research_organisations: 'organisation',
  research_locations: 'location',
  research_subjects: 'subject',
  research_genres: 'genre',
  research_languages: 'language',
};

/** The tag modifier class for a corpus, or '' to keep the generic term hue. */
export function entityTagClass(profile: string | undefined): string {
  const entity = profile ? PROFILE_ENTITIES[profile] : undefined;
  return entity ? `dre-shell__tag--${entity}` : '';
}
