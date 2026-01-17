#!/bin/bash
set -e

# Fuel Preprocessor Benchmark
# Compares task execution with and without preprocessors

FUEL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BENCH_DIR="$FUEL_ROOT/bench"
FUEL_BIN="$FUEL_ROOT/fuel"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Benchmark tasks - title and description
declare -a TASKS=(
    "Fix auth bug where empty password allows login|In AuthService, the authenticate method should reject empty passwords before checking the hash"
    "Add validation to TaskService create method|TaskService.create should validate that title is not empty and priority is valid (low/medium/high)"
    "Make token expiry configurable|TokenService has hardcoded TOKEN_EXPIRY_HOURS=24. Make it configurable via constructor parameter"
)

# Create temp directory for benchmark
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
WORK_DIR="/tmp/fuel-bench-$TIMESTAMP"
mkdir -p "$WORK_DIR"

echo -e "${YELLOW}Fuel Preprocessor Benchmark${NC}"
echo "Working directory: $WORK_DIR"
echo ""

# Function to setup a benchmark instance
setup_instance() {
    local name=$1
    local dir="$WORK_DIR/$name"

    echo -e "${GREEN}Setting up $name instance...${NC}"

    # Copy bench project
    cp -r "$BENCH_DIR" "$dir"
    rm -f "$dir/benchmark.sh" "$dir/README.md"  # Don't need these in test instance

    # Initialize fuel
    cd "$dir"
    "$FUEL_BIN" init --quiet 2>/dev/null || true

    echo "$dir"
}

# Function to run a task and capture metrics
run_task() {
    local dir=$1
    local title=$2
    local description=$3
    local use_preprocessors=$4

    cd "$dir"

    # Add the task
    local task_output
    task_output=$("$FUEL_BIN" add "$title" --description="$description" --json 2>/dev/null)
    local task_id
    task_id=$(echo "$task_output" | grep -o '"short_id":"[^"]*"' | cut -d'"' -f4)

    if [ -z "$task_id" ]; then
        echo "Failed to create task"
        return 1
    fi

    # Run the task
    local start_time
    start_time=$(date +%s)

    local run_flags=""
    if [ "$use_preprocessors" = "false" ]; then
        run_flags="--no-preprocessors"
    fi

    # Run task (capture output but don't fail on non-zero exit)
    "$FUEL_BIN" run "$task_id" $run_flags --no-done 2>/dev/null || true

    local end_time
    end_time=$(date +%s)
    local duration=$((end_time - start_time))

    # Get stats from run
    local stats
    stats=$("$FUEL_BIN" runs --json 2>/dev/null | tail -1)

    echo "$task_id|$duration|$stats"
}

# Setup instances
echo -e "\n${YELLOW}=== Setup ===${NC}"
CONTROL_DIR=$(setup_instance "control")
TREATMENT_DIR=$(setup_instance "treatment")

echo ""
echo "Control (no preprocessors): $CONTROL_DIR"
echo "Treatment (with preprocessors): $TREATMENT_DIR"

# Run benchmarks
echo -e "\n${YELLOW}=== Running Benchmarks ===${NC}"
echo "Note: This requires a configured agent. Tasks may timeout if no agent is available."
echo ""

# Results arrays
declare -a CONTROL_RESULTS
declare -a TREATMENT_RESULTS

for task_spec in "${TASKS[@]}"; do
    IFS='|' read -r title description <<< "$task_spec"

    echo -e "${GREEN}Task: $title${NC}"

    echo "  Running control (no preprocessors)..."
    control_result=$(run_task "$CONTROL_DIR" "$title" "$description" "false" 2>/dev/null || echo "error")
    CONTROL_RESULTS+=("$control_result")

    echo "  Running treatment (with preprocessors)..."
    treatment_result=$(run_task "$TREATMENT_DIR" "$title" "$description" "true" 2>/dev/null || echo "error")
    TREATMENT_RESULTS+=("$treatment_result")

    echo ""
done

# Summary
echo -e "\n${YELLOW}=== Results Summary ===${NC}"
echo ""
echo "Control directory: $CONTROL_DIR"
echo "Treatment directory: $TREATMENT_DIR"
echo ""
echo "Run 'fuel stats' in each directory to compare metrics."
echo ""
echo "Quick comparison:"
echo "  cd $CONTROL_DIR && $FUEL_BIN runs"
echo "  cd $TREATMENT_DIR && $FUEL_BIN runs"
echo ""
echo -e "${GREEN}Benchmark complete!${NC}"
echo "Directories preserved in $WORK_DIR for inspection."
