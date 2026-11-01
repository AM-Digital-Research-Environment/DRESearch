# Search profile configuration

Profiles live under `dre_search.profiles` and may be overridden in Omeka's
`config/local.config.php`.

The registry checks these invariants:

- profile names and Typesense fields are identifiers;
- collection aliases are unique;
- `template_id` or `item_set_id` defines the primary source;
- `query_by` contains only indexed base, facet, or display fields, never
  `restricted_text` (see [Restricted full text](#restricted-full-text));
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

## Restricted full text

A profile may let visitors find records through a full text they may not
read: a **private** value approved for public discovery and limited quotation,
such as the OCR text of ILAM's *African Music* articles (item set 27724, private
`bibo:content`, under the journal's copyright). It is the one exception to the
rule that the indexer reads only what an anonymous visitor may see, so it is
off unless an operator names the collections it covers:

```php
// config/local.config.php
return [
    'dre_search' => [
        'profiles' => [
            'research_items' => [
                'restricted_fulltext' => ['item_sets' => [27724]],
            ],
        ],
    ],
];
```

The shipped `research_items` profile declares the block with `property:
bibo:content` and no item sets. With item sets named:

- **Eligibility.** A record contributes text only when the record is public and
  in one of `item_sets`, and only its first non-empty **private** literal of
  `property` (`OmekaSourceRepository::loadRestrictedTexts()`). Private records,
  other private values, linked or URI values, a public value of the property and
  every record outside the listed sets stay out of the index.
- **Search.** The text is indexed as `restricted_text` and searched last in
  `query_by`, after every metadata field. Corpus searches, block scopes, the
  item-page **Project records** block, the federated tabs and the union stream
  all share the same matching, so a word found only in the text finds the record
  in each. Autocomplete stays on titles and never quotes the text.
- **Excerpts.** A hit whose text matched carries one excerpt,
  `_highlights.restricted_text[0]`: the sentence around the first match when it
  is at least 40 characters long, else whole words around it, whitespace
  collapsed, never more than 200 characters
  (`ResponseNormalizer::RESTRICTED_EXCERPT_MAX`), cut at the server. Typesense
  is asked only for a windowed snippet of this field, never its full value.
  The card shows it under **Matched in restricted full text** with the note that
  the complete text is not publicly accessible; the result opens the public item
  page, which does not show the text. A browse (no query) has no highlights, so
  no excerpt.
- **Payloads.** `restricted_text` is dropped from every returned document
  (search, union, export, map) whatever the profile says, so an index built
  under a policy since removed still never returns it; the response normaliser
  and the export drop it again.

The schema declares `restricted_text` whenever the profile has the block, even
with no item sets, so a collection built by 1.34.0 or later can be enabled
without a schema change. **Reindex the corpus** after changing `item_sets`:

- Naming a set reads its texts only at the next rebuild (or as each record is
  saved). Before that, a live collection built before 1.34.0 lacks the field,
  and the corpus's searches fail until the rebuild is promoted.
- Emptying the list stops matching and excerpts at once, since the field leaves
  `query_by`; the rebuild then removes the stored texts.
- Removing one set while others stay listed takes effect only at the rebuild:
  until then the records of the removed set can still be found, and quoted, by
  their stored text. To withdraw a collection at once, empty the list first,
  rebuild, then name the remaining sets again.

Editing, removing or making public a text, or moving a record out of a listed
set, is an Omeka write: it queues the record like any other change, the
readiness gate hides it until the worker has reapplied it, and the shared
first-page cache moves on.

**What a snippet does not protect.** Each excerpt is short, but a determined
visitor who already knows part of a text can request one phrase after another
and stitch excerpts together; the search rate limit (`rate_limits.search`)
slows this down without preventing it. Name a collection only when its rights
allow a public search to quote short passages of it, and keep texts whose words
alone must stay confidential out of every listed set.

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
