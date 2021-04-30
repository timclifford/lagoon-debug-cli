#!/bin/sh

set -e

#args
# domain=''
verbose='false'

while getopts ':v' flag
do
    case "${flag}" in
       # d) domain="${OPTARG}" ;;
       v) verbose='true' ;;
    esac
done

# envs
export TEMP_FOLDER=./tmp/
export GITLAB_TOKEN=token
export ELASTICSEARCH_ENDPOINT=localhost
export ELASTICSEARCH_TOKEN=token
export GOOGLE_ANALYTICS_VIEW_ID=ga:1231234
export GOOGLE_APPLICATION_CREDENTIALS=./tmp/google-analytics-creds.json

export COMPOSER_ALLOW_XDEBUG=1

# Lagoon
export LAGOON_ENDPOINT=https://api-lagoon-master.lagoon.ch.amazee.io/graphql

if [[ -z "${LAGOON_TOKEN}" ]]; then
    export LAGOON_TOKEN=$(ssh -p 32222 -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -t lagoon@ssh.lagoon.amazeeio.cloud token 2>&1 | grep '^ey' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
else
    export LAGOON_TOKEN="${LAGOON_TOKEN}"
fi

if [ -z "$LAGOON_TOKEN" ]; then
 echo "Could not retrieve token"
 exit 0
fi

if [ -z "$DEBUG_COMMAND" ]; then
  export DEBUG_COMMAND="domain:details"
fi

if [ "$verbose" = true ] ; then
  echo "---------------------------"
  echo "Print environment variables"
  echo "---------------------------"
  env

  echo "------------------------"
  echo "Running debug command..."
  echo "------------------------"
  exec env LAGOON_ENDPOINT=${LAGOON_ENDPOINT} LAGOON_TOKEN=${LAGOON_TOKEN} TEMP_FOLDER=${TEMP_FOLDER} ./cli ${DEBUG_COMMAND} "$@"
else
  exec env LAGOON_ENDPOINT=${LAGOON_ENDPOINT} LAGOON_TOKEN=${LAGOON_TOKEN} TEMP_FOLDER=${TEMP_FOLDER} ./cli ${DEBUG_COMMAND} "$@"
fi
# exec "$@"
