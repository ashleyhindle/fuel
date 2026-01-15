#!/bin/bash
# Overnight monitor - keeps fuel running, logs actions for morning review

DB=".fuel/agent.db"
LOG=".fuel/overnight.log"
DAEMON_LOG=".fuel/daemon.log"
INTERVAL=300  # 5 minutes

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG"
    echo "[$(date '+%H:%M:%S')] $1"
}

check_runner() {
    if pgrep -f "fuel consume" > /dev/null 2>&1; then
        return 0  # Running
    else
        return 1  # Not running
    fi
}

restart_runner() {
    log "RUNNER DIED - Restarting fuel consume"

    # Check daemon.log for recent errors
    if [ -f "$DAEMON_LOG" ]; then
        RECENT_ERROR=$(tail -20 "$DAEMON_LOG" | grep -i "exception\|error\|fatal" | tail -1)
        if [ -n "$RECENT_ERROR" ]; then
            log "Last error in daemon.log: $RECENT_ERROR"
        fi
    fi

    # Restart the runner
    cd /Users/ashleyhindle/Code/fuel
    nohup ./fuel consume >> .fuel/runner-output.log 2>&1 &
    sleep 3

    if check_runner; then
        log "Runner restarted successfully (PID: $(pgrep -f 'fuel consume'))"
    else
        log "FAILED to restart runner - needs manual intervention"
    fi
}

check_stuck_tasks() {
    # Get LATEST run per consumed in_progress task
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
            if ! ps -p "$pid" > /dev/null 2>&1; then
                log "STUCK TASK: $task_id ($title) - run $run_id has dead PID $pid"

                # Mark run as failed
                sqlite3 "$DB" "
                    UPDATE runs
                    SET status = 'failed',
                        exit_code = -1,
                        ended_at = datetime('now'),
                        output = '[Overnight: process died]'
                    WHERE short_id = '$run_id'
                " 2>/dev/null

                # Reopen the task
                ./fuel reopen "$task_id" --silent 2>/dev/null
                log "Reopened task $task_id"
            fi
        fi
    done
}

check_daemon_errors() {
    if [ -f "$DAEMON_LOG" ]; then
        # Check for new errors since last check
        LAST_CHECK_LINE=${LAST_CHECK_LINE:-0}
        CURRENT_LINES=$(wc -l < "$DAEMON_LOG")

        if [ "$CURRENT_LINES" -gt "$LAST_CHECK_LINE" ]; then
            NEW_ERRORS=$(tail -n +$((LAST_CHECK_LINE + 1)) "$DAEMON_LOG" | grep -i "exception\|fatal")
            if [ -n "$NEW_ERRORS" ]; then
                log "NEW ERRORS in daemon.log:"
                echo "$NEW_ERRORS" | while read -r line; do
                    log "  $line"
                done
            fi
            LAST_CHECK_LINE=$CURRENT_LINES
        fi
    fi
}

# Start
log "=========================================="
log "Overnight monitor started"
log "Interval: ${INTERVAL}s ($(($INTERVAL / 60)) minutes)"
log "=========================================="

# Initial status
if check_runner; then
    log "Runner is running (PID: $(pgrep -f 'fuel consume'))"
else
    log "Runner not running - starting it"
    restart_runner
fi

# Main loop
while true; do
    echo "[$(date '+%H:%M:%S')] Checking..."

    # 1. Check if runner is alive
    if ! check_runner; then
        restart_runner
    fi

    # 2. Check for stuck tasks
    check_stuck_tasks

    # 3. Check for daemon errors
    check_daemon_errors

    # 4. Quick status
    READY=$(sqlite3 "$DB" "SELECT COUNT(*) FROM tasks WHERE status = 'open'" 2>/dev/null)
    IN_PROGRESS=$(sqlite3 "$DB" "SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'" 2>/dev/null)
    echo "[$(date '+%H:%M:%S')] Ready: $READY, In Progress: $IN_PROGRESS"

    sleep $INTERVAL
done
