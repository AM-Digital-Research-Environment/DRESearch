<?php

declare(strict_types=1);

/**
 * Generates the module's translation template and the client string table.
 *
 *   php scripts/build-translations.php          write language/template.pot and
 *                                               data/client-strings.json
 *   php scripts/build-translations.php --check  fail if either is out of date
 *                                               (run by `npm run lint`)
 *
 * Sources:
 *   - PHP and view scripts: literal arguments of translate()/$t()/$translate(),
 *     and string literals followed by a `// @translate` marker;
 *   - config/module.config.php: every corpus label, facet/display/date label
 *     and placeholder, which the module translates dynamically;
 *   - src/svelte/lib/i18n.ts: the client's English UI strings. The server
 *     translates them per page (View\ClientStrings) so the Svelte client speaks
 *     the site's language; data/client-strings.json is the key → English table
 *     it reads at runtime.
 *
 * Translators copy language/template.pot to language/<locale>.po and compile
 * it to <locale>.mo (e.g. with msgfmt or Poedit).
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

/** @var array<string,list<string>> msgid => source references */
$messages = [];
$add = static function (string $msgid, string $ref) use (&$messages): void {
    $msgid = trim($msgid);
    if ($msgid === '' || preg_match('/^[a-z0-9_.:\/-]+$/', $msgid)) {
        return; // empty, or an identifier rather than prose
    }
    $messages[$msgid][] = $ref;
};

$unquote = static function (string $quoted): string {
    $quote = $quoted[0];
    $body = substr($quoted, 1, -1);
    return $quote === "'"
        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
        : stripcslashes($body);
};
$literal = "('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\$]|\\\\.)*\")";

// 1. PHP / view scripts.
$files = array_merge(
    [$root . '/Module.php'],
    glob($root . '/view/*/*/*/*.phtml') ?: [],
    glob($root . '/view/*/*/*.phtml') ?: [],
    glob($root . '/view/*/*.phtml') ?: [],
);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);
foreach (array_unique($files) as $path) {
    $source = (string) file_get_contents($path);
    $rel = ltrim(str_replace([$root, '\\'], ['', '/'], $path), '/');
    $patterns = [
        '/(?:->translate|\$t|\$translate)\(\s*' . $literal . '/',
        '/' . $literal . '\s*\)?\s*[,;)]*\s*\/\/\s*@translate/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$quoted, $offset]) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $add($unquote($quoted), $rel . ':' . $line);
            }
        }
    }
}

// 2. Configured corpora: labels the module passes to translate() at runtime.
$config = require $root . '/config/module.config.php';
foreach ($config['dre_search']['profiles'] ?? [] as $name => $profile) {
    $ref = 'config/module.config.php (' . $name . ')';
    foreach (['label', 'placeholder'] as $key) {
        if (is_string($profile[$key] ?? null)) {
            $add($profile[$key], $ref);
        }
    }
    foreach (['facets', 'display_fields'] as $group) {
        foreach ($profile[$group] ?? [] as $field) {
            if (is_array($field) && is_string($field['label'] ?? null)) {
                $add($field['label'], $ref);
            }
        }
    }
    if (is_string($profile['date']['label'] ?? null)) {
        $add($profile['date']['label'], $ref);
    }
    foreach ($profile['sort_fields'] ?? [] as $sort) {
        if (is_array($sort) && is_string($sort['label'] ?? null)) {
            $add($sort['label'], $ref);
        }
    }
}

// 3. Client strings.
$i18n = (string) file_get_contents($root . '/src/svelte/lib/i18n.ts');
if (!preg_match('/const ENGLISH_STRINGS: Record<string, string> = \{(.*?)\n\};/s', $i18n, $block)) {
    fwrite(STDERR, "Cannot find ENGLISH_STRINGS in src/svelte/lib/i18n.ts\n");
    exit(1);
}
$client = [];
foreach (preg_split('/\R/', $block[1]) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '//')) {
        continue;
    }
    if (!preg_match('/^([a-z0-9_]+): \'((?:[^\'\\\\]|\\\\.)*)\',$/', $line, $m)) {
        fwrite(STDERR, "Unparseable client string (keep one single-quoted value per line): {$line}\n");
        exit(1);
    }
    $client[$m[1]] = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[2]);
}
ksort($client);
foreach ($client as $key => $english) {
    $add($english, 'src/svelte/lib/i18n.ts (' . $key . ')');
}

// Output.
ksort($messages, SORT_STRING);
$escape = static fn(string $s): string => addcslashes($s, "\\\"\n\t");
$pot = "# Translation template for the DRE Search Omeka S module.\n"
    . "# Generated by scripts/build-translations.php — do not edit by hand.\n"
    . "msgid \"\"\nmsgstr \"\"\n"
    . "\"Content-Type: text/plain; charset=UTF-8\\n\"\n\n";
foreach ($messages as $msgid => $refs) {
    $refs = array_values(array_unique($refs));
    $pot .= '#: ' . implode(' ', array_slice($refs, 0, 4)) . "\n";
    if (str_contains((string) $msgid, '%')) {
        $pot .= "#, php-format\n";
    }
    $pot .= 'msgid "' . $escape((string) $msgid) . "\"\nmsgstr \"\"\n\n";
}
$json = json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$targets = [
    $root . '/language/template.pot' => $pot,
    $root . '/data/client-strings.json' => $json,
];
$stale = [];
foreach ($targets as $path => $content) {
    $current = is_readable($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : null;
    if ($current === $content) {
        continue;
    }
    if ($check) {
        $stale[] = ltrim(str_replace([$root, '\\'], ['', '/'], $path), '/');
        continue;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $content);
}
if ($stale !== []) {
    fwrite(STDERR, 'Translation files are out of date: ' . implode(', ', $stale) . "\nRun: php scripts/build-translations.php\n");
    exit(1);
}
printf("Translations: %d messages (%d client strings).\n", count($messages), count($client));
