# Upgrading to 1.17

1. Back up the Omeka database and retain the current module directory.
2. Replace the module, run `composer install --no-dev`, and upgrade it from
   Admin → Modules. The upgrade creates operational tables; it does not modify
   Omeka resources or delete Typesense collections.
3. Rebuild every corpus. Existing aliases remain live until each new generation
   passes import and count verification.
4. Confirm Admin → DRE Search reports `live`, matching totals, and no dirty flag.
5. Purge reverse-proxy/browser caches for compiled frontend assets.

Existing block settings remain compatible. Missing facet settings retain the
legacy "show all" behavior; explicitly empty facets remain empty. Stored API
keys are no longer echoed into the form. Raw browser `locked_filter` requests
are rejected; shipped clients send the block ID instead.
