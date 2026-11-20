#!/bin/bash
# Starts every app on this server. Set the panel's Startup command to:
#   bash /home/container/start-all.sh
#
# Runs: Gemini Promo bot (+ ABA watcher) and the COHERS web app.
# The demo store bot is no longer started (its folder is left untouched).

trap 'kill "$GEMINI_PID" "$COHERS_PID" 2>/dev/null; wait; exit 0' TERM INT

# Gemini Promo bot + ABA watcher (its existing startup script, unchanged)
bash /home/container/geminiprobot/start-gemini.sh &
GEMINI_PID=$!

# COHERS dashboard on this server's port + queue worker, scheduler, Telegram polling
bash /home/container/cohers/start.sh &
COHERS_PID=$!

wait
