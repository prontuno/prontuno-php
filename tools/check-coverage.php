<?php

declare(strict_types=1);

// Usage: php tools/check-coverage.php <clover.xml> <minimum-line-percentage>

[, $file, $minimum] = $argv + [null, null, '100'];

if (! is_string($file) || ! is_file($file)) {
    fwrite(STDERR, "Coverage report [{$file}] not found. Run with pcov or Xdebug enabled.\n");
    exit(1);
}

$metrics = simplexml_load_file($file)?->project->metrics;
$statements = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
$percentage = $statements === 0 ? 0.0 : $covered / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d), required %s%%\n", $percentage, $covered, $statements, $minimum);

exit($percentage + 1e-9 >= (float) $minimum ? 0 : 1);
