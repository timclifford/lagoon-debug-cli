# Debug cli

Wraps the GovCMS debug cli inside a uselagoon/php base image without the web application stuff.

Pulls ssh key from pygmy.


### Local
    TEMP_FOLDER=./tmp/ ./cli ...


### Build
    docker-compose build --pull

### Run container
    docker run -it --rm \
        --volumes-from=amazeeio-ssh-agent \
        lagoon-debug-cli_cli \
        ./entrypoint.sh www.safeworkaustralia.gov.au

### Debug env vars with '-v' flag
    docker run -it --rm \
        --volumes-from=amazeeio-ssh-agent \
        lagoon-debug-cli_cli \
        ./entrypoint.sh -v www.safeworkaustralia.gov.au


### Build from tag amazeeio/lagoon-debug-cli
    docker build --pull -t amazeeio/lagoon-debug-cli .
    docker run -it --rm \
        --volumes-from=amazeeio-ssh-agent \
        amazeeio/lagoon-debug-cli \
        ./entrypoint.sh www.safeworkaustralia.gov.au
