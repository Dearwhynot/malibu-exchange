#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  ./nodejs_scripts/sftp-deploy.sh [options] [file1 file2 ...]

Behavior:
  - If files are passed, uploads only those files.
  - If files are not passed, runs full mirror upload from project root.

Options:
  -c, --config <path>  Path to sftp.json (default: ./.vscode/sftp.json)
      --delete         Delete remote files missing locally (mirror mode)
      --dry-run        Print plan only (files mode) or use lftp --dry-run (mirror mode)
  -h, --help           Show this help

Environment overrides (optional):
  SFTP_HOST
  SFTP_PORT
  SFTP_USERNAME
  SFTP_PASSWORD
  SFTP_REMOTE_PATH
USAGE
}

escape_lftp() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

normalize_rel() {
  local path="$1"
  path="${path#./}"
  if [[ "$path" == "$PROJECT_ROOT"* ]]; then
    path="${path#"$PROJECT_ROOT"/}"
  fi
  printf '%s' "$path"
}

matches_ignore() {
  local rel="$1"
  local p
  for p in "${IGNORE_PATTERNS[@]}"; do
    [[ -z "$p" ]] && continue
    [[ "$rel" == $p ]] && return 0
  done
  return 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG_PATH="${PROJECT_ROOT}/.vscode/sftp.json"
DELETE_MODE=0
DRY_RUN=0

FILES=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    -c|--config)
      [[ $# -ge 2 ]] || { echo "Missing value for $1" >&2; exit 1; }
      CONFIG_PATH="$2"
      shift 2
      ;;
    --delete)
      DELETE_MODE=1
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      FILES+=("$1")
      shift
      ;;
  esac
done

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required but not found." >&2
  exit 1
fi

if ! command -v lftp >/dev/null 2>&1; then
  echo "lftp is required but not found. Install it with: brew install lftp" >&2
  exit 1
fi

if [[ ! -f "$CONFIG_PATH" ]]; then
  echo "Config file not found: $CONFIG_PATH" >&2
  exit 1
fi

protocol="$(jq -er '.protocol // "sftp"' "$CONFIG_PATH")"
host="$(jq -er '.host' "$CONFIG_PATH")"
port="$(jq -er '.port // 22' "$CONFIG_PATH")"
username="$(jq -er '.username' "$CONFIG_PATH")"
password="$(jq -er '.password // ""' "$CONFIG_PATH")"
remote_path="$(jq -er '.remotePath' "$CONFIG_PATH")"

host="${SFTP_HOST:-$host}"
port="${SFTP_PORT:-$port}"
username="${SFTP_USERNAME:-$username}"
password="${SFTP_PASSWORD:-$password}"
remote_path="${SFTP_REMOTE_PATH:-$remote_path}"

if [[ "$protocol" != "sftp" ]]; then
  echo "Unsupported protocol in config: $protocol (expected sftp)" >&2
  exit 1
fi

IGNORE_PATTERNS=()
while IFS= read -r p; do
  [[ -n "$p" ]] && IGNORE_PATTERNS+=("$p")
done < <(jq -r '.ignore[]?' "$CONFIG_PATH")
IGNORE_PATTERNS+=(
  ".git/**"
  ".vscode/**"
  "doc/**"
  "sql/**"
  "tmp/**"
  "tests/**"
  # "nodejs_scripts/**"
  "screenshot.png"
)

host_esc="$(escape_lftp "$host")"
user_esc="$(escape_lftp "$username")"
pass_esc="$(escape_lftp "$password")"
remote_esc="$(escape_lftp "$remote_path")"
project_esc="$(escape_lftp "$PROJECT_ROOT/")"

echo "Target: sftp://${host}:${port}${remote_path}"

if [[ ${#FILES[@]} -eq 0 ]]; then
  exclude_file="$(mktemp -t sftp-ignore.XXXXXX)"
  lftp_script="$(mktemp -t lftp-script.XXXXXX)"
  trap 'rm -f "$exclude_file" "$lftp_script"' EXIT

  for p in "${IGNORE_PATTERNS[@]}"; do
    printf '%s\n' "$p" >> "$exclude_file"
  done

  exclude_esc="$(escape_lftp "$exclude_file")"

  {
    echo "set cmd:fail-exit yes"
    echo "set sftp:auto-confirm yes"
    echo "set net:max-retries 2"
    echo "set net:reconnect-interval-base 5"
    echo "set net:reconnect-interval-max 20"
    echo "open -u \"${user_esc}\",\"${pass_esc}\" -p ${port} \"sftp://${host_esc}\""
    printf "mirror --reverse --parallel=4 --only-newer --exclude-glob-from=\"%s\" " "$exclude_esc"
    if [[ "$DELETE_MODE" -eq 1 ]]; then
      printf -- "--delete "
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
      printf -- "--dry-run "
    fi
    printf "\"%s\" \"%s\"\n" "$project_esc" "$remote_esc"
    echo "bye"
  } > "$lftp_script"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "Mode: full mirror dry-run"
  else
    echo "Mode: full mirror upload"
  fi

  lftp -f "$lftp_script"
  echo "Deploy finished."
  exit 0
fi

UPLOADS=()
for file in "${FILES[@]}"; do
  rel="$(normalize_rel "$file")"
  [[ -z "$rel" ]] && continue
  matches_ignore "$rel" && continue

  if [[ -f "$PROJECT_ROOT/$rel" ]]; then
    UPLOADS+=("$rel")
  else
    echo "Skip missing file: $rel" >&2
  fi
done

if [[ ${#UPLOADS[@]} -eq 0 ]]; then
  echo "No files to upload."
  exit 0
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Mode: selected files dry-run"
  for rel in "${UPLOADS[@]}"; do
    echo "[UPLOAD] $rel"
  done
  exit 0
fi

lftp_script="$(mktemp -t lftp-script.XXXXXX)"
trap 'rm -f "$lftp_script"' EXIT

{
  echo "set cmd:fail-exit yes"
  echo "set sftp:auto-confirm yes"
  echo "set net:max-retries 2"
  echo "set net:reconnect-interval-base 5"
  echo "set net:reconnect-interval-max 20"
  echo "open -u \"${user_esc}\",\"${pass_esc}\" -p ${port} \"sftp://${host_esc}\""
  echo "cd \"${remote_esc}\""
  echo "lcd \"${project_esc%/}\""

  for rel in "${UPLOADS[@]}"; do
    rel_esc="$(escape_lftp "$rel")"
    dir="$(dirname "$rel")"
    dir_esc="$(escape_lftp "$dir")"
    echo "put -O \"${dir_esc}\" \"${rel_esc}\""
  done

  echo "bye"
} > "$lftp_script"

echo "Mode: selected files upload (${#UPLOADS[@]})"
lftp -f "$lftp_script"
echo "Deploy finished."
