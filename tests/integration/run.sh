#!/usr/bin/env bash
# BackupDAV end-to-end test against the nextcloud-config-server dev stack (S3 primary storage).
#
# Prerequisites: stack running from nextcloud-config-server branch feat/backupdav-dev,
# host tools docker, curl, rclone, python3, sha256sum.
# Creates bd-* users, groups and group folders; removes them on exit.
# Brute-force protection and the userlimit cap (config-server) are switched off during the run and
# restored afterwards.
#
# Env: BASE_URL (default http://nextcloud.localhost:7689), NC_CONTAINER, DB_CONTAINER,
#      BIG_MB (large test file size in MiB, default 256).
set -euo pipefail

BASE_URL="${BASE_URL:-http://nextcloud.localhost:7689}"
NC="${NC_CONTAINER:-$(docker ps --format '{{.Names}}' | grep -m1 '^nextcloud-app-' || true)}"
DB="${DB_CONTAINER:-$(docker ps --format '{{.Names}}' | grep -m1 '^nextcloud-db-' || true)}"
BIG_MB="${BIG_MB:-256}"
DAV="$BASE_URL/remote.php/backupdav"
if [ -z "$NC" ] || [ -z "$DB" ]; then
    echo "Nextcloud or database container not found: is the dev stack up?" >&2
    exit 2
fi

WORK="$(mktemp -d)"
PW="Bd-$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
USERS=(bd-alice bd-bob "bd-carol x@y.z")
GF_NAME=bd-gf
GF_ORPHAN=bd-gf-orphan
GF_ID=""
GF_ORPHAN_ID=""
SECRET=""
SECRETS=()
PREV_BF=""
PREV_LIMIT=""
FAILS=0

occ() { docker exec -u www-data "$NC" php occ "$@"; }
urlenc() { python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
pass() { echo "ok    $*"; }
fail() { echo "FAIL  $*"; FAILS=$((FAILS + 1)); }
check() { local name="$1"; shift; if "$@"; then pass "$name"; else fail "$name"; fi; }
quiet() { "$@" >/dev/null 2>&1; }
refused() { ! "$@" >/dev/null 2>&1; }
# expect <name> <status regex> <curl args...>
# Retries for up to 10 s: web workers cache app config for 3 s, so occ changes show up within seconds.
expect() {
    local name="$1" want="$2" got i
    shift 2
    for i in $(seq 1 20); do
        got="$(curl -s -o /dev/null -w '%{http_code}' "$@")"
        if [[ "$got" =~ ^($want)$ ]]; then pass "$name ($got)"; return; fi
        sleep 0.5
    done
    fail "$name: want $want, got $got"
}
svc() { curl -s -u "backup-service:$SECRET" "$@"; }
# nc_dav <uid> <curl args...> <path in the user's files>: standard Nextcloud WebDAV, as that user
nc_dav() {
    local uid="$1" path="${*: -1}"
    set -- "${@:2:$#-2}"
    curl -fsS -o /dev/null -u "$uid:$PW" "$@" "$BASE_URL/remote.php/dav/files/$(urlenc "$uid")/$(urlenc "${path#/}")"
}
# upload_tree <uid> <local dir> <remote prefix>
upload_tree() {
    local uid="$1" dir="$2" prefix="$3" rel
    while IFS= read -r -d '' rel; do
        nc_dav "$uid" -X MKCOL "$prefix/${rel#./}"
    done < <(cd "$dir" && find . -mindepth 1 -type d -print0 | sort -z)
    while IFS= read -r -d '' rel; do
        nc_dav "$uid" -T "$dir/${rel#./}" "$prefix/${rel#./}"
    done < <(cd "$dir" && find . -type f -print0 | sort -z)
}
put() { mkdir -p "$(dirname "$1")"; printf '%s' "$2" > "$1"; }
sums() { (cd "$1" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum); }
dirs() { (cd "$1" && find . -type d | sort); }
prop() { svc -X PROPFIND -H 'Depth: 0' "$DAV/$(urlenc "$2")" | grep -o "<d:$1>[^<]*" | cut -d'>' -f2; }
ghost_storages() {
    docker exec "$DB" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$0"' \
        "SELECT count(*) FROM oc_storages WHERE id LIKE '%bd-ghost%'"
}
# ids of this test's group folders (groupfolders 23: JSON list, key "mountPoint")
group_folder_ids() {
    occ groupfolders:list --output=json | python3 -c '
import json, sys
for f in json.load(sys.stdin):
    if f["mountPoint"] in sys.argv[1:]:
        print(f["id"])' "$GF_NAME" "$GF_ORPHAN"
}
# copy: export the test subset with the README flags; prints the number of files transferred, -1 on error
copy() {
    : > "$WORK/rclone.log"
    rclone copy bd: "$WORK/dest" \
        --include "/users/bd-alice/**" --include "/users/bd-bob/**" --include "/users/bd-carol x@y.z/**" \
        --include "/groupfolders/$GF_ID/**" \
        --create-empty-src-dirs --transfers 8 --checkers 16 -v --log-file "$WORK/rclone.log" || { echo -1; return; }
    # "Copied (new)", or "Multi-thread Copied (new)" for files above rclone's --multi-thread-cutoff (256 MiB)
    grep -c 'Copied (' "$WORK/rclone.log" || true
}
cleanup() {
    set +e
    for id in $(group_folder_ids 2>/dev/null); do occ groupfolders:delete -f "$id" >/dev/null 2>&1; done
    for u in "${USERS[@]}" bd-ghost; do occ user:delete "$u" >/dev/null 2>&1; done
    occ group:delete bd-group >/dev/null 2>&1
    occ group:delete bd-orphans >/dev/null 2>&1
    occ backupdav:disable >/dev/null 2>&1
    occ backupdav:credentials:revoke >/dev/null 2>&1
    occ config:app:delete backupdav allowed_ips >/dev/null 2>&1
    if [ -n "$PREV_BF" ]; then
        occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value="$PREV_BF" >/dev/null 2>&1
    else
        occ config:system:delete auth.bruteforce.protection.enabled >/dev/null 2>&1
    fi
    [ -z "$PREV_LIMIT" ] || occ config:app:set userlimit limit --value="$PREV_LIMIT" >/dev/null 2>&1
    rm -rf "$WORK"
}

echo "== setup"
PREV_BF="$(occ config:system:get auth.bruteforce.protection.enabled 2>/dev/null || true)"
PREV_LIMIT="$(occ config:app:get userlimit limit 2>/dev/null || true)"
trap cleanup EXIT
[ -z "$PREV_LIMIT" ] || occ config:app:set userlimit limit --value=0 >/dev/null
for u in "${USERS[@]}" bd-ghost; do occ user:delete "$u" >/dev/null 2>&1 || true; done
for id in $(group_folder_ids); do occ groupfolders:delete -f "$id" >/dev/null; done
occ backupdav:credentials:revoke >/dev/null 2>&1 || true
occ app:enable backupdav >/dev/null
occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false >/dev/null
objectstore="$(occ config:system:get objectstore class 2>/dev/null || true)"
check "primary storage is S3 ($objectstore)" grep -q S3 <<<"$objectstore"
check "remote handler registered" test "$(occ config:app:get core remote_backupdav)" = "backupdav/appinfo/remote.php"
expect "endpoint absent before a secret exists" 404 -X PROPFIND "$DAV/"

echo "== test data"
for u in "${USERS[@]}" bd-ghost; do
    docker exec -u www-data -e OC_PASS="$PW" "$NC" php occ user:add --password-from-env "$u" >/dev/null
done
occ group:add bd-group >/dev/null
occ group:adduser bd-group bd-alice
occ group:add bd-orphans >/dev/null
GF_ID="$(occ groupfolders:create "$GF_NAME" | tr -dc '0-9')"
occ groupfolders:group "$GF_ID" bd-group write >/dev/null
GF_ORPHAN_ID="$(occ groupfolders:create "$GF_ORPHAN" | tr -dc '0-9')"
occ groupfolders:group "$GF_ORPHAN_ID" bd-orphans write >/dev/null

SRC="$WORK/src"
A="$SRC/users/bd-alice"
B="$SRC/users/bd-bob"
C="$SRC/users/bd-carol x@y.z"
G="$WORK/gf"
put "$A/Documents/contrat.pdf" "contrat v1"
put "$A/Documents/facture.xlsx" "facture"
put "$A/Été 2026/Ünïcødé — spaced name.txt" "unicode"
put "$A/Specials/100% #1 & co; a+b=c 'q'.txt" "reserved characters"
put "$A/Specials/photo 📷.txt" "emoji"
put "$A/empty.txt" ""
mkdir -p "$A/Empty folder"
put "$A/Shared-with-bob/plan.txt" "shared by alice"
head -c "$((BIG_MB * 1024 * 1024))" /dev/urandom > "$A/big.bin"
put "$B/Projets/rapport.docx" "rapport"
put "$C/archive.zip" "archive"
put "$G/notes.txt" "group folder notes"
put "$G/sub/data.csv" "a,b"

upload_tree bd-alice "$A" ""
upload_tree bd-bob "$B" ""
upload_tree "bd-carol x@y.z" "$C" ""
upload_tree bd-alice "$G" "$GF_NAME"
mkdir -p "$SRC/groupfolders/$GF_ID"
cp -a "$G/." "$SRC/groupfolders/$GF_ID/"
curl -fsS -o /dev/null -u "bd-alice:$PW" -H 'OCS-APIRequest: true' -X POST \
    "$BASE_URL/ocs/v2.php/apps/files_sharing/api/v1/shares" \
    --data-urlencode path=/Shared-with-bob --data-urlencode shareType=0 --data-urlencode shareWith=bd-bob
check "bob sees alice's share in his own files" quiet nc_dav bd-bob -X PROPFIND Shared-with-bob
check "alice sees the group folder in her files" quiet nc_dav bd-alice -X PROPFIND "$GF_NAME"
# Advanced permissions: hide sub/ from its only member. The backup must still contain it.
occ groupfolders:permissions "$GF_ID" --enable >/dev/null
occ groupfolders:permissions "$GF_ID" sub -g bd-group -- -read >/dev/null
check "ACL hides sub/ from alice" refused nc_dav bd-alice -X PROPFIND "$GF_NAME/sub"
occ user:disable bd-bob >/dev/null
GHOST_BEFORE="$(ghost_storages)"

echo "== service identity"
SECRET="$(occ backupdav:credentials:generate | sed -n 's/^Secret: *//p')"
SECRETS+=("$SECRET")
check "secret is 64 hex characters" grep -Eqx '[0-9a-f]{64}' <<<"$SECRET"
check "second generate is refused" refused occ backupdav:credentials:generate
expect "secret set but endpoint disabled" 404 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
occ backupdav:enable >/dev/null
expect "endpoint live after enable" 207 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
status_out="$(occ backupdav:status)"
check "status reports live" grep -q live <<<"$status_out"
check "status never prints the secret" refused grep -qF "$SECRET" <<<"$status_out"
check "backup-service is not a Nextcloud user" refused occ user:info backup-service
export RCLONE_CONFIG_BD_TYPE=webdav RCLONE_CONFIG_BD_URL="$DAV/" RCLONE_CONFIG_BD_VENDOR=owncloud \
    RCLONE_CONFIG_BD_USER=backup-service RCLONE_CONFIG_BD_PASS="$(rclone obscure "$SECRET")"

echo "== listing"
check "root lists groupfolders/ and users/" test "$(rclone lsf bd: | sort | tr '\n' ' ')" = "groupfolders/ users/ "
users_list="$(rclone lsf bd:users)"
for u in bd-alice/ bd-bob/ "bd-carol x@y.z/"; do check "users/ lists $u" grep -qxF "$u" <<<"$users_list"; done
check "users/ hides the never-logged-in user" refused grep -qxF bd-ghost/ <<<"$users_list"
gf_list="$(rclone lsf bd:groupfolders)"
check "group folder listed by id" grep -qxF "$GF_ID/" <<<"$gf_list"
check "group folder without members is listed" grep -qxF "$GF_ORPHAN_ID/" <<<"$gf_list"
check "rclone ls walks the whole export" quiet rclone ls bd:

echo "== copy"
n="$(copy)"
check "first copy transfers every file ($n)" test "$n" -eq "$(find "$SRC" -type f | wc -l)"
check "contents identical (sha256)" diff <(sums "$SRC") <(sums "$WORK/dest")
check "directory tree identical, empty folder included" diff <(dirs "$SRC") <(dirs "$WORK/dest")
check "share exported only under its owner" test ! -e "$WORK/dest/users/bd-bob/Shared-with-bob"
check "group folder not duplicated under its members" test ! -e "$WORK/dest/users/bd-alice/$GF_NAME"
check "content hidden by ACL is still exported" test -f "$WORK/dest/groupfolders/$GF_ID/sub/data.csv"
check "disabled user still exported" test -f "$WORK/dest/users/bd-bob/Projets/rapport.docx"
check "no storage created for the never-logged-in user" test "$(ghost_storages)" = "$GHOST_BEFORE"
n="$(copy)"
check "unchanged second copy transfers nothing ($n)" test "$n" -eq 0

echo "== incremental"
etag0="$(prop getetag users/bd-alice/Documents/contrat.pdf)"
mtime0="$(prop getlastmodified users/bd-alice/Documents/contrat.pdf)"
sleep 2
put "$A/Documents/contrat.pdf" "contrat v2, longer"
put "$A/Documents/facture.xlsx" "FACTURE" # same size: only the mtime tells rclone it changed
nc_dav bd-alice -T "$A/Documents/contrat.pdf" Documents/contrat.pdf
nc_dav bd-alice -T "$A/Documents/facture.xlsx" Documents/facture.xlsx
check "ETag changed after modification" test "$(prop getetag users/bd-alice/Documents/contrat.pdf)" != "$etag0"
check "mtime changed after modification" test "$(prop getlastmodified users/bd-alice/Documents/contrat.pdf)" != "$mtime0"
n="$(copy)"
check "third copy transfers exactly the 2 modified files ($n)" test "$n" -eq 2
check "contents identical after the incremental copy" diff <(sums "$SRC") <(sums "$WORK/dest")

echo "== downloads"
size="$(stat -c %s "$A/big.bin")"
head_len="$(svc -I "$DAV/users/bd-alice/big.bin" | tr -d '\r' | sed -n 's/^[Cc]ontent-[Ll]ength: //p')"
check "HEAD returns the Content-Length" test "$head_len" = "$size"
svc -r 1000-1999 -o "$WORK/range.part" "$DAV/users/bd-alice/big.bin"
check "Range request returns exact bytes" cmp "$WORK/range.part" <(tail -c +1001 "$A/big.bin" | head -c 1000)
expect "Range request status" 206 -u "backup-service:$SECRET" -r 0-9 "$DAV/users/bd-alice/big.bin"
check "Depth: infinity is not honoured (depth 1 max)" \
    refused grep -q 'bd-alice/Documents' <<<"$(svc -X PROPFIND -H 'Depth: infinity' "$DAV/users/")"

echo "== security"
expect "no credentials" 401 -X PROPFIND "$DAV/"
expect "OPTIONS without credentials" 401 -X OPTIONS "$DAV/"
expect "wrong secret" 401 -u "backup-service:wrong" -X PROPFIND "$DAV/"
expect "wrong user" 401 -u "admin:$SECRET" -X PROPFIND "$DAV/"
check "OPTIONS advertises read-only methods" \
    grep -qix 'allow: OPTIONS, GET, HEAD, PROPFIND' <<<"$(svc -i -X OPTIONS "$DAV/" | tr -d '\r')"
for m in PUT DELETE MKCOL MOVE COPY PROPPATCH LOCK UNLOCK POST PATCH REPORT SEARCH; do
    expect "$m refused" 405 -u "backup-service:$SECRET" -X "$m" -H "Destination: $DAV/users/bd-alice/moved.txt" \
        --data x "$DAV/users/bd-alice/empty.txt"
done
expect "unknown method refused" "405|501" -u "backup-service:$SECRET" -X FOO "$DAV/users/bd-alice/empty.txt"
check "write attempts changed nothing" test "$(copy)" -eq 0
check "no moved file appeared" refused nc_dav bd-alice -X PROPFIND moved.txt
instanceid="$(occ config:system:get instanceid)"
for p in "users/bd-alice/%2e%2e/%2e%2e/%2e%2e/etc/passwd" "users/bd-alice/%2e%2e/files_trashbin/" \
    "users/bd-alice/..%2ffiles_trashbin/" "users/bd-alice/files_trashbin/" "users/bd-alice/files_versions/" \
    "appdata_$instanceid/" "users/bd-alice/$GF_NAME/" "users/bd-bob/Shared-with-bob/" \
    "users/BD-ALICE/" "users/bd-ghost/" "groupfolders/0$GF_ID/" "groupfolders/$GF_NAME/" \
    "groupfolders/$GF_ID/versions/" "groupfolders/$GF_ID/trash/" "groupfolders/$GF_ID/..%2fversions/"; do
    expect "out-of-perimeter path $p" "400|403|404" --path-as-is -u "backup-service:$SECRET" \
        -X PROPFIND -H 'Depth: 0' "$DAV/$p"
done
expect "service identity rejected by standard WebDAV" 401 -u "backup-service:$SECRET" \
    -X PROPFIND "$BASE_URL/remote.php/dav/files/backup-service/"

echo "== IP allowlist"
occ config:app:set backupdav allowed_ips --value=192.0.2.1/32 >/dev/null
expect "IP outside allowed_ips" 403 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
occ config:app:set backupdav allowed_ips --value="0.0.0.0/0,::/0" >/dev/null
expect "IP inside allowed_ips" 207 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
occ config:app:delete backupdav allowed_ips >/dev/null

echo "== credential lifecycle"
old="$SECRET"
SECRET="$(occ backupdav:credentials:rotate | sed -n 's/^Secret: *//p')"
SECRETS+=("$SECRET")
expect "old secret after rotate" 401 -u "backup-service:$old" -X PROPFIND "$DAV/"
expect "new secret after rotate" 207 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
occ backupdav:disable >/dev/null
expect "disabled endpoint" 404 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
check "disabling the endpoint keeps the app enabled" test "$(occ config:app:get backupdav enabled)" = yes
occ backupdav:enable >/dev/null
expect "re-enabled endpoint" 207 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
occ backupdav:credentials:revoke >/dev/null
expect "revoked secret" 404 -u "backup-service:$SECRET" -X PROPFIND "$DAV/"
check "enable refused without a secret" refused occ backupdav:enable

echo "== logs"
logfile="$(occ config:system:get logfile 2>/dev/null || true)"
[ -n "$logfile" ] || logfile="$(occ config:system:get datadirectory)/nextcloud.log"
check "log file readable" quiet docker exec "$NC" test -r "$logfile"
check "auth failures are logged" quiet docker exec "$NC" grep -q 'BackupDAV authentication failed' "$logfile"
for s in "${SECRETS[@]}"; do check "secret absent from nextcloud.log" refused docker exec "$NC" grep -qF "$s" "$logfile"; done

echo
if [ "$FAILS" -eq 0 ]; then
    echo "ALL CHECKS PASSED"
else
    echo "$FAILS CHECK(S) FAILED"
    exit 1
fi
