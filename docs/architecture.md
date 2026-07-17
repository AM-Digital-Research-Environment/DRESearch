# Architecture

DRE Search is an Omeka S module with three boundaries:

1. The indexer reads Omeka records from MySQL, maps them into profile-specific
   Typesense documents, and publishes only a verified complete generation.
2. The PHP proxy validates anonymous requests, injects visibility and saved
   block scope, calls Typesense with the server-held key, and normalizes output.
3. The compiled Svelte client consumes only the module API. It never talks to
   Typesense directly.

Each corpus is a typed profile. Its source scope, collection alias, facets,
fields, date model, and sorts form the contract shared by schema generation,
mapping, validation, and UI bootstrap.

## Rebuild lifecycle

`building → verifying → live` is the successful path. A MySQL advisory lock
serializes rebuilds per profile. Every run owns a unique staging collection and
records it in `dre_search_generation`. Any rejected document or count mismatch
prevents alias promotion and removes only that run's staging collection. The
previous generation is retained as rollback; older module-owned retired
generations are deleted only after the configured retention period.

## Incremental lifecycle

Item, batch, media, and item-set events call the bounded incremental indexer.
`syncOne` upserts a matching public resource and deletes a document that became
private or left scope. Linked resources are refreshed up to the configured cap.
Failures mark the profile dirty so operators know a rebuild is required.
