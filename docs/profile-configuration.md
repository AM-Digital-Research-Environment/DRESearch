# Search profile configuration

Profiles live under `dre_search.profiles` and may be overridden in Omeka's
`config/local.config.php`.

The registry checks these invariants:

- profile names and Typesense fields are identifiers;
- collection aliases are unique;
- `template_id` or `item_set_id` defines the primary source;
- `query_by` contains only indexed base, facet, or display fields;
- date modes are `none`, `single`, or `range`, and dated profiles name a source
  property;
- display field types are supported;
- custom/count sorts reference display fields marked `sort: true`;
- the default sort is exposed by the profile.

Facet definitions need a source `property`, or `derived: true` when the mapper
computes the value. `index: false` is for payload-only fields and cannot be
faceted, sorted, or search-only. `search_only: true` indexes large text such as a
transcript but excludes it from returned documents.

The line under an autocomplete suggestion's title comes from the profile's
kind (`SearchProfile::SUBTITLE_DEFAULTS`: a project's section and years, a
publication's first author, else editor, else type, and its year, …). A profile
may set its own with `suggest_subtitle`: a list of parts, joined with "·", each
a field or `a|b` alternatives (the first with a value wins; a list field gives
its first value). The token `year_range` gives a range corpus's years
(`year_start`, or `year_start–year_end`):

```php
'suggest_subtitle' => ['author_ss|editor_ss|type_s', 'year'],
```

A part naming a field the corpus does not store is refused, as is an empty
part.

A publication profile may name a `fulltext_source`: a `property` and an http(s)
`url_prefix`. The first value of that property under the prefix marks the record
`has_fulltext: "Yes"` and is returned as `fulltext_url_s`, the card's **Full
text** link. AMIRA reads EPub Bayreuth permalinks off `bibo:uri`, because a
record there carries an open-access PDF. Without `fulltext_source`, any record
with extracted text (the `fulltext` display field) is flagged instead.

## Overriding from local.config.php

Omeka merges `local.config.php` over module config with
`Laminas\Stdlib\ArrayUtils::merge`: string keys are merged key by key, but
**lists are appended**. To narrow or reorder a list — `federated.union_profiles`,
a profile's `read_properties` or `extra_sources` — replace it explicitly:

```php
use Laminas\Stdlib\ArrayUtils\MergeReplaceKey;

return [
    'dre_search' => [
        'federated' => [
            'union_profiles' => new MergeReplaceKey(['research_items', 'research_publications']),
        ],
    ],
];
```

## Adding a corpus

Adding a corpus of an existing kind takes:

1. a profile under `dre_search.profiles` (collection alias, source scope, fields);
2. a block layout: a row in `Settings\BlockProfiles::LAYOUTS` (the layout
   name, the profile it searches and its block-picker label), and the same
   name under `block_layouts` in `config/module.config.php` with
   `SearchBlockFactory` (a test keeps the two in step; the scope resolver checks
   that a block's layout matches the profile it searches). A layout name is
   stored by every block placed with it, so it never changes once released;
3. optionally an entry in `federated.union_profiles`;
4. `npm run i18n` to add its labels to `language/template.pot`;
5. raising the profile count asserted in `tests/phpunit/SearchProfileTest.php`;
6. **Reindex** of the new corpus.

A new mapper kind additionally needs a mapper (one entry in
`Indexer\MapperRegistry::default()`, which also makes the kind valid in config)
and a result card (`src/svelte/components`).
