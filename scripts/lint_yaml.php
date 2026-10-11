<?php
/**
 * Lint every yaml file in the plugin.
 *
 * Three checks, because one is not enough:
 *  1. Parse each file with Winter's parser.
 *  2. Flag values that contain ": " while unquoted, in a mapping value OR in a sequence item.
 *     Storm's parser is lenient about this, but the backend's Settings/Form widgets are not - an
 *     unquoted comment containing a colon rendered the whole settings page as a syntax-error
 *     notice even though the parser accepted it.
 *  3. Assert updates/version.yaml has the shape Winter's updater can walk: every entry's details
 *     must be strings. An unquoted colon in a sequence item makes YAML build a nested mapping,
 *     and then VersionManager::extractScriptsAndComments() calls preg_match() on an array - the
 *     migration run then dies with a type error rather than anything that names the file.
 *
 * Run from the site root:  php plugins/niainteractive/pencatalog/scripts/lint_yaml.php
 * Exit: 0 clean, 1 problems found, 2 cannot bootstrap.
 */
require __DIR__ . '/../../../../bootstrap/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Winter\Storm\Parse\Yaml;

$root = realpath(__DIR__ . '/..');
if (!$root || !class_exists(Yaml::class)) {
    fwrite(STDERR, "lint_yaml: cannot bootstrap\n");
    exit(2);
}

$parser = new Yaml();
$checked = 0;
$problems = 0;

$files = [];
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($dir as $file) {
    $path = $file->getPathname();
    if (!preg_match('/\.ya?ml$/', $path)) { continue; }
    if (strpos($path, '/.git/') !== false || strpos($path, '/node_modules/') !== false) { continue; }
    $files[] = $path;
}
sort($files);

foreach ($files as $path) {
    $checked++;
    $rel = str_replace($root . '/', '', $path);

    // 1. does it parse at all
    try {
        $parsed = $parser->parseFile($path);
        if ($parsed !== null && !is_array($parsed)) {
            throw new RuntimeException('did not parse to an array');
        }
    } catch (\Throwable $e) {
        $problems++;
        echo "PARSE  " . $rel . "\n       " . trim(preg_replace('/\s+/', ' ', $e->getMessage())) . "\n";
    }

    // 2. unquoted values containing ": ", whether a mapping value or a sequence item
    foreach (file($path) as $i => $line) {
        $line = rtrim($line, "\r\n");

        if (preg_match('/^\s*[A-Za-z0-9_.-]+:\s+(.*)$/', $line, $m)) {
            $value = trim($m[1]);            // key: value
        } elseif (preg_match('/^\s*-\s+(.*)$/', $line, $m)) {
            $value = trim($m[1]);            // - value    (a sequence item)
        } else {
            continue;
        }

        if ($value === '' || preg_match('/^[\'\">|\[{]/', $value)) {
            continue;   // quoted, block scalar or collection - fine
        }

        if (strpos($value, ': ') !== false) {
            $problems++;
            echo "COLON  " . $rel . ':' . ($i + 1) . "\n";
            echo "       " . substr($value, 0, 110) . "\n";
            echo "       -> wrap the value in single quotes\n";
        }
    }
}

// 3. version.yaml must stay a shape the updater can walk
$versionFile = $root . '/updates/version.yaml';
if (is_file($versionFile)) {
    try {
        $versions = $parser->parseFile($versionFile);
        if (is_array($versions)) {
            foreach ($versions as $version => $details) {
                $items = is_array($details) ? $details : [$details];
                foreach ($items as $index => $item) {
                    if (!is_string($item)) {
                        $problems++;
                        echo "SHAPE  updates/version.yaml\n";
                        echo "       version $version, item " . ($index + 1) . " is " . gettype($item)
                            . ", not a string (an unquoted colon builds a mapping)\n";
                        echo "       -> quote the line\n";
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $problems++;
        echo "SHAPE  updates/version.yaml did not parse: " . trim($e->getMessage()) . "\n";
    }
}

if ($problems === 0) {
    echo "lint_yaml: OK - $checked yaml files parsed, no unquoted colons, version.yaml shape is sound.\n";
} else {
    echo "lint_yaml: $problems problem(s) across $checked yaml files.\n";
}

exit($problems === 0 ? 0 : 1);
