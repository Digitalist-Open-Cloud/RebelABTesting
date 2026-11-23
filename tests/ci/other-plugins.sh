#!/bin/bash
# Fetches the plugins that some tests of this plugin need, into the Matomo the tests run in.
# Runs in the Matomo directory. A plugin that cannot be fetched is left out and its tests skip.
set -u

fetch() {
  rm -rf "plugins/$1"
  git clone --quiet --depth=1 "$2" "plugins/$1" && echo "Fetched $1." || echo "Could not fetch $1, its tests will be skipped."
}

fetch RebelFunnels https://github.com/Digitalist-Open-Cloud/RebelFunnels

# UserFeedback is private and needs a token that can read it.
if [ -n "${OTHER_PLUGINS_TOKEN:-}" ]; then
  fetch UserFeedback "https://x-access-token:${OTHER_PLUGINS_TOKEN}@github.com/Digitalist-Open-Cloud/Matomo-Plugin-UserFeedback"
else
  echo "No token for UserFeedback, its tests will be skipped."
fi
