#!/bin/sh

set -e

# Lagoon
if [[ -z "${LAGOON_TOKEN}" ]]; then
    LAGOON_TOKEN=$(ssh -p 32222 -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -t lagoon@ssh.lagoon.amazeeio.cloud token 2>&1 | grep '^ey' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
else
    LAGOON_TOKEN="${LAGOON_TOKEN}"
fi

export LAGOON_TOKEN=$LAGOON_TOKEN;
