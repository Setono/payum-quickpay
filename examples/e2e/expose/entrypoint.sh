#!/bin/sh
set -e

# `expose token` writes to a config file that doesn't persist in an ephemeral container, so re-apply
# the token from EXPOSE_TOKEN on every run before sharing.
if [ -n "$EXPOSE_TOKEN" ]; then
    expose token "$EXPOSE_TOKEN" >/dev/null 2>&1 || true
fi

exec expose "$@"
