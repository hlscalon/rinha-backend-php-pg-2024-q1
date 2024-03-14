docker compose rm db
docker compose -f docker-compose-test.yml --compatibility up --force-recreate --build --remove-orphans
