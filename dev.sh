#!/bin/bash

# Development Startup Script
# Manages: Laravel Scheduler, Queue Worker, npm/Vite dev server
# Usage: ./dev.sh [start|stop|restart|status]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# PID file directory
PID_DIR="$SCRIPT_DIR/storage/framework"
mkdir -p "$PID_DIR"

# PID files
SCHEDULER_PID="$PID_DIR/scheduler.pid"
WORKER_PID="$PID_DIR/worker.pid"
NPM_PID="$PID_DIR/npm.pid"
STRIPE_PID="$PID_DIR/stripe.pid"

# Log files
SCHEDULER_LOG="$SCRIPT_DIR/storage/logs/scheduler.log"
WORKER_LOG="$SCRIPT_DIR/storage/logs/worker.log"
NPM_LOG="$SCRIPT_DIR/storage/logs/npm.log"
STRIPE_LOG="$SCRIPT_DIR/storage/logs/stripe.log"

# Configuration
ENABLE_STRIPE="${ENABLE_STRIPE:-false}"
STRIPE_WEBHOOK_URL="${STRIPE_WEBHOOK_URL:-http://localhost/webhook/stripe}"

# Function to determine command prefix
setup_commands() {
    # Check if we're inside a Docker container
    if [ -f "/.dockerenv" ] || [ -f "/proc/self/cgroup" ] && grep -q docker /proc/self/cgroup 2>/dev/null; then
        # Inside container - run commands directly
        ARTISAN_CMD="php artisan"
        NPM_CMD="npm"
        echo -e "${CYAN}Detected: Running inside Docker container${NC}"
    elif [ -f "./vendor/bin/sail" ]; then
        # On host - use Sail wrapper
        ARTISAN_CMD="./vendor/bin/sail artisan"
        NPM_CMD="./vendor/bin/sail npm"
        echo -e "${CYAN}Detected: Running on host, using Sail wrapper${NC}"
    else
        echo -e "${RED}Error: Cannot determine execution environment.${NC}"
        echo -e "${YELLOW}Please ensure Sail is available or run this script inside the container.${NC}"
        exit 1
    fi
}

# Function to print status
print_status() {
    local name=$1
    local pid_file=$2
    local log_file=$3

    if [ -f "$pid_file" ]; then
        local pid=$(cat "$pid_file")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -e "  ${GREEN}✓${NC} $name (PID: $pid)"
            return 0
        else
            echo -e "  ${RED}✗${NC} $name (stale PID file)"
            rm -f "$pid_file"
            return 1
        fi
    else
        echo -e "  ${RED}✗${NC} $name (not running)"
        return 1
    fi
}

# Function to start scheduler
start_scheduler() {
    if [ -f "$SCHEDULER_PID" ]; then
        local pid=$(cat "$SCHEDULER_PID")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -e "${YELLOW}Scheduler is already running (PID: $pid)${NC}"
            return 0
        fi
        rm -f "$SCHEDULER_PID"
    fi

    echo -e "${CYAN}Starting Laravel Scheduler...${NC}"
    $ARTISAN_CMD schedule:work > "$SCHEDULER_LOG" 2>&1 &
    local pid=$!
    echo $pid > "$SCHEDULER_PID"
    echo -e "${GREEN}Scheduler started (PID: $pid)${NC}"
}

# Function to start queue worker
start_worker() {
    if [ -f "$WORKER_PID" ]; then
        local pid=$(cat "$WORKER_PID")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -e "${YELLOW}Queue worker is already running (PID: $pid)${NC}"
            return 0
        fi
        rm -f "$WORKER_PID"
    fi

    echo -e "${CYAN}Starting Queue Worker...${NC}"
    $ARTISAN_CMD queue:work --tries=3 --timeout=90 > "$WORKER_LOG" 2>&1 &
    local pid=$!
    echo $pid > "$WORKER_PID"
    echo -e "${GREEN}Queue worker started (PID: $pid)${NC}"
}

# Function to start npm dev server
start_npm() {
    if [ -f "$NPM_PID" ]; then
        local pid=$(cat "$NPM_PID")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -e "${YELLOW}npm dev server is already running (PID: $pid)${NC}"
            return 0
        fi
        rm -f "$NPM_PID"
    fi

    echo -e "${CYAN}Starting npm dev server...${NC}"
    $NPM_CMD run dev > "$NPM_LOG" 2>&1 &
    local pid=$!
    echo $pid > "$NPM_PID"
    echo -e "${GREEN}npm dev server started (PID: $pid)${NC}"
}

# Function to start Stripe webhook listener (optional)
start_stripe() {
    if [ "$ENABLE_STRIPE" != "true" ]; then
        return 0
    fi

    if ! command -v stripe &> /dev/null; then
        echo -e "${YELLOW}Stripe CLI not found. Skipping Stripe webhook listener.${NC}"
        echo -e "${YELLOW}Install: https://stripe.com/docs/stripe-cli${NC}"
        return 0
    fi

    if [ -f "$STRIPE_PID" ]; then
        local pid=$(cat "$STRIPE_PID")
        if ps -p "$pid" > /dev/null 2>&1; then
            echo -e "${YELLOW}Stripe webhook listener is already running (PID: $pid)${NC}"
            return 0
        fi
        rm -f "$STRIPE_PID"
    fi

    echo -e "${CYAN}Starting Stripe webhook listener...${NC}"
    stripe listen --forward-to "$STRIPE_WEBHOOK_URL" > "$STRIPE_LOG" 2>&1 &
    local pid=$!
    echo $pid > "$STRIPE_PID"
    echo -e "${GREEN}Stripe webhook listener started (PID: $pid)${NC}"
    echo -e "${YELLOW}Note: Make sure to configure STRIPE_WEBHOOK_SECRET in .env${NC}"
}

# Function to stop a process
stop_process() {
    local name=$1
    local pid_file=$2

    if [ ! -f "$pid_file" ]; then
        echo -e "${YELLOW}$name is not running${NC}"
        return 0
    fi

    local pid=$(cat "$pid_file")
    if ! ps -p "$pid" > /dev/null 2>&1; then
        echo -e "${YELLOW}$name was not running (stale PID file)${NC}"
        rm -f "$pid_file"
        return 0
    fi

    echo -e "${CYAN}Stopping $name (PID: $pid)...${NC}"
    kill "$pid" 2>/dev/null || true
    
    # Wait up to 5 seconds for graceful shutdown
    local count=0
    while ps -p "$pid" > /dev/null 2>&1 && [ $count -lt 10 ]; do
        sleep 0.5
        count=$((count + 1))
    done

    # Force kill if still running
    if ps -p "$pid" > /dev/null 2>&1; then
        echo -e "${YELLOW}Force killing $name...${NC}"
        kill -9 "$pid" 2>/dev/null || true
        sleep 0.5
    fi

    if ps -p "$pid" > /dev/null 2>&1; then
        echo -e "${RED}Failed to stop $name${NC}"
        return 1
    else
        echo -e "${GREEN}$name stopped${NC}"
        rm -f "$pid_file"
        return 0
    fi
}

# Function to stop all processes
stop_all() {
    echo -e "${CYAN}Stopping all services...${NC}"
    stop_process "npm dev server" "$NPM_PID"
    if [ "$ENABLE_STRIPE" = "true" ]; then
        stop_process "Stripe webhook listener" "$STRIPE_PID"
    fi
    stop_process "Queue worker" "$WORKER_PID"
    stop_process "Laravel Scheduler" "$SCHEDULER_PID"
}

# Function to start all processes
start_all() {
    setup_commands
    
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}Starting Development Services${NC}"
    echo -e "${BLUE}========================================${NC}"
    
    start_scheduler
    start_worker
    start_npm
    start_stripe
    
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${GREEN}All services started!${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
    echo -e "Logs:"
    echo -e "  Scheduler: ${CYAN}tail -f $SCHEDULER_LOG${NC}"
    echo -e "  Worker:    ${CYAN}tail -f $WORKER_LOG${NC}"
    echo -e "  npm:       ${CYAN}tail -f $NPM_LOG${NC}"
    if [ "$ENABLE_STRIPE" = "true" ]; then
        echo -e "  Stripe:    ${CYAN}tail -f $STRIPE_LOG${NC}"
    fi
    echo ""
}

# Function to show status
show_status() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}Development Services Status${NC}"
    echo -e "${BLUE}========================================${NC}"
    
    print_status "Laravel Scheduler" "$SCHEDULER_PID" "$SCHEDULER_LOG"
    print_status "Queue Worker" "$WORKER_PID" "$WORKER_LOG"
    print_status "npm dev server" "$NPM_PID" "$NPM_LOG"
    if [ "$ENABLE_STRIPE" = "true" ]; then
        print_status "Stripe webhook listener" "$STRIPE_PID" "$STRIPE_LOG"
    fi
    
    echo ""
}

# Trap signals for graceful shutdown
trap 'echo -e "\n${YELLOW}Shutting down...${NC}"; stop_all; exit 0' INT TERM

# Main command handler
case "${1:-}" in
    start)
        start_all
        # Keep script running to maintain processes
        echo -e "${CYAN}Press Ctrl+C to stop all services${NC}"
        wait
        ;;
    stop)
        stop_all
        ;;
    restart)
        echo -e "${CYAN}Restarting all services...${NC}"
        stop_all
        sleep 2
        start_all
        echo -e "${CYAN}Press Ctrl+C to stop all services${NC}"
        wait
        ;;
    status)
        show_status
        ;;
    *)
        echo -e "${BLUE}Usage: $0 {start|stop|restart|status}${NC}"
        echo ""
        echo -e "Commands:"
        echo -e "  ${GREEN}start${NC}    - Start all development services"
        echo -e "  ${RED}stop${NC}     - Stop all development services"
        echo -e "  ${YELLOW}restart${NC}  - Restart all development services"
        echo -e "  ${CYAN}status${NC}   - Show status of all services"
        echo ""
        echo -e "Environment Variables:"
        echo -e "  ${CYAN}ENABLE_STRIPE${NC}=true        - Enable Stripe webhook listener (default: false)"
        echo -e "  ${CYAN}STRIPE_WEBHOOK_URL${NC}=url    - Stripe webhook forwarding URL (default: http://localhost/webhook/stripe)"
        echo ""
        echo -e "Examples:"
        echo -e "  ${GREEN}./dev.sh start${NC}                    # Start all services"
        echo -e "  ${GREEN}ENABLE_STRIPE=true ./dev.sh start${NC} # Start with Stripe listener"
        echo -e "  ${GREEN}./dev.sh status${NC}                   # Check status"
        echo -e "  ${RED}./dev.sh stop${NC}                      # Stop all services"
        echo ""
        echo -e "Note: Sail must be started manually before running this script."
        exit 1
        ;;
esac
