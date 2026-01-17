#!/bin/bash
set -e

# Fuel Preprocessor Benchmark
# Runs all task types with and without preprocessors, compares results

FUEL_ROOT="$(cd "$(dirname "$0")" && pwd)"
FUEL_BIN="$FUEL_ROOT/fuel"
BENCH_SRC="$FUEL_ROOT/bench"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Config
TOTAL_ITERATIONS=${1:-10}
AGENT="claude-sonnet"
BENCH_APP="nextjs-app"  # nextjs-app or app (PHP)

# Task definitions - multiple tasks to test different scenarios
declare -a TASKS=(
    "bug-fix|Fix password validation bug|The validatePassword function in src/lib/validation.ts is not checking for special characters. Add a check that requires at least one special character (!@#$%^&*). Update the tests in validation.test.ts to cover this case. Run: npm run test:run && npm run lint"
    "feature-add-1|Add post search endpoint|Create a new API route at src/app/api/search/route.ts that searches posts by title and content. It should accept a 'q' query param, return matching posts with pagination, and handle empty queries gracefully. Add tests. Run: npm run test:run && npm run lint"
    "feature-add-2|Add user profile update endpoint|Create a new API route at src/app/api/users/[id]/route.ts with a PATCH method that allows authenticated users to update their own profile (name, bio, avatar). Return 403 if trying to update another user. Follow the patterns in existing API routes. Add tests. Run: npm run test:run && npm run lint"
    "feature-add-3|Add post bookmarks endpoint|Create API routes at src/app/api/bookmarks/route.ts (GET list, POST add) and src/app/api/bookmarks/[postId]/route.ts (DELETE remove). Users can bookmark posts and retrieve their bookmarked posts. You'll need to add a Bookmark model to the Prisma schema. Add tests. Run: npm run test:run && npm run lint"
    "refactor|Refactor auth token handling|The auth token handling is duplicated across multiple API routes. Extract the authentication logic from src/app/api/auth/me/route.ts into a reusable middleware or helper in src/lib/auth.ts. Update all routes to use it. Run: npm run test:run && npm run lint"
)

NUM_TASKS=${#TASKS[@]}

# Output file - save in current working directory
RESULTS_DIR="$(pwd)/bench-results"
mkdir -p "$RESULTS_DIR"
RESULTS_FILE="$RESULTS_DIR/benchmark-$(date +%Y%m%d-%H%M%S).txt"

# Function to output to both stdout and file
log() {
    echo -e "$@" | tee -a "$RESULTS_FILE"
}

log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log "${CYAN}  Fuel Preprocessor Benchmark${NC}"
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log ""
log "  Agent:      $AGENT"
log "  Iterations: $TOTAL_ITERATIONS (across all task types)"
log "  Task types: $NUM_TASKS (bug-fix, feature-add, refactor)"
log "  Bench app:  $BENCH_APP"
log ""

# Check bench directory exists
if [ ! -d "$BENCH_SRC/$BENCH_APP" ]; then
    log "${RED}Error: bench/$BENCH_APP directory not found${NC}"
    exit 1
fi

# Create work directory
WORK_DIR=$(mktemp -d)
log "${YELLOW}Setup${NC}"
log "  Work directory: $WORK_DIR"
log "  Results file:   $RESULTS_FILE"
log ""

# Arrays to store overall results
declare -a CONTROL_DURATIONS
declare -a CONTROL_COSTS
declare -a TREATMENT_DURATIONS
declare -a TREATMENT_COSTS

# Arrays to store per-task-type results
declare -a BUGFIX_CTRL_DUR BUGFIX_CTRL_COST BUGFIX_TREAT_DUR BUGFIX_TREAT_COST
declare -a FEATURE_CTRL_DUR FEATURE_CTRL_COST FEATURE_TREAT_DUR FEATURE_TREAT_COST
declare -a REFACTOR_CTRL_DUR REFACTOR_CTRL_COST REFACTOR_TREAT_DUR REFACTOR_TREAT_COST

# Setup function - creates fresh instance
setup_instance() {
    local dir=$1

    rm -rf "$dir"
    mkdir -p "$dir"

    # Copy bench app (include node_modules, exclude build artifacts and db)
    rsync -a --exclude '.next' --exclude '*.db' --exclude '.fuel' "$BENCH_SRC/$BENCH_APP/" "$dir/"

    cd "$dir"

    # Create fresh database for Prisma
    if [ -f "prisma/schema.prisma" ]; then
        npx prisma db push --force-reset --skip-generate 2>/dev/null || true
    fi

    # Initialize fuel
    "$FUEL_BIN" init --quiet 2>/dev/null || "$FUEL_BIN" init 2>/dev/null || true
}

# Run task function - returns "duration|cost|exit_code"
run_task() {
    local dir=$1
    local use_preprocessors=$2

    cd "$dir"

    # Add task
    local task_json
    task_json=$("$FUEL_BIN" add "$TASK_TITLE" --description="$TASK_DESC" --json 2>/dev/null)
    local task_id
    task_id=$(echo "$task_json" | php -r "echo json_decode(file_get_contents('php://stdin'))->short_id ?? '';")

    if [ -z "$task_id" ]; then
        echo "error|0|0|1"
        return
    fi

    # Build run command
    local flags="--no-done --agent=$AGENT"
    if [ "$use_preprocessors" = "false" ]; then
        flags="$flags --no-preprocessors"
    fi

    # Run agent - suppress all output
    "$FUEL_BIN" run "$task_id" $flags > /dev/null 2>&1 || true

    # Get metrics directly from SQLite (fuel runs --json has issues in temp dirs)
    local db_path="$dir/.fuel/agent.db"
    local duration cost

    if [ -f "$db_path" ]; then
        # Query duration_seconds and cost_usd from latest run for this task
        local result
        result=$(sqlite3 "$db_path" "SELECT duration_seconds, cost_usd FROM runs r JOIN tasks t ON r.task_id = t.id WHERE t.short_id = '$task_id' ORDER BY r.id DESC LIMIT 1" 2>/dev/null) || result=""

        if [ -n "$result" ]; then
            duration=$(echo "$result" | cut -d'|' -f1)
            cost=$(echo "$result" | cut -d'|' -f2)
        else
            duration="0"
            cost="0"
        fi
    else
        duration="0"
        cost="0"
    fi

    # Ensure we have numeric values
    duration=${duration:-0}
    cost=${cost:-0}

    echo "$duration|$cost|0"
}

# Calculate mean from space-separated values
calc_mean() {
    local values="$1"
    local sum=0
    local count=0

    for val in $values; do
        # Skip non-numeric values
        if [[ "$val" =~ ^[0-9.]+$ ]]; then
            sum=$(echo "$sum + $val" | bc -l)
            count=$((count + 1))
        fi
    done

    if [ $count -eq 0 ]; then
        echo "0"
        return
    fi

    echo "scale=2; $sum / $count" | bc -l
}

# Calculate stddev from space-separated values
calc_stddev() {
    local values="$1"
    local mean=$2
    local sum_sq=0
    local count=0

    for val in $values; do
        if [[ "$val" =~ ^[0-9.]+$ ]]; then
            local diff=$(echo "$val - $mean" | bc -l)
            sum_sq=$(echo "$sum_sq + ($diff * $diff)" | bc -l)
            count=$((count + 1))
        fi
    done

    if [ $count -le 1 ]; then
        echo "0"
        return
    fi

    echo "scale=2; sqrt($sum_sq / ($count - 1))" | bc -l
}

log "${YELLOW}Running benchmarks${NC}"
log "  $TOTAL_ITERATIONS iterations × $NUM_TASKS task types × 2 (control/treatment) = $((TOTAL_ITERATIONS * NUM_TASKS * 2)) agent runs"
log ""
log "  ${RED}Note: This will spawn real agents and cost real money!${NC}"
log ""

for ITERATION in $(seq 1 $TOTAL_ITERATIONS); do
    log "  ${CYAN}══ Iteration $ITERATION/$TOTAL_ITERATIONS ══${NC}"

    for TASK_INDEX in $(seq 0 $((NUM_TASKS - 1))); do
        IFS='|' read -r TASK_TYPE TASK_TITLE TASK_DESC <<< "${TASKS[$TASK_INDEX]}"

        log "    ${YELLOW}$TASK_TYPE${NC}"

        CONTROL_DIR="$WORK_DIR/${TASK_TYPE}_control_$ITERATION"
        TREATMENT_DIR="$WORK_DIR/${TASK_TYPE}_treatment_$ITERATION"

        # Setup fresh instances
        echo -n "      Setting up instances... " | tee -a "$RESULTS_FILE"
        setup_instance "$CONTROL_DIR"
        setup_instance "$TREATMENT_DIR"
        log "done"

        # Run control and treatment in parallel
        log "      Running control & treatment in parallel..."

        # Start both in background, capture results to temp files
        CTRL_RESULT_FILE=$(mktemp)
        TREAT_RESULT_FILE=$(mktemp)

        run_task "$CONTROL_DIR" "false" > "$CTRL_RESULT_FILE" &
        CTRL_PID=$!

        run_task "$TREATMENT_DIR" "true" > "$TREAT_RESULT_FILE" &
        TREAT_PID=$!

        # Wait for both to complete
        wait $CTRL_PID
        wait $TREAT_PID

        # Read results
        CONTROL_RESULT=$(cat "$CTRL_RESULT_FILE")
        TREATMENT_RESULT=$(cat "$TREAT_RESULT_FILE")
        rm -f "$CTRL_RESULT_FILE" "$TREAT_RESULT_FILE"

        CTRL_DUR=$(echo "$CONTROL_RESULT" | cut -d'|' -f1)
        CTRL_COST=$(echo "$CONTROL_RESULT" | cut -d'|' -f2)
        TREAT_DUR=$(echo "$TREATMENT_RESULT" | cut -d'|' -f1)
        TREAT_COST=$(echo "$TREATMENT_RESULT" | cut -d'|' -f2)

        CONTROL_DURATIONS+=("$CTRL_DUR")
        CONTROL_COSTS+=("$CTRL_COST")
        TREATMENT_DURATIONS+=("$TREAT_DUR")
        TREATMENT_COSTS+=("$TREAT_COST")

        log "      Control: ${CTRL_DUR}s, \$${CTRL_COST}"
        log "      Treatment: ${TREAT_DUR}s, \$${TREAT_COST}"

        # Store per-task-type results (group all feature-add-* together)
        case "$TASK_TYPE" in
            bug-fix)
                BUGFIX_CTRL_DUR+=("$CTRL_DUR")
                BUGFIX_CTRL_COST+=("$CTRL_COST")
                BUGFIX_TREAT_DUR+=("$TREAT_DUR")
                BUGFIX_TREAT_COST+=("$TREAT_COST")
                ;;
            feature-add-*)
                FEATURE_CTRL_DUR+=("$CTRL_DUR")
                FEATURE_CTRL_COST+=("$CTRL_COST")
                FEATURE_TREAT_DUR+=("$TREAT_DUR")
                FEATURE_TREAT_COST+=("$TREAT_COST")
                ;;
            refactor)
                REFACTOR_CTRL_DUR+=("$CTRL_DUR")
                REFACTOR_CTRL_COST+=("$CTRL_COST")
                REFACTOR_TREAT_DUR+=("$TREAT_DUR")
                REFACTOR_TREAT_COST+=("$TREAT_COST")
                ;;
        esac

        log ""
    done
done

# Calculate statistics
CTRL_DUR_MEAN=$(calc_mean "${CONTROL_DURATIONS[*]}")
CTRL_DUR_STD=$(calc_stddev "${CONTROL_DURATIONS[*]}" "$CTRL_DUR_MEAN")
CTRL_COST_MEAN=$(calc_mean "${CONTROL_COSTS[*]}")
CTRL_COST_STD=$(calc_stddev "${CONTROL_COSTS[*]}" "$CTRL_COST_MEAN")

TREAT_DUR_MEAN=$(calc_mean "${TREATMENT_DURATIONS[*]}")
TREAT_DUR_STD=$(calc_stddev "${TREATMENT_DURATIONS[*]}" "$TREAT_DUR_MEAN")
TREAT_COST_MEAN=$(calc_mean "${TREATMENT_COSTS[*]}")
TREAT_COST_STD=$(calc_stddev "${TREATMENT_COSTS[*]}" "$TREAT_COST_MEAN")

# Results
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log "${CYAN}  Overall Results (n=$TOTAL_ITERATIONS)${NC}"
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log ""
printf "  %-20s %20s %20s\n" "" "Control" "Treatment" | tee -a "$RESULTS_FILE"
printf "  %-20s %20s %20s\n" "" "(no preproc)" "(with preproc)" | tee -a "$RESULTS_FILE"
log "  ─────────────────────────────────────────────────────────────"
printf "  %-20s %17.1fs ±%4.1f %17.1fs ±%4.1f\n" "Duration" "$CTRL_DUR_MEAN" "$CTRL_DUR_STD" "$TREAT_DUR_MEAN" "$TREAT_DUR_STD" | tee -a "$RESULTS_FILE"
printf "  %-20s %17s ±%4.2f %17s ±%4.2f\n" "Cost (USD)" "\$$CTRL_COST_MEAN" "$CTRL_COST_STD" "\$$TREAT_COST_MEAN" "$TREAT_COST_STD" | tee -a "$RESULTS_FILE"
log ""

# Calculate differences
if command -v bc &> /dev/null && [ "$CTRL_DUR_MEAN" != "0" ]; then
    TIME_DIFF=$(echo "scale=1; (($CTRL_DUR_MEAN - $TREAT_DUR_MEAN) / $CTRL_DUR_MEAN) * 100" | bc 2>/dev/null || echo "?")
    if (( $(echo "$TIME_DIFF > 0" | bc -l) )); then
        log "  Time: ${GREEN}${TIME_DIFF}% faster${NC} with preprocessors"
    elif (( $(echo "$TIME_DIFF < 0" | bc -l) )); then
        TIME_DIFF_ABS=$(echo "$TIME_DIFF * -1" | bc)
        log "  Time: ${RED}${TIME_DIFF_ABS}% slower${NC} with preprocessors"
    else
        log "  Time: No significant difference"
    fi
fi

if command -v bc &> /dev/null && [ "$CTRL_COST_MEAN" != "0" ]; then
    COST_DIFF=$(echo "scale=1; (($CTRL_COST_MEAN - $TREAT_COST_MEAN) / $CTRL_COST_MEAN) * 100" | bc 2>/dev/null || echo "?")
    if (( $(echo "$COST_DIFF > 0" | bc -l) )); then
        log "  Cost: ${GREEN}${COST_DIFF}% cheaper${NC} with preprocessors"
    elif (( $(echo "$COST_DIFF < 0" | bc -l) )); then
        COST_DIFF_ABS=$(echo "$COST_DIFF * -1" | bc)
        log "  Cost: ${RED}${COST_DIFF_ABS}% more expensive${NC} with preprocessors"
    else
        log "  Cost: No significant difference"
    fi
fi

# Per-task-type breakdown
log ""
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log "${CYAN}  Results by Task Type${NC}"
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"

# Bug-fix results
if [ ${#BUGFIX_CTRL_DUR[@]} -gt 0 ]; then
    BF_CTRL_DUR_MEAN=$(calc_mean "${BUGFIX_CTRL_DUR[*]}")
    BF_CTRL_DUR_STD=$(calc_stddev "${BUGFIX_CTRL_DUR[*]}" "$BF_CTRL_DUR_MEAN")
    BF_TREAT_DUR_MEAN=$(calc_mean "${BUGFIX_TREAT_DUR[*]}")
    BF_TREAT_DUR_STD=$(calc_stddev "${BUGFIX_TREAT_DUR[*]}" "$BF_TREAT_DUR_MEAN")
    BF_CTRL_COST_MEAN=$(calc_mean "${BUGFIX_CTRL_COST[*]}")
    BF_TREAT_COST_MEAN=$(calc_mean "${BUGFIX_TREAT_COST[*]}")

    log ""
    log "${YELLOW}  bug-fix (n=${#BUGFIX_CTRL_DUR[@]})${NC}"
    printf "    Duration: Control %.1fs ±%.1f | Treatment %.1fs ±%.1f\n" "$BF_CTRL_DUR_MEAN" "$BF_CTRL_DUR_STD" "$BF_TREAT_DUR_MEAN" "$BF_TREAT_DUR_STD" | tee -a "$RESULTS_FILE"
    printf "    Cost:     Control \$%.2f | Treatment \$%.2f\n" "$BF_CTRL_COST_MEAN" "$BF_TREAT_COST_MEAN" | tee -a "$RESULTS_FILE"
fi

# Feature-add results
if [ ${#FEATURE_CTRL_DUR[@]} -gt 0 ]; then
    FA_CTRL_DUR_MEAN=$(calc_mean "${FEATURE_CTRL_DUR[*]}")
    FA_CTRL_DUR_STD=$(calc_stddev "${FEATURE_CTRL_DUR[*]}" "$FA_CTRL_DUR_MEAN")
    FA_TREAT_DUR_MEAN=$(calc_mean "${FEATURE_TREAT_DUR[*]}")
    FA_TREAT_DUR_STD=$(calc_stddev "${FEATURE_TREAT_DUR[*]}" "$FA_TREAT_DUR_MEAN")
    FA_CTRL_COST_MEAN=$(calc_mean "${FEATURE_CTRL_COST[*]}")
    FA_TREAT_COST_MEAN=$(calc_mean "${FEATURE_TREAT_COST[*]}")

    log ""
    log "${YELLOW}  feature-add (n=${#FEATURE_CTRL_DUR[@]})${NC}"
    printf "    Duration: Control %.1fs ±%.1f | Treatment %.1fs ±%.1f\n" "$FA_CTRL_DUR_MEAN" "$FA_CTRL_DUR_STD" "$FA_TREAT_DUR_MEAN" "$FA_TREAT_DUR_STD" | tee -a "$RESULTS_FILE"
    printf "    Cost:     Control \$%.2f | Treatment \$%.2f\n" "$FA_CTRL_COST_MEAN" "$FA_TREAT_COST_MEAN" | tee -a "$RESULTS_FILE"
fi

# Refactor results
if [ ${#REFACTOR_CTRL_DUR[@]} -gt 0 ]; then
    RF_CTRL_DUR_MEAN=$(calc_mean "${REFACTOR_CTRL_DUR[*]}")
    RF_CTRL_DUR_STD=$(calc_stddev "${REFACTOR_CTRL_DUR[*]}" "$RF_CTRL_DUR_MEAN")
    RF_TREAT_DUR_MEAN=$(calc_mean "${REFACTOR_TREAT_DUR[*]}")
    RF_TREAT_DUR_STD=$(calc_stddev "${REFACTOR_TREAT_DUR[*]}" "$RF_TREAT_DUR_MEAN")
    RF_CTRL_COST_MEAN=$(calc_mean "${REFACTOR_CTRL_COST[*]}")
    RF_TREAT_COST_MEAN=$(calc_mean "${REFACTOR_TREAT_COST[*]}")

    log ""
    log "${YELLOW}  refactor (n=${#REFACTOR_CTRL_DUR[@]})${NC}"
    printf "    Duration: Control %.1fs ±%.1f | Treatment %.1fs ±%.1f\n" "$RF_CTRL_DUR_MEAN" "$RF_CTRL_DUR_STD" "$RF_TREAT_DUR_MEAN" "$RF_TREAT_DUR_STD" | tee -a "$RESULTS_FILE"
    printf "    Cost:     Control \$%.2f | Treatment \$%.2f\n" "$RF_CTRL_COST_MEAN" "$RF_TREAT_COST_MEAN" | tee -a "$RESULTS_FILE"
fi

log ""
log "${YELLOW}Raw data:${NC}"
log "  Control durations:   ${CONTROL_DURATIONS[*]}"
log "  Control costs:       ${CONTROL_COSTS[*]}"
log "  Treatment durations: ${TREATMENT_DURATIONS[*]}"
log "  Treatment costs:     ${TREATMENT_COSTS[*]}"

# Tool call analysis
log ""
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log "${CYAN}  Tool Call Analysis${NC}"
log "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
log ""

analyze_tools() {
    local label=$1
    local pattern=$2
    local total=0
    local runs=0

    log "  ${YELLOW}$label${NC}"

    # Aggregate tool counts
    local all_tools=""
    for dir in "$WORK_DIR"/$pattern/.fuel/processes/*/; do
        if [ -d "$dir" ] && [ -f "$dir/stdout.log" ]; then
            runs=$((runs + 1))
            tools=$(jq -r 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use") | .name' "$dir/stdout.log" 2>/dev/null)
            count=$(echo "$tools" | grep -c . 2>/dev/null || echo 0)
            total=$((total + count))
            all_tools="$all_tools $tools"
        fi
    done

    if [ $runs -eq 0 ]; then
        log "    No runs found"
        return
    fi

    log "    Runs: $runs"
    log "    Total tool calls: $total"
    if [ $runs -gt 0 ]; then
        avg=$(echo "scale=1; $total / $runs" | bc)
        log "    Average per run: $avg"
    fi
    log "    Breakdown:"
    echo "$all_tools" | tr ' ' '\n' | grep -v '^$' | sort | uniq -c | sort -rn | sed 's/^/      /' | tee -a "$RESULTS_FILE"
    log ""
}

analyze_tools "Control (no preprocessors)" "*_control_*"
analyze_tools "Treatment (with preprocessors)" "*_treatment_*"

# Tool comparison
CTRL_TOOLS=0
TREAT_TOOLS=0
CTRL_RUNS=0
TREAT_RUNS=0

for dir in "$WORK_DIR"/*_control_*/.fuel/processes/*/; do
    if [ -d "$dir" ] && [ -f "$dir/stdout.log" ]; then
        CTRL_RUNS=$((CTRL_RUNS + 1))
        count=$(jq -r 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use") | .name' "$dir/stdout.log" 2>/dev/null | grep -c . || echo 0)
        CTRL_TOOLS=$((CTRL_TOOLS + count))
    fi
done

for dir in "$WORK_DIR"/*_treatment_*/.fuel/processes/*/; do
    if [ -d "$dir" ] && [ -f "$dir/stdout.log" ]; then
        TREAT_RUNS=$((TREAT_RUNS + 1))
        count=$(jq -r 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use") | .name' "$dir/stdout.log" 2>/dev/null | grep -c . || echo 0)
        TREAT_TOOLS=$((TREAT_TOOLS + count))
    fi
done

if [ $CTRL_RUNS -gt 0 ] && [ $TREAT_RUNS -gt 0 ]; then
    CTRL_AVG=$(echo "scale=1; $CTRL_TOOLS / $CTRL_RUNS" | bc)
    TREAT_AVG=$(echo "scale=1; $TREAT_TOOLS / $TREAT_RUNS" | bc)
    TOOL_DIFF=$(echo "scale=1; (($CTRL_AVG - $TREAT_AVG) / $CTRL_AVG) * 100" | bc 2>/dev/null || echo "?")

    log "  ${YELLOW}Comparison${NC}"
    log "    Control avg:   $CTRL_AVG tools/run"
    log "    Treatment avg: $TREAT_AVG tools/run"
    if (( $(echo "$TOOL_DIFF > 0" | bc -l 2>/dev/null || echo 0) )); then
        log "    Difference:    ${GREEN}${TOOL_DIFF}% fewer tools${NC} with preprocessors"
    elif (( $(echo "$TOOL_DIFF < 0" | bc -l 2>/dev/null || echo 0) )); then
        TOOL_DIFF_ABS=$(echo "$TOOL_DIFF * -1" | bc)
        log "    Difference:    ${RED}${TOOL_DIFF_ABS}% more tools${NC} with preprocessors"
    else
        log "    Difference:    No significant difference"
    fi
fi

log ""
log "${YELLOW}Work directory:${NC} $WORK_DIR"
log "${YELLOW}Results saved:${NC} $RESULTS_FILE"
log ""
log "${GREEN}Benchmark complete!${NC}"
