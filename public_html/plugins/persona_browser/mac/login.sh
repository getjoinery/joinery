#!/bin/bash
# Open a real Firefox window to log into the site by hand. Run this once (and
# again whenever the session expires). Must be run in a desktop session on the
# Mac, not over plain SSH — it needs a screen.
export PATH="$HOME/persona-browser/node/bin:$PATH"
cd ~/persona-browser
node login.js
