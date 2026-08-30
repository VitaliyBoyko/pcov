<?php

declare(strict_types=1);

[$script, $directory, $fileCount, $statementCount] = $argv + [null, null, null, null];
if ($directory === null || !ctype_digit((string) $fileCount) || !ctype_digit((string) $statementCount)) {
    fwrite(STDERR, "Usage: php generate.php DIRECTORY FILES STATEMENTS\n");
    exit(64);
}

if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
    throw new RuntimeException("Cannot create fixture directory: {$directory}");
}

for ($file = 0; $file < (int) $fileCount; $file++) {
    $name = sprintf('pcov_large_%05d', $file);
    $source = "<?php\nfunction {$name}(int \$seed): int\n{\n    \$value = \$seed;\n";
    for ($line = 1; $line <= (int) $statementCount; $line++) {
        $conditions = array_fill(0, 80, '$value');
        $source .= "    \$value += ((" . implode(' && ', $conditions) . ") ? {$line} : -{$line});\n";
    }
    $source .= "    return \$value;\n}\n";
    file_put_contents("{$directory}/{$name}.php", $source);
}
