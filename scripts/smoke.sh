#!/usr/bin/env bash
#
# Proves the wiring no unit test can see, against the throwaway instance
# (compose.dev.yaml — `make instance-up` first; epi's smoke doctrine, restated:
# these assertions need a provisioned Nextcloud, an admin user and an app
# password, which is a fixture an order of magnitude heavier than phpunit,
# for checks whose value is precisely that they run against a REAL instance):
#
#   - the OCS route answers 412 without the OCS-APIRequest header and 200
#     with it — the header IS the CSRF protection, the in-container verdict
#     the design recorded, asserted here because only a real request carries
#     it
#   - the write path works over OCS with no CSRF token — POST creates
#     through the same door the sidebar uses, the exact promise the verified
#     verdict made for farmacia's first OCS writes
#   - the contract refuses what it cannot understand (400 gestación, 404
#     categoría) rather than answering 200-empty
#   - the icon serves, the .htaccess holes stay shut, the bundle builds, and
#     openapi.json regenerates without drift
#
# Output of every command is captured before it is matched, never piped into
# `grep -q` (epi's SIGPIPE lesson), and every curl carries --max-time — nothing
# behind these URLs can take minutes (all reads are local, no foreign feed
# walks like the 363 s one epi's --max-time 600 exists for), so 30 s is
# already generous.
#
# Usage: scripts/smoke.sh     (override with FARMACIA_CONTAINER=… FARMACIA_DB=… FARMACIA_USER=…)
set -uo pipefail

CONTAINER="${FARMACIA_CONTAINER:-farmacia-dev-nextcloud-1}"
DB_CONTAINER="${FARMACIA_DB:-farmacia-dev-db-1}"
USER_ID="${FARMACIA_USER:-admin}"
APP=farmacia
OCS="http://localhost/ocs/v2.php/apps/$APP/api/v1/medicamentos"
OCSH="OCS-APIRequest: true"
TOKEN_NAME=farmacia-smoke

# stderr is KEPT, not discarded (epi's B-003 lesson: a container that is down
# or an occ that errored must not read as "the app does not show as enabled").
occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }

# The credential arrives on STDIN, as a curl config file, so it is never a
# command-line argument or the environment — both are readable from /proc
# for the length of the run (epi's lesson, kept word for word).
web() {
	printf 'user = "%s:%s"\n' "$USER_ID" "$TOKEN" \
		| docker exec -i -u www-data "$CONTAINER" curl -sS --max-time 30 -K - "$@" 2>/dev/null
}

failures=0
pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
# $1 haystack, $2 needle, $3 message on success, $4 message on failure
# A failing check names what it actually got: CI's throwaway instance cannot be
# poked afterwards, and four FAILs that only restate the expectation cost a
# whole diagnosis round (2026-09-27). 200 bytes, one line — enough to see a
# status code or the head of a body, never the whole envelope.
expect() {
	case "$1" in
		*"$2"*) pass "$3" ;;
		*) fail "$4 — got: $(printf '%s' "$1" | head -c 200 | tr '\n' ' ')" ;;
	esac
}

# Revoke the throwaway credential however we leave.
cleanup() {
	[ -n "${TOKEN_ID:-}" ] && occ user:auth-tokens:delete "$USER_ID" "$TOKEN_ID" >/dev/null 2>&1
	return 0
}
trap cleanup EXIT

printf '\nFarmacia — checking %s\n\n' "$CONTAINER"

occ app:enable "$APP" >/dev/null 2>&1
expect "$(occ app:list --enabled)" " $APP:" \
	"the app is installed and enabled" \
	"the app does not show as enabled"

# A throwaway app password, minted and revoked here, so running this needs
# no credentials from whoever runs it. tail, not head — epi's lesson: earlier
# runs may have left rows of the same name, and deleting the oldest of those
# would leak a credential on every run.
TOKEN="$(occ user:add-app-password "$USER_ID" --name="$TOKEN_NAME" -n | tail -1 | tr -d '\r')"
TOKEN_ID="$(occ user:auth-tokens:list "$USER_ID" | grep -F "| $TOKEN_NAME " | tail -1 \
	| awk -F'|' '{gsub(/[^0-9]/, "", $2); print $2}')"
if [ -z "$TOKEN" ]; then
	fail "could not create an app password for $USER_ID"
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi

# --- the header is the protection ------------------------------------------
# 412 is Nextcloud refusing before the controller runs; a 200 here means the
# OCS protection is not in effect at all.
expect "$(web -o /dev/null -w '%{http_code}' "$OCS?format=json")" '412' \
	"without the OCS-APIRequest header the API answers 412" \
	"without the OCS-APIRequest header the API does NOT answer 412"

list="$(web -H "$OCSH" "$OCS?format=json")"
# `"status":"ok"`, not `"ocs"`: an OCS FAILURE envelope (998 route-not-found)
# also says "ocs" and carries "data":[] — this check and the next passed on
# exactly the 404 they exist to catch (2026-09-27).
expect "$list" '"status":"ok"' \
	"the response comes wrapped in the OCS envelope" \
	"the response does NOT carry the OCS envelope"
# The shape, not the count: a fresh seed carries zero medications, and a
# smoke that demanded rows would fail on the very instance it is meant to
# prove. An object here (not an array) is the list route drifting into a
# keyed shape — the failure the epi route's own history records once.
expect "$list" '"data":[' \
	"the arsenal answers as an array (empty on a fresh seed — the shape is the contract)" \
	"the list did not answer as an array"

# --- the narrowing refuses what it cannot understand -----------------------
# Asserted on the STATUS, not the body (epi's lesson: a body check on "does
# not contain" passes against a 500 page, an empty answer and a hang alike).
expect "$(web -H "$OCSH" -o /dev/null -w '%{http_code}' "$OCS?format=json&gestacion=Q")" '400' \
	"a gestación outside A–X answers 400" \
	"a gestación outside A–X does NOT answer 400 — a silent 200-empty is the failure this exists to catch"

expect "$(web -H "$OCSH" -o /dev/null -w '%{http_code}' "$OCS?format=json&categoria=no-existe")" '404' \
	"an unknown categoría slug answers 404" \
	"an unknown categoría slug does NOT answer 404"

# --- the write path: no CSRF token, only the header -------------------------
# One real category id, read from the seed the only honest way — the API does
# not serve the vocabulary, so the smoke reads what it needs from the
# throwaway's own database.
CATEGORY_ID="$(docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
	'select id from oc_farmacia_category order by id limit 1' | tr -d '[:space:]')"
if [ -z "$CATEGORY_ID" ]; then
	fail "no category in farmacia_category — the seed did not run"
else
	pass "the seed serves category id $CATEGORY_ID"
fi

# The código is unique per run: the unique index is the one refusal a re-run
# would otherwise hit, and the throwaway keeps the row — instance-clean wipes
# it, which is the trade for proving the write end to end.
CODIGO="farmacia-smoke-$(date +%s)"
code="$(web -H "$OCSH" -o /dev/null -w '%{http_code}' \
	-d "codigo=$CODIGO" -d 'producto=Prueba de humo' -d "categoryId=$CATEGORY_ID" \
	"$OCS?format=json")"
expect "$code" '201' \
	"POST creates over OCS with no CSRF token — only the header" \
	"POST answered $code — the header-as-CSRF verdict or the write path broke"

expect "$(web -H "$OCSH" "$OCS?format=json&search=prueba+de+humo")" "$CODIGO" \
	"the created medication answers to ?search= (accent-folded, like the app's own search)" \
	"the created medication did not answer to ?search="

# --- the icon ----------------------------------------------------------------
# The navigation endpoint reports a path whether or not the file exists; what
# matters is that the path actually serves an SVG, or the menu falls back to
# core's generic placeholder. app.svg is the pair's AppIconTest-pinned name.
icon="$(web -o /dev/null -w '%{http_code} %{content_type} %{size_download}' \
	"http://localhost/custom_apps/$APP/img/app.svg")"
case "$icon" in
	'200 image/svg+xml'*) pass "the app icon is served ($icon)" ;;
	*) fail "the icon is not served ($icon) — the menu would show the generic placeholder" ;;
esac

# --- what Apache hands to ANYONE --------------------------------------------
# Answered before Nextcloud is involved, so measured with no credentials at
# all (epi's lesson: an authenticated probe would pass while the hole was
# open). openapi.json joins the list the day it exists: epi denies its own
# spec, and a published contract is repo material, not webroot material.
for path in package.json openapi.json src/payload.js scripts/smoke.sh; do
	code="$(docker exec -u www-data "$CONTAINER" \
		curl -sS --max-time 30 -o /dev/null -w '%{http_code}' "http://localhost/custom_apps/$APP/$path")"
	case "$code" in
		404|403) pass "$path is not served to anonymous callers" ;;
		000) fail "$path did not answer within 30 s — that is the instance, not the .htaccess" ;;
		*) fail "$path answers $code with NO credentials — check .htaccess" ;;
	esac
done

# --- the bundle is the deployment --------------------------------------------
# js/ is built, not committed (the release packs it), so the failure this guards
# is a build that produces no bundle at all — the same refusal pack-app makes, and
# CI's js job runs the same assertion. CI runs this same check and does NOT skip
# (#39): the skip triggers exactly where the check is most needed — a fresh clone
# with no node_modules. This copy stays for the local answer.
if command -v npm >/dev/null 2>&1 && [ -d node_modules ]; then
	npm run build --silent >/dev/null 2>&1
	if [ -s "js/$APP-main.js" ] && [ -s "js/$APP-main.js.LICENSE.txt" ]; then
		pass "the build produces the js/ bundle"
	else
		fail "npm run build produced no js/$APP-main.js — the release packer would refuse this tree"
	fi
else
	printf '  --    no node_modules: not checking whether the bundle builds\n'
fi

# --- the contract is generated, not hand-edited ------------------------------
# Regen-drift is checked here and nowhere else (MetadataTest pins the version
# and description it declares, but only a regeneration can prove the REST of
# the spec still matches the controller that claims to serve it). A failed
# regeneration is NOT a clean tree: openapi.sh exits non-zero and leaves the
# old file in place, which would read as "no drift" if the exit were ignored
# — so it is checked first, and named.
if scripts/openapi.sh >/dev/null 2>&1; then
	drift="$(git status --porcelain openapi.json 2>/dev/null)"
	if [ -z "$drift" ]; then
		pass "openapi.json regenerates without drift"
	else
		fail "openapi.json drifted from ApiController — run scripts/openapi.sh and commit the result"
		printf '%s\n' "$drift" | sed 's/^/        /'
	fi
else
	fail "scripts/openapi.sh did not run — the extractor needs the instance up (and, on a first run, the network); a contract that cannot regenerate cannot be checked"
fi

if [ "$failures" -eq 0 ]; then
	printf '\nAll good.\n\n'
else
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi
