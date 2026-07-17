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

Adding a new mapper kind requires a mapper and result card. Adding a corpus of
an existing kind is configuration plus a thin block-layout binding.
