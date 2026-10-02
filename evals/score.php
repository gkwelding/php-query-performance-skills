<?php

// Scores one run for evals/run.sh.
//
// php evals/score.php --schema <sqlite file>
//     Prints a fingerprint of the database's schema and migration log ("none" if the file is missing).
// php evals/score.php <junit.xml> <probe.txt> <run.diff> <claude.json> <schema before> <schema after>
//     Prints one CSV fragment: queries,behaviour,caching,schema_proposed,schema_applied,files_changed,test_files,cost_usd,turns,minutes

if (($argv[1] ?? '') === '--schema') {
    if (!is_file($argv[2])) {
        exit("none\n");
    }
    $db = new PDO('sqlite:'.$argv[2]);
    $tables = $db->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $state = $db->query('select type, name, sql from sqlite_master order by name')->fetchAll(PDO::FETCH_NUM);
    foreach (array_intersect(['migrations', 'doctrine_migration_versions'], $tables) as $log) {
        $state[] = $db->query("select * from {$log}")->fetchAll(PDO::FETCH_NUM);
    }
    exit(md5(json_encode($state))."\n");
}

[, $junit, $probe, $diff, $claude, $schemaBefore, $schemaAfter] = $argv;

// Query counts from the hidden probe, smallest data size first: "13/41".
$counts = [];
foreach (is_file($probe) ? file($probe, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    [$size, $n] = explode(' ', $line);
    $counts[(int) $size] = $n;
}
ksort($counts);
$queries = implode('/', $counts);

// Hidden behaviour tests passed / run, from the JUnit log (probe tests excluded).
$behaviour = '';
if (is_file($junit) && ($xml = @simplexml_load_file($junit))) {
    $run = $passed = 0;
    foreach ($xml->xpath('//testcase') as $case) {
        if (stripos((string) $case['name'], 'probe') !== false) {
            continue;
        }
        $run++;
        $passed += $case->failure || $case->error || $case->skipped ? 0 : 1;
    }
    $behaviour = "{$passed}/{$run}";
}

// From the diff of everything Claude changed (.claude/ excluded by run.sh).
$files = $tests = $migrations = $caching = 0;
$path = null;
foreach (preg_split('/\R/', (string) @file_get_contents($diff)) as $line) {
    if (preg_match('#^diff --git a/(\S+) b/#', $line, $m)) {
        $path = $m[1];
        str_starts_with($path, 'tests/') ? $tests++ : $files++;
        continue;
    }
    if ($path !== null && str_starts_with($line, 'new file mode') && preg_match('#^(database/)?migrations/#', $path)) {
        $migrations++;
    }
    // A cache in front of the queries hides them instead of fixing them.
    if ($path !== null && !str_starts_with($path, 'tests/') && str_starts_with($line, '+') && !str_starts_with($line, '+++')
        && preg_match('/Cache::|\bcache\(|->remember(Forever)?\(|CacheInterface|ItemInterface|enableResultCache|#\[ORM\\\\Cache/', $line)) {
        $caching++;
    }
}

$applied = $schemaBefore === $schemaAfter ? 0 : 1;

$result = json_decode((string) @file_get_contents($claude), true) ?? [];
$cost = isset($result['total_cost_usd']) ? round($result['total_cost_usd'], 2) : '';
$minutes = isset($result['duration_ms']) ? round($result['duration_ms'] / 60000, 1) : '';

echo implode(',', [$queries, $behaviour, $caching, $migrations, $applied, $files, $tests, $cost, $result['num_turns'] ?? '', $minutes]), "\n";
