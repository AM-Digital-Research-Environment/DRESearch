<?php
declare(strict_types=1);

use DRESearch\Indexer\SchemaProvider;
use DRESearch\Search\QueryBuilder;
use DRESearch\Settings\SearchProfile;

/** Standalone CI guard: profile config, Typesense schema and client contracts. */
$root = dirname(__DIR__);
foreach (['DateDefinition', 'FieldDefinition', 'SortDefinition', 'SourceScope'] as $class) {
    require_once $root . '/src/Settings/Definition/' . $class . '.php';
}
require_once $root . '/src/Settings/SearchProfile.php';
// Profile validation reads the valid kinds from the mapper registry.
require_once $root . '/src/Indexer/MapperRegistry.php';
require_once $root . '/src/Indexer/SchemaProvider.php';
require_once $root . '/src/Indexer/StopwordsSync.php';
require_once $root . '/src/Indexer/SynonymsSync.php';
require_once $root . '/src/Search/FilterExpression.php';
require_once $root . '/src/Search/QueryBuilder.php';

$config = require $root . '/config/module.config.php';
$profiles = $config['dre_search']['profiles'] ?? [];
$clientTypes = (string) file_get_contents($root . '/src/svelte/lib/types.ts');
$i18n = (string) file_get_contents($root . '/src/svelte/lib/i18n.ts');
$errors = [];

foreach ($profiles as $name => $definition) {
    try {
        $profile = SearchProfile::fromArray((string) $name, $definition);
        $schema = (new SchemaProvider())->collection('schema_guard', $profile);
        $fields = [];
        foreach ($schema['fields'] as $field) {
            $fields[$field['name']] = $field;
        }
        foreach (array_filter(array_map('trim', explode(',', $profile->queryBy()))) as $field) {
            if (!isset($fields[$field]) || (($fields[$field]['index'] ?? true) === false)) {
                $errors[] = "$name: query_by field $field is missing or unindexed";
            }
            if (!in_array($field, ['title', 'abstract', 'description'], true)
                && !preg_match('/^\s*' . preg_quote($field, '/') . '\s*:/m', $i18n)
            ) {
                $errors[] = "$name: searchable field $field has no client field-label mapping";
            }
        }
        foreach ($profile->fieldNames() as $field) {
            if (!isset($fields[$field]) || empty($fields[$field]['facet'])) {
                $errors[] = "$name: facet $field is missing or not facetable";
            }
        }
        foreach ($profile->displayFields() as $field => $fieldDefinition) {
            if (!empty($fieldDefinition['facet']) && (!isset($fields[$field]) || empty($fields[$field]['facet']))) {
                $errors[] = "$name: display facet $field is missing or not facetable";
            }
        }
        // Typesense derives the geo index from the sort index, so it refuses to
        // create a collection whose geopoint field carries `sort: false`. The
        // failure only surfaces at reindex time, well after CI.
        foreach ($fields as $field => $schemaField) {
            if (in_array($schemaField['type'], ['geopoint', 'geopoint[]'], true)
                && empty($schemaField['sort'])
            ) {
                $errors[] = "$name: geopoint field $field must be sortable";
            }
        }
        $query = (new QueryBuilder($profile))->search(['q' => '', 'facets' => []]);
        $excluded = array_filter(explode(',', (string) ($query['exclude_fields'] ?? '')));
        foreach ($profile->searchOnlyFields() as $field) {
            if (!isset($fields[$field]) || (($fields[$field]['index'] ?? true) === false)) {
                $errors[] = "$name: search_only field $field is not indexed";
            }
            if (!in_array($field, $excluded, true)) {
                $errors[] = "$name: search_only field $field is not excluded from payloads";
            }
        }
    } catch (Throwable $exception) {
        $errors[] = "$name: {$exception->getMessage()}";
    }
}

// One list of kinds: the mapper registry is the source (a profile's kind is
// validated against it), and the client's CARD_KINDS in src/svelte/lib/types.ts
// must name exactly the same ones. A kind only the server knows renders the
// generic item card; one only the client knows hides a typo.
$serverKinds = \DRESearch\Indexer\MapperRegistry::default()->kinds();
$clientKinds = [];
if (preg_match('/export const CARD_KINDS = \[(.*?)\] as const;/s', $clientTypes, $match)) {
    preg_match_all("/'([a-z_]+)'/", $match[1], $kinds);
    $clientKinds = $kinds[1];
} else {
    $errors[] = 'src/svelte/lib/types.ts: CARD_KINDS is missing';
}
foreach (array_diff($serverKinds, $clientKinds) as $kind) {
    $errors[] = "kind $kind is registered in MapperRegistry but missing from the client's CARD_KINDS";
}
foreach (array_diff($clientKinds, $serverKinds) as $kind) {
    $errors[] = "kind $kind is in the client's CARD_KINDS but no mapper is registered for it";
}

// Constants the client copies from the server, which only a comment tied
// together. A page past the server's MAX_PAGE is refused (400), not clamped;
// a different export cap misstates the limit hint; other highlight markers
// print raw private-use characters and mark nothing.
$clientConstants = [
    'MAX_PAGE' => ['src/svelte/lib/urlState.ts', QueryBuilder::MAX_PAGE],
    'EXPORT_MAX_HITS' => ['src/svelte/lib/export.ts', QueryBuilder::EXPORT_MAX_HITS],
];
foreach ($clientConstants as $constant => [$file, $expected]) {
    $source = (string) file_get_contents($root . '/' . $file);
    if (!preg_match('/export const ' . $constant . ' = ([0-9_]+);/', $source, $match)) {
        $errors[] = "$file: $constant is missing";
    } elseif ((int) str_replace('_', '', $match[1]) !== $expected) {
        $errors[] = "$file: $constant is {$match[1]}, the server's QueryBuilder::$constant is $expected";
    }
}
$highlight = (string) file_get_contents($root . '/src/svelte/lib/highlight.ts');
foreach (['HL_START' => QueryBuilder::HL_START, 'HL_END' => QueryBuilder::HL_END] as $constant => $marker) {
    // json_encode escapes the private-use character as \uXXXX (no mbstring needed).
    $codePoint = '0x' . strtolower(substr((string) json_encode($marker), 3, 4));
    if (!preg_match('/export const ' . $constant . ' = String\.fromCharCode\((0x[0-9a-f]+)\);/i', $highlight, $match)
        || strtolower($match[1]) !== $codePoint) {
        $errors[] = "src/svelte/lib/highlight.ts: $constant must be String.fromCharCode($codePoint), as QueryBuilder::$constant";
    }
}
if (!str_contains($highlight, "export const RESTRICTED_TEXT_FIELD = '" . SearchProfile::RESTRICTED_TEXT_FIELD . "';")) {
    $errors[] = 'src/svelte/lib/highlight.ts: RESTRICTED_TEXT_FIELD must be \'' . SearchProfile::RESTRICTED_TEXT_FIELD . '\', as SearchProfile';
}

if ($errors !== []) {
    fwrite(STDERR, "Profile/schema drift detected:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf("Profile/schema guard passed for %d profiles.\n", count($profiles)));
