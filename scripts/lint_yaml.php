<?php
/**
 * Parse every yaml file in the plugin with Winter's own parser.
 *
 * Why: a yaml syntax error does not break the build, it surfaces as a runtime error notice on
 * whichever backend page reads that file - a "colon cannot be used in an unquoted mapping value"
 * in a settings comment rendered the whole settings page as an error. Cheap to check, so check it.
 *
 * Run from the site root:  php plugins/niainteractive/pencatalog/scripts/lint_yaml.php
 * Exit: 0 clean, 1 problems found, 2 parser unavailable.
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
$bad = 0;

$dir = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($dir as $file) {
    $path = $file->getPathname();
    if (!preg_match('/\.ya?ml$/', $path)) {
        continue;
    }
    if (strpos($path, '/.git/') !== false || strpos($path, '/node_modules/') !== false) {
        continue;
    }

    $checked++;
    $rel = str_replace($root . '/', '', $path);

    try {
        $parsed = $parser->parseFile($path);
        if ($parsed !== null && !is_array($parsed)) {
            throw new RuntimeException('did not parse to an array');
        }
    } catch (\Throwable $e) {
        $bad++;
        echo "BAD  " . $rel . "\n";
        echo "     " . trim(preg_replace('/\s+/', ' ', $e->getMessage())) . "\n";
    }
}

if ($bad === 0) {
    echo "lint_yaml: OK - $checked yaml files parsed cleanly.\n";
} else {
    echo "lint_yaml: $bad of $checked yaml files failed.\n";
}

exit($bad === 0 ? 0 : 1);
