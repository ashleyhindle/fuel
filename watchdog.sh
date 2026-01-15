#!/bin/bash
# Fuel task watchdog - reopens stuck tasks with dead PIDs

DB=".fuel/agent.db"
INTERVAL=900  # 15 minutes

check_and_reopen() {
    echo "[$(date '+%H:%M:%S')] Checking for stuck tasks..."

    # Get LATEST run per consumed in_progress task (battery icon = consumed)
    sqlite3 "$DB" "
        SELECT r.short_id, r.pid, t.short_id, t.title
        FROM runs r
        JOIN tasks t ON r.task_id = t.id
        WHERE r.status = 'running'
        AND r.pid IS NOT NULL
        AND t.status = 'in_progress'
        AND t.consumed = 1
        AND r.id = (
            SELECT MAX(r2.id) FROM runs r2 WHERE r2.task_id = r.task_id
        )
    " 2>/dev/null | while IFS='|' read -r run_id pid task_id title; do
        if [ -n "$pid" ] && [ "$pid" -gt 0 ]; then
            # Check if PID exists using /proc (safer than kill -0)
            if [ ! -d "/proc/$pid" ] && ! ps -p "$pid" > /dev/null 2>&1; then
                echo "  Stuck: $task_id ($title)"
                echo "    Latest run $run_id has dead PID $pid"

                # Mark run as failed
                sqlite3 "$DB" "
                    UPDATE runs
                    SET status = 'failed',
                        exit_code = -1,
                        ended_at = datetime('now'),
                        output = '[Watchdog: process died]'
                    WHERE short_id = '$run_id'
                " 2>/dev/null

                # Reopen the task
                ./fuel reopen "$task_id" --silent 2>/dev/null
                echo "    Reopened"
            fi
        fi
    done

    # Show board
    ./fuel consume --once 2>/dev/null | head -20
}

echo "Fuel watchdog started (checking every ${INTERVAL}s)"
echo "Press Ctrl+C to stop"
echo ""

while true; do
    check_and_reopen
    echo ""
    echo "[$(date '+%H:%M:%S')] Sleeping ${INTERVAL}s..."
    sleep $INTERVAL
done
