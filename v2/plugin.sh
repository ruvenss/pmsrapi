#!/usr/bin/env bash
#
# plugin.sh — PMSRAPI v2 plugin wizard.
#
# A friendly scaffolder so anyone can build a plugin without touching the core.
#
#   bash v2/plugin.sh            # or: new   — interactive: create a plugin
#   bash v2/plugin.sh list                   — list installed plugins
#   bash v2/plugin.sh check <Name>           — lint/validate one plugin
#   bash v2/plugin.sh remove <Name>          — delete a plugin (asks first)
#   bash v2/plugin.sh help
#
# Everything it creates lives under v2/plugins/<Name>/ and is picked up
# automatically on the next request — no core files are edited, ever.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$SCRIPT_DIR/plugins"

# ---- pretty output (plain when not a TTY) ---------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GRN=$'\033[32m'
    YLW=$'\033[33m'; CYN=$'\033[36m'; RST=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GRN=''; YLW=''; CYN=''; RST=''
fi
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s✓%s %s\n' "$GRN" "$RST" "$*"; }
warn() { printf '%s!%s %s\n' "$YLW" "$RST" "$*"; }
err()  { printf '%s✗%s %s\n' "$RED" "$RST" "$*" >&2; }
die()  { err "$*"; exit 1; }
hr()   { printf '%s%s%s\n' "$DIM" "──────────────────────────────────────────────" "$RST"; }

need_php() { command -v php >/dev/null 2>&1 || die "PHP is not on your PATH — install PHP 8.3+ first."; }

# ---- tiny prompt helpers (bash 3.2 compatible) ----------------------------
ask() { # ask <varname> <prompt> [default]
    local __var="$1" __prompt="$2" __def="${3:-}" __ans=''
    if [ -n "$__def" ]; then
        printf '%s%s%s %s(%s)%s: ' "$BOLD" "$__prompt" "$RST" "$DIM" "$__def" "$RST"
    else
        printf '%s%s%s: ' "$BOLD" "$__prompt" "$RST"
    fi
    IFS= read -r __ans || __ans=''
    [ -z "$__ans" ] && __ans="$__def"
    printf -v "$__var" '%s' "$__ans"
}
confirm() { # confirm <prompt> <default y|n> -> exit status
    local __p="$1" __d="${2:-n}" __a='' __hint='[y/N]'
    [ "$__d" = 'y' ] && __hint='[Y/n]'
    printf '%s%s%s %s: ' "$BOLD" "$__p" "$RST" "$__hint"
    IFS= read -r __a || __a=''
    [ -z "$__a" ] && __a="$__d"
    case "$__a" in [Yy]*) return 0 ;; *) return 1 ;; esac
}
sanitize() { printf '%s' "$1" | tr -d '\\"`'; }   # strip chars that break JSON/PHP

# ---- template renderer: literal placeholder substitution (no sed pitfalls) --
render() { # render <dest-file>  ; template on stdin, placeholders __NAME__ etc.
    local dest="$1" tpl
    tpl="$(cat)"
    tpl="${tpl//__NAME__/$NAME}"
    tpl="${tpl//__SLUG__/$SLUG}"
    tpl="${tpl//__DESC__/$DESC}"
    tpl="${tpl//__AUTHOR__/$AUTHOR}"
    tpl="${tpl//__VERSION__/$VERSION}"
    printf '%s\n' "$tpl" > "$dest"
}

# ---------------------------------------------------------------------------
# new
# ---------------------------------------------------------------------------
cmd_new() {
    need_php
    mkdir -p "$PLUGINS_DIR"

    say ''
    printf '%s╭──────────────────────────────────────────────╮%s\n' "$CYN" "$RST"
    printf '%s│      PMSRAPI v2 · new plugin wizard           │%s\n' "$CYN" "$RST"
    printf '%s╰──────────────────────────────────────────────╯%s\n' "$CYN" "$RST"
    say "${DIM}A plugin is a self-contained folder of your own controllers,${RST}"
    say "${DIM}models and routes. The core stays untouched.${RST}"
    say ''

    # --- name (validated + normalized) ---
    local first rest
    while :; do
        ask NAME "Plugin name  ${DIM}PascalCase, e.g. Billing${RST}"
        NAME="$(printf '%s' "$NAME" | tr -d ' ')"
        if ! printf '%s' "$NAME" | grep -Eq '^[A-Za-z][A-Za-z0-9]*$'; then
            err "Use letters and digits only, starting with a letter."
            continue
        fi
        first="$(printf '%s' "$NAME" | cut -c1 | tr '[:lower:]' '[:upper:]')"
        rest="$(printf '%s' "$NAME" | cut -c2-)"
        NAME="${first}${rest}"
        SLUG="$(printf '%s' "$NAME" | tr '[:upper:]' '[:lower:]')"
        case " info health webhooks _debug capabilities stream hive " in
            *" $SLUG "*) err "'/$SLUG' is a reserved core prefix — pick another name."; continue ;;
        esac
        if [ -d "$PLUGINS_DIR/$NAME" ]; then
            err "Plugin '$NAME' already exists at v2/plugins/$NAME."
            continue
        fi
        break
    done

    # --- metadata ---
    local git_author
    git_author="$(git config user.name 2>/dev/null || true)"
    ask DESC    "Short description" "A ${NAME} plugin for PMSRAPI v2."
    ask AUTHOR  "Author"            "${git_author:-PMSRAPI}"
    ask VERSION "Version"           "0.1.0"
    DESC="$(sanitize "$DESC")"; AUTHOR="$(sanitize "$AUTHOR")"; VERSION="$(sanitize "$VERSION")"

    local with_db='no'
    if confirm "Include a database example (uses the shared core Repository)?" "n"; then
        with_db='yes'
    fi

    # --- scaffold ---
    local dir="$PLUGINS_DIR/$NAME"
    mkdir -p "$dir/src/Controllers" "$dir/assets"

    render "$dir/plugin.json" <<'EOF'
{
    "name": "__NAME__",
    "slug": "__SLUG__",
    "description": "__DESC__",
    "version": "__VERSION__",
    "author": "__AUTHOR__",
    "enabled": true,
    "dependencies": []
}
EOF

    if [ "$with_db" = 'yes' ]; then
        render "$dir/src/Controllers/${NAME}Controller.php" <<'EOF'
<?php

declare(strict_types=1);

namespace Plugins\__NAME__\Controllers;

use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\DatabaseException;
use Pmsrapi\V2\Http\Response;

final class __NAME__Controller
{
    // The core Repository is injected — prepared statements + schema whitelisting
    // for free. NEVER open your own DB connection from a plugin.
    public function __construct(
        private readonly Repository $repository,
    ) {}

    public function ping(): Response
    {
        return Response::ok(['plugin' => '__SLUG__', 'status' => 'ok']);
    }

    /**
     * GET /v2/__SLUG__/count/{table}
     * Demonstrates a safe read through the shared Repository. Before shipping,
     * restrict which tables callers may address (whitelist $table).
     */
    public function count(string $table): Response
    {
        try {
            return Response::ok([
                'table' => $table,
                'count' => $this->repository->count($table),
            ]);
        } catch (DatabaseException $e) {
            return Response::error(404, ['code' => 'unknown_table', 'message' => $e->getMessage()]);
        }
    }
}
EOF
        render "$dir/src/${NAME}Plugin.php" <<'EOF'
<?php

declare(strict_types=1);

namespace Plugins\__NAME__;

use Plugins\__NAME__\Controllers\__NAME__Controller;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

/**
 * __DESC__
 *
 * Routes are auto-prefixed with this plugin's slug, so they live under
 * /v2/__SLUG__/… — no chance of colliding with the core or another plugin.
 */
final class __NAME__Plugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            __NAME__Controller::class,
            static fn(Container $c): __NAME__Controller
                => new __NAME__Controller($c->get(Repository::class)),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        // GET /v2/__SLUG__/ping
        $router->get('/ping', static fn(Request $r, array $p): Response
            => $container->get(__NAME__Controller::class)->ping());

        // GET /v2/__SLUG__/count/{table}
        $router->get('/count/{table}', static fn(Request $r, array $p): Response
            => $container->get(__NAME__Controller::class)->count($p['table']));
    }
}
EOF
    else
        render "$dir/src/Controllers/${NAME}Controller.php" <<'EOF'
<?php

declare(strict_types=1);

namespace Plugins\__NAME__\Controllers;

use Pmsrapi\V2\Http\Response;

final class __NAME__Controller
{
    public function ping(): Response
    {
        return Response::ok(['plugin' => '__SLUG__', 'status' => 'ok']);
    }
}
EOF
        render "$dir/src/${NAME}Plugin.php" <<'EOF'
<?php

declare(strict_types=1);

namespace Plugins\__NAME__;

use Plugins\__NAME__\Controllers\__NAME__Controller;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

/**
 * __DESC__
 *
 * Routes are auto-prefixed with this plugin's slug, so they live under
 * /v2/__SLUG__/… — no chance of colliding with the core or another plugin.
 */
final class __NAME__Plugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        // Register your own services here. Resolve core services (Connection,
        // Repository, Logger, …) via $c inside the closure — never build your own.
        $registrar->singleton(
            __NAME__Controller::class,
            static fn(Container $c): __NAME__Controller => new __NAME__Controller(),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        // GET /v2/__SLUG__/ping
        $router->get('/ping', static fn(Request $r, array $p): Response
            => $container->get(__NAME__Controller::class)->ping());
    }
}
EOF
    fi

    render "$dir/README.md" <<'EOF'
# __NAME__ plugin

__DESC__

- **Version:** __VERSION__
- **Author:** __AUTHOR__
- **URL prefix:** `/v2/__SLUG__/…`

## Endpoints

- `GET /v2/__SLUG__/ping` — health check for this plugin.

## Develop

Add routes in `src/__NAME__Plugin.php`, controllers under `src/Controllers/`,
and any models/helpers under `src/`. Everything autoloads as
`Plugins\__NAME__\…`. Validate anytime with:

```bash
bash v2/plugin.sh check __NAME__
```

Disable without deleting by setting `"enabled": false` in `plugin.json`.
EOF

    printf 'assets/ holds this plugin'"'"'s static files (read-only).\n' > "$dir/assets/.gitkeep"

    # --- validate what we generated ---
    hr
    local bad=0 f
    while IFS= read -r f; do
        if php -l "$f" >/dev/null 2>&1; then
            ok "lint ${f#"$SCRIPT_DIR/"}"
        else
            err "lint failed: ${f#"$SCRIPT_DIR/"}"; php -l "$f" || true; bad=1
        fi
    done < <(find "$dir" -name '*.php')
    [ "$bad" -eq 0 ] || die "Generated files failed to lint — please report this."

    # --- success ---
    say ''
    ok "${BOLD}Plugin '${NAME}' created${RST} at v2/plugins/${NAME}/"
    say ''
    say "${BOLD}Next steps${RST}"
    say "  1. Start the service:   ${CYN}php -S 0.0.0.0:8000 v2/server.php${RST}"
    say "  2. Call your endpoint:"
    say "     ${CYN}curl -H 'Authorization: Bearer <ms_server_token>' \\"
    say "          http://localhost:8000/v2/${SLUG}/ping${RST}"
    say "  3. Edit ${CYN}v2/plugins/${NAME}/src/${NAME}Plugin.php${RST} to add routes."
    say ''
    say "${DIM}It is already active — discovery is automatic. No core files were changed.${RST}"
}

# ---------------------------------------------------------------------------
# list
# ---------------------------------------------------------------------------
cmd_list() {
    need_php
    [ -d "$PLUGINS_DIR" ] || { warn "No plugins directory yet."; return 0; }

    local found=0 d name slug meta ver status desc
    printf '%s%-18s %-14s %-9s %-9s %s%s\n' "$BOLD" "NAME" "PREFIX" "VERSION" "STATUS" "DESCRIPTION" "$RST"
    hr
    for d in "$PLUGINS_DIR"/*/; do
        [ -d "$d" ] || continue
        name="$(basename "$d")"
        [ -f "$d/plugin.json" ] || [ -f "$d/src/${name}Plugin.php" ] || continue
        found=1
        slug="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
        meta="$(php -r '
            $f=$argv[1];
            $d=is_file($f)?json_decode((string)file_get_contents($f),true):[];
            $d=is_array($d)?$d:[];
            printf("%s|%s|%s",
                $d["version"]??"?",
                (array_key_exists("enabled",$d)&&$d["enabled"]===false)?"disabled":"enabled",
                $d["description"]??"");
        ' "$d/plugin.json" 2>/dev/null || printf '?|enabled|')"
        ver="${meta%%|*}"; meta="${meta#*|}"; status="${meta%%|*}"; desc="${meta#*|}"
        if [ "$status" = 'disabled' ]; then
            printf '%s%-18s /%-13s %-9s %-9s %s%s\n' "$DIM" "$name" "$slug" "$ver" "$status" "$desc" "$RST"
        else
            printf '%-18s %s/%-13s%s %-9s %s%-9s%s %s\n' "$name" "$CYN" "$slug" "$RST" "$ver" "$GRN" "$status" "$RST" "$desc"
        fi
    done
    [ "$found" -eq 1 ] || say "${DIM}(none installed — run 'bash v2/plugin.sh new')${RST}"
}

# ---------------------------------------------------------------------------
# check <Name>
# ---------------------------------------------------------------------------
cmd_check() {
    need_php
    local name="${1:-}"
    [ -n "$name" ] || die "Usage: plugin.sh check <Name>"
    local dir="$PLUGINS_DIR/$name"
    [ -d "$dir" ] || die "No plugin '$name' at v2/plugins/$name."

    local problems=0
    say "Checking ${BOLD}${name}${RST} …"
    hr

    if [ -f "$dir/plugin.json" ]; then
        if php -r 'exit(json_validate((string)file_get_contents($argv[1]))?0:1);' "$dir/plugin.json" 2>/dev/null; then
            ok "plugin.json is valid JSON"
        else
            err "plugin.json is not valid JSON"; problems=1
        fi
    else
        warn "no plugin.json (optional, but recommended)"
    fi

    if [ -f "$dir/src/${name}Plugin.php" ]; then
        ok "entry class file src/${name}Plugin.php present"
    else
        err "missing entry class: src/${name}Plugin.php must define Plugins\\${name}\\${name}Plugin"
        problems=1
    fi

    local f
    while IFS= read -r f; do
        if php -l "$f" >/dev/null 2>&1; then
            ok "lint ${f#"$dir/"}"
        else
            err "lint failed ${f#"$dir/"}"; php -l "$f" || true; problems=1
        fi
    done < <(find "$dir" -name '*.php')

    hr
    if [ "$problems" -eq 0 ]; then
        ok "${GRN}${BOLD}${name} looks good.${RST}"
    else
        die "${name} has problems (see above)."
    fi
}

# ---------------------------------------------------------------------------
# remove <Name>
# ---------------------------------------------------------------------------
cmd_remove() {
    local name="${1:-}"
    [ -n "$name" ] || die "Usage: plugin.sh remove <Name>"
    local dir="$PLUGINS_DIR/$name"
    [ -d "$dir" ] || die "No plugin '$name' at v2/plugins/$name."

    warn "This will permanently delete ${BOLD}v2/plugins/${name}/${RST} and everything in it."
    local typed=''
    ask typed "Type the plugin name to confirm"
    if [ "$typed" != "$name" ]; then
        say "Aborted — nothing was deleted."
        return 0
    fi
    rm -rf "$dir"
    ok "Removed plugin '$name'."
}

# ---------------------------------------------------------------------------
usage() {
    cat <<EOF
${BOLD}PMSRAPI v2 · plugin wizard${RST}

  ${CYN}bash v2/plugin.sh${RST} ${DIM}[command]${RST}

Commands:
  ${BOLD}new${RST}            Create a new plugin (interactive). Default if omitted.
  ${BOLD}list${RST}           List installed plugins and their status.
  ${BOLD}check${RST} <Name>   Validate & lint a plugin.
  ${BOLD}remove${RST} <Name>  Delete a plugin (asks for confirmation).
  ${BOLD}help${RST}           Show this help.

Plugins live in v2/plugins/<Name>/ and load automatically. The core is never edited.
EOF
}

main() {
    local cmd="${1:-new}"
    case "$cmd" in
        new)            shift || true; cmd_new "$@" ;;
        list|ls)        shift || true; cmd_list "$@" ;;
        check|validate) shift || true; cmd_check "${1:-}" ;;
        remove|rm|delete) shift || true; cmd_remove "${1:-}" ;;
        help|-h|--help) usage ;;
        *) err "Unknown command: $cmd"; say ''; usage; exit 1 ;;
    esac
}

main "$@"
