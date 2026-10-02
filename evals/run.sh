#!/usr/bin/env bash
# Fix each fixture target's queries with and without the skill, then score both with hidden tests
# Claude never sees: query counts before and after, exact output unchanged, no cache hiding the queries,
# schema changes proposed vs applied, files changed, cost.
#
# Usage: evals/run.sh [all|laravel|symfony|<part of a target path>]   e.g. evals/run.sh OrderController
# Env:   MODEL       model for claude -p (default: your claude default)
#        BUDGET_USD  spend cap per claude run (default 5)
#        WORK        scratch directory for the scaffolded apps (default evals/.work)
#        JUDGE       0 skips the blind side-by-side review at the end (evals/judge.sh)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
work=${WORK:-$root/evals/.work}
budget=${BUDGET_USD:-5}
which=${1:-all}
stamp=$(date +%Y%m%d-%H%M%S)
results=$work/results/$stamp
mkdir -p "$results"
csv=$results/results.csv
echo "framework,target,variant,queries,behaviour,caching,schema_proposed,schema_applied,files_changed,test_files,cost_usd,turns,minutes" > "$csv"
echo "claude: $(command -v claude)"

# framework|target|hidden test class (evals/hidden/<framework>/tests/Eval/)
targets=(
    "laravel|app/Http/Controllers/OrderController.php|OrderIndexEvalTest"
    "laravel|app/Http/Controllers/CustomerController.php|CustomerIndexEvalTest"
    "laravel|app/Console/Commands/ArchiveStaleOrders.php|ArchiveStaleOrdersEvalTest"
    "symfony|src/Controller/AuthorController.php|AuthorIndexEvalTest"
)

# The app's own (dev) database, where an applied schema change would land. Tests use their own.
devdb() { case $1 in laravel) echo database/database.sqlite ;; symfony) echo var/data_dev.db ;; esac; }

scaffold() {
    local fw=$1 dir=$work/$1
    if [ ! -d "$dir/.git" ]; then
        rm -rf "$dir"
        case $fw in
            laravel)
                composer create-project -n --quiet laravel/laravel "$dir"
                echo "require __DIR__.'/shop.php';" >> "$dir/routes/web.php" ;;
            symfony)
                composer create-project -n --quiet symfony/skeleton "$dir"
                (cd "$dir" && composer require -n --quiet symfony/orm-pack symfony/twig-bundle \
                    && composer require -n --quiet --dev symfony/test-pack symfony/web-profiler-bundle \
                    && sed -i 's#^DATABASE_URL=.*#DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"#' .env) ;;
        esac
        (cd "$dir" && git init -q)
    fi

    # Copy fixtures on every run so edits reach an existing scaffold.
    # Package changes still need a fresh scaffold: delete $work/<framework>.
    reset_app "$fw"
    cp -r "$root/evals/fixtures/$fw/." "$dir/"
    case $fw in
        laravel) (cd "$dir" && php artisan migrate --force -q) ;;
        symfony) (cd "$dir" && php bin/console doctrine:schema:update --force -q) ;;
    esac
    (cd "$dir" && git add -A 2>/dev/null \
        && { git diff --cached --quiet || git -c user.name=eval -c user.email=eval@localhost commit -qm baseline; })
    # Restored before every run, so a migration one run applied doesn't leak into the next.
    cp "$dir/$(devdb "$fw")" "$work/$fw.pristine.db"
}

reset_app() {
    local dir=$work/$1
    (cd "$dir" && if git rev-parse -q --verify HEAD > /dev/null; then git reset -q --hard && git clean -qfd; fi)
    rm -rf "$dir/.claude/skills" "$dir/eval-probe.txt"
    [ ! -f "$work/$1.pristine.db" ] || cp "$work/$1.pristine.db" "$dir/$(devdb "$1")"
}

run_one() {
    local fw=$1 target=$2 class=$3 variant=$4 dir=$work/$1 name prompt
    name=$fw-$(basename "$target" .php)-$variant
    # Paths below are relative to $dir so they also work with a native Windows php.
    local out=../results/$stamp/$name db before

    reset_app "$fw"
    db=$(devdb "$fw")
    before=$(cd "$dir" && php "$root/evals/score.php" --schema "$db")

    echo "== $name"
    if [ "$variant" != baseline ]; then
        if [ "$variant" = with ]; then
            mkdir -p "$dir/.claude/skills"
            cp -r "$root"/skills/* "$dir/.claude/skills/"
            prompt="/optimise-queries $target"
        else
            prompt="$target is slow. Fix its database queries without changing behaviour."
        fi
        # MSYS_NO_PATHCONV stops Git Bash on Windows rewriting "/optimise-queries" into a file path.
        (cd "$dir" && MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' claude -p "$prompt" ${MODEL:+--model "$MODEL"} \
            --max-budget-usd "$budget" --no-session-persistence --permission-mode acceptEdits --output-format json \
            --allowedTools "Read,Write,Edit,Glob,Grep,Bash(php:*),Bash(vendor/bin/phpunit:*),Bash(vendor/bin/pest:*),Bash(bin/phpunit:*),Bash(bin/console:*),Bash(composer dump-autoload:*),Bash(git diff:*),Bash(git status:*)" \
            > "$out.claude.json" 2> "$out.claude.err") || echo "   claude exited non-zero, see $results/$name.claude.err"
        # Keep Claude's final message (its report) readable next to the raw JSON.
        (cd "$dir" && php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); file_put_contents($argv[2], $j["result"] ?? "");' \
            "$out.claude.json" "$out.claude.txt")
    fi
    (cd "$dir" && git add -A 2>/dev/null && git diff --cached -- . ':!.claude' > "$out.diff")

    # Only now do the hidden tests reach the app. PAO_DISABLE=1 turns off laravel/pao (Laravel 13
    # skeletons), which switches PHPUnit to JSON output when it detects an AI agent; JUnit XML is read instead.
    local after
    after=$(cd "$dir" && php "$root/evals/score.php" --schema "$db")
    cp -r "$root/evals/hidden/$fw/." "$dir/"
    (cd "$dir" && rm -f eval-probe.txt && PAO_DISABLE=1 php -d auto_prepend_file= vendor/bin/phpunit "tests/Eval/$class.php" \
        --log-junit "$out.junit.xml" > "$out.phpunit.txt" 2>&1) || true
    (cd "$dir" && { [ ! -f eval-probe.txt ] || mv eval-probe.txt "$out.probe.txt"; })

    (cd "$dir" && printf '%s,%s\n' "$fw,$target,$variant" "$(php "$root/evals/score.php" \
        "$out.junit.xml" "$out.probe.txt" "$out.diff" "$out.claude.json" "$before" "$after")") >> "$csv"
}

for entry in "${targets[@]}"; do
    IFS='|' read -r fw target class <<< "$entry"
    [ "$which" = all ] || [ "$which" = "$fw" ] || [[ "$target" == *"$which"* ]] || continue
    [ -d "$work/$fw/.git" ] && [ "${scaffolded:-}" = "$fw" ] || { scaffold "$fw"; scaffolded=$fw; }
    for variant in baseline without with; do
        run_one "$fw" "$target" "$class" "$variant"
    done
done

echo
column -s, -t < "$csv" 2>/dev/null || cat "$csv"
echo
echo "Logs, diffs and results.csv: $results"

# Blind side-by-side review of each target's two fixes (one extra claude -p call per target).
[ "${JUDGE:-1}" = 0 ] || bash "$root/evals/judge.sh" "$results"
