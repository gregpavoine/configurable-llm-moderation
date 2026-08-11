#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly ENV_FILE="$SCRIPT_DIR/.env.docker"
readonly ENV_EXAMPLE="$SCRIPT_DIR/.env.docker.example"
readonly WAIT_TIMEOUT=180

build=true

usage() {
    cat <<'EOF'
Usage: ./launch.sh [--no-build]

Starts the complete local moderation stack and waits until it is ready.

Options:
  --no-build  Start existing images without rebuilding them.
  -h, --help  Show this help message.
EOF
}

fail_usage() {
    printf 'Unknown option: %s\n\n' "$1" >&2
    usage >&2
    exit 64
}

for argument in "$@"; do
    case "$argument" in
        --no-build)
            build=false
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail_usage "$argument"
            ;;
    esac
done

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Required command not found: %s\n' "$1" >&2
        exit 1
    fi
}

require_command docker
require_command curl
require_command openssl
require_command awk
require_command grep

cd "$SCRIPT_DIR"

prepare_environment() {
    if [[ ! -f "$ENV_EXAMPLE" ]]; then
        printf 'Missing environment template: %s\n' "$ENV_EXAMPLE" >&2
        exit 1
    fi

    if [[ ! -f "$ENV_FILE" ]]; then
        cp "$ENV_EXAMPLE" "$ENV_FILE"
    fi

    if ! grep -q '^APP_SECRET=' "$ENV_FILE"; then
        printf 'APP_SECRET is missing from %s\n' "$ENV_FILE" >&2
        exit 1
    fi

    local current_secret
    current_secret="$(awk '/^APP_SECRET=/ { sub(/^[^=]*=/, ""); print; exit }' "$ENV_FILE")"
    if [[ -z "$current_secret" || "$current_secret" == 'replace-with-a-long-random-value' ]]; then
        local generated_secret
        local generated_file
        generated_secret="$(openssl rand -hex 32)"
        generated_file="$(mktemp "$ENV_FILE.tmp.XXXXXX")"
        awk -v secret="$generated_secret" '
            /^APP_SECRET=/ { print "APP_SECRET=" secret; next }
            { print }
        ' "$ENV_FILE" > "$generated_file"
        mv "$generated_file" "$ENV_FILE"
    fi

    chmod 600 "$ENV_FILE"
}

prepare_environment

read_environment_value() {
    awk -v key="$1" '$0 ~ "^" key "=" { sub(/^[^=]*=/, ""); print; exit }' "$ENV_FILE"
}

api_port="$(read_environment_value API_PORT)"
ollama_port="$(read_environment_value OLLAMA_HOST_PORT)"
api_port="${api_port:-8000}"
ollama_port="${ollama_port:-11435}"

if ! docker info >/dev/null 2>&1; then
    printf 'Docker is not running. Start Docker Desktop and retry.\n' >&2
    exit 1
fi

compose=(docker compose --env-file "$ENV_FILE")

show_failure_context() {
    local exit_code=$?
    printf '\nLaunch failed. Recent container logs:\n' >&2
    "${compose[@]}" ps -a >&2 || true
    "${compose[@]}" logs --tail=80 init php web worker ollama ollama-init >&2 || true
    exit "$exit_code"
}

trap show_failure_context ERR

"${compose[@]}" config --quiet

if [[ "$build" == true ]]; then
    "${compose[@]}" up --build -d
else
    "${compose[@]}" up -d
fi

wait_until_ready() {
    local deadline=$((SECONDS + WAIT_TIMEOUT))

    while ((SECONDS < deadline)); do
        if curl -fsS "http://127.0.0.1:$api_port/health" >/dev/null 2>&1 \
            && "${compose[@]}" exec -T ollama ollama list >/dev/null 2>&1 \
            && "${compose[@]}" ps --status running --services | grep -qx php \
            && "${compose[@]}" ps --status running --services | grep -qx web \
            && "${compose[@]}" ps --status running --services | grep -qx worker; then
            return 0
        fi

        sleep 2
    done

    printf 'Services did not become ready within %s seconds.\n' "$WAIT_TIMEOUT" >&2
    return 1
}

wait_until_ready

[[ "$(docker inspect --format '{{.State.ExitCode}}' comment-moderation-init-1)" == 0 ]]
[[ "$(docker inspect --format '{{.State.ExitCode}}' comment-moderation-ollama-init-1)" == 0 ]]

trap - ERR

"${compose[@]}" ps -a

printf '\nStack ready.\n'
printf 'API:     http://127.0.0.1:%s\n' "$api_port"
printf 'Health:  http://127.0.0.1:%s/health\n' "$api_port"
printf 'Ollama:  http://127.0.0.1:%s\n' "$ollama_port"
cat <<'EOF'

Generate a local moderator token:
docker compose --env-file .env.docker --profile tools run --rm token --subject=alice

Complete test procedure: docs/TESTING.md
EOF
