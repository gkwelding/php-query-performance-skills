#!/usr/bin/env bash
# Blind side-by-side review of the two fixes (with / without the skill) for each target in a results folder.
# The diffs are shown as A and B in random order; verdicts are mapped back to with/without.
#
# Usage: evals/judge.sh <results folder>     (run.sh calls this at the end unless JUDGE=0)
# Env:   MODEL             model for claude -p (default: your claude default)
#        JUDGE_BUDGET_USD  spend cap per comparison (default 1)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$1" # relative paths from here also work with a native Windows php
budget=${JUDGE_BUDGET_USD:-1}

criteria=(behaviour root_cause minimal clarity overall)
props=""
for c in "${criteria[@]}"; do
    props+="\"$c\":{\"type\":\"object\",\"properties\":{\"winner\":{\"type\":\"string\",\"enum\":[\"A\",\"B\",\"tie\"]},\"reason\":{\"type\":\"string\"}},\"required\":[\"winner\",\"reason\"]},"
done
required=$(printf '"%s",' "${criteria[@]}")
schema="{\"type\":\"object\",\"properties\":{${props%,}},\"required\":[${required%,}]}"

# a_was says which variant was shown as A; the reasons refer to the fixes as A and B.
echo "framework,target,criterion,winner,a_was,reason" > judge.csv

tail -n +2 results.csv | cut -d, -f1,2 | sort -u | while IFS=, read -r fw target; do
    name=$fw-$(basename "$target" .php)
    [ -f "$name-with.diff" ] && [ -f "$name-without.diff" ] || continue

    # Random order, so position can't favour a variant.
    if (( RANDOM % 2 )); then a=with b=without; else a=without b=with; fi

    {
        cat <<EOF
Two developers, A and B, were each asked to fix the database query performance of $target in the same
app without changing its behaviour. Compare their diffs.

For each criterion, pick A, B or tie, and give one sentence that cites something specific in the diffs:
- behaviour: more likely to keep the exact same output, ordering, rows written and side effects.
- root_cause: removes the repeated queries at their source (eager loading, counts in SQL, safe batching)
  rather than hiding them (caching) or moving them elsewhere.
- minimal: the smallest change that does the job, with no unrelated edits.
- clarity: easier for the next developer to read and trust, including any test left behind.
- overall: the change you would rather merge.

Judge quality, not size: more code or more tests is not better by itself.
EOF
        echo
        echo "=== The app before either change ==="
        (cd "$root/evals/fixtures/$fw" && find . -type f | sort | while read -r f; do echo "// ${f#./}"; cat "$f"; echo; done)
        echo "=== Diff A ==="
        cat "$name-$a.diff"
        echo "=== Diff B ==="
        cat "$name-$b.diff"
    } > "$name.judge-prompt.txt"

    echo "== judging $name (A = $a)"
    claude -p --tools "" --output-format json --json-schema "$schema" --max-budget-usd "$budget" \
        --no-session-persistence ${MODEL:+--model "$MODEL"} \
        < "$name.judge-prompt.txt" > "$name.judge.json" 2> "$name.judge.err" || echo "   judge failed, see $name.judge.err"

    php -r '
        [, $json, $a, $b, $fw, $target] = $argv;
        $verdict = json_decode((string) @file_get_contents($json), true)["structured_output"] ?? [];
        $out = fopen("judge.csv", "a");
        foreach ($verdict as $criterion => $v) {
            $winner = ["A" => $a, "B" => $b][$v["winner"]] ?? "tie";
            fputcsv($out, [$fw, $target, $criterion, $winner, $a, $v["reason"]], escape: "");
        }
    ' "$name.judge.json" "$a" "$b" "$fw" "$target"
done

php -r '
    $rows = array_map(fn ($l) => str_getcsv($l, escape: ""), array_slice(file("judge.csv", FILE_IGNORE_NEW_LINES), 1));
    $tally = [];
    foreach ($rows as [, , $criterion, $winner]) {
        $tally[$criterion][$winner] = ($tally[$criterion][$winner] ?? 0) + 1;
    }
    printf("\n%-14s %5s %8s %4s\n", "criterion", "with", "without", "tie");
    foreach ($tally as $criterion => $t) {
        printf("%-14s %5d %8d %4d\n", $criterion, $t["with"] ?? 0, $t["without"] ?? 0, $t["tie"] ?? 0);
    }
'
echo
echo "Verdicts with reasons: $1/judge.csv"
