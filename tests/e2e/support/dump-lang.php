<?php

/**
 * Prints every translation of lang/ar and lang/en as flat "dot" keys, in JSON:
 *
 *   {"ar": {"resources.projects.label": "..."}, "en": {...}}
 *
 * The specs locate buttons and labels by these strings, so they keep working
 * whichever locale the app runs in and whatever wording a later change gives
 * a key. Plain PHP on purpose: the lang files are just `return [...]`, no
 * framework boot is needed.
 */

$root = dirname(__DIR__, 3);

function flatten(array $items, string $prefix = ''): array
{
    $flat = [];

    foreach ($items as $key => $value) {
        $name = $prefix.$key;

        if (is_array($value)) {
            $flat += flatten($value, $name.'.');
        } elseif (is_string($value) || is_numeric($value)) {
            $flat[$name] = (string) $value;
        }
    }

    return $flat;
}

$out = [];

foreach (['ar', 'en'] as $locale) {
    $flat = [];

    foreach (glob($root.'/lang/'.$locale.'/*.php') ?: [] as $file) {
        try {
            $data = require $file;
        } catch (Throwable $e) {
            fwrite(STDERR, "skipped {$file}: {$e->getMessage()}\n");

            continue;
        }

        if (is_array($data)) {
            $flat += flatten($data, basename($file, '.php').'.');
        }
    }

    $json = $root.'/lang/'.$locale.'.json';
    if (is_file($json)) {
        $flat += (array) json_decode((string) file_get_contents($json), true);
    }

    $out[$locale] = $flat;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
