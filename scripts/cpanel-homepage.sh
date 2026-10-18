#!/bin/bash
set -euo pipefail

deploy_dir="${1:?Pass the website document root}"
htaccess="$deploy_dir/.htaccess"
marker='# BEGIN ZAPEKS HOMEPAGE'

if [[ ! -d "$deploy_dir" || ! -f "$deploy_dir/index.html" || ! -f "$deploy_dir/about.html" ]]; then
  printf 'The Zapeks HTML pages must be deployed before setting homepage priority.\n' >&2
  exit 1
fi

if [[ -f "$htaccess" ]] && grep -Fxq "$marker" "$htaccess"; then
  printf 'Zapeks homepage priority is already configured.\n'
  exit 0
fi

if [[ -e "$htaccess" ]]; then
  backup_dir="${ZAPEKS_BACKUP_DIR:-${HOME:?}/.zapeks-deploy-backups}"
  backup_file="$backup_dir/htaccess-$(date -u +%Y%m%dT%H%M%SZ)-$$.bak"
  (umask 077; mkdir -p "$backup_dir"; cp "$htaccess" "$backup_file")
  printf 'Existing .htaccess backed up to %s\n' "$backup_file"
fi

# Reset the inherited index list after existing rules without replacing them.
printf '\n%s\nDirectoryIndex disabled\nDirectoryIndex index.html index.php\n# END ZAPEKS HOMEPAGE\n' "$marker" >> "$htaccess"
printf 'Zapeks index.html now takes priority over index.php.\n'
