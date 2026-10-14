# BackupDAV

Read-only WebDAV export of every Nextcloud user's files and every group folder, built for
[rclone](https://rclone.org/) backups. One service credential managed with `occ`: no user
passwords, no admin account, no core changes. Works with S3 primary storage: files are read
through the Nextcloud Files API, never from the bucket directly.

> **This is not a full backup of the instance.** BackupDAV exports file contents only: no
> database, no `config.php`, no app data, no file versions, no trash bin, no shares, calendars or
> contacts. Back those up separately.

## How it works

```
Nextcloud (A) ──HTTPS / WebDAV──▶ rclone (C) ──▶ backup storage (B)
```

- Endpoint: `https://<host>/remote.php/backupdav/`, registered with `<remote>` in `info.xml` and
  served by the app's own SabreDAV server. It is independent from `/remote.php/dav`: no Nextcloud
  login, no session, no change to standard WebDAV.
- Read-only: `GET`, `HEAD`, `OPTIONS`, `PROPFIND`. Every other method gets `405`.
- Listings come from the Nextcloud file cache (size, mtime, ETag, MIME type): no S3 reads.
  Downloads are streamed in 4 MiB chunks; `Range` requests are supported.

```
/remote.php/backupdav/
├── users/<uid>/...          personal files of each user who has logged in at least once
└── groupfolders/<id>/...    each group folder once, by numeric id
```

| Content | Exported |
|---|---|
| A user's own files and folders | yes, under `users/<uid>/` |
| Disabled accounts | yes |
| Users who never logged in | no (they have no files; listing them would create their home) |
| Incoming shares | no — exported once, under the owner |
| Group folders | yes, once, under `groupfolders/<id>/` |
| External storage, federated shares | no |
| Trash bin, file versions, `appdata_*` | no |

Map group folder ids to names with `occ groupfolders:list`. Ids survive renames, so renaming a
group folder does not re-transfer it.

## Compatibility

- Nextcloud 35 (tested on 35.0.1). `info.xml` allows 34–35; 34 is not tested.
- Group folders: tested with groupfolders 23.0.1, which gives each folder its own storage. Nextcloud
  has no public API to list group folders, so BackupDAV uses the groupfolders app's `FolderManager`
  for the list (the app's only dependency on another app's internals) and reads each folder through
  the public Files API, via one member's mount. If a groupfolders update changes that class,
  `groupfolders/` answers `503` and the error is logged: the export fails loudly instead of silently
  skipping group folders. Re-run the integration test after upgrading Nextcloud or groupfolders.

## Install (Docker)

Build the archive (`make build` → `build/backupdav.tar.gz`) or take it from a GitHub release,
then copy the app into the container and enable it:

```bash
tar -xzf backupdav.tar.gz
docker cp backupdav <nextcloud_container>:/var/www/html/custom_apps/backupdav
docker exec <nextcloud_container> chown -R www-data:www-data /var/www/html/custom_apps/backupdav
docker exec -u www-data <nextcloud_container> php occ app:enable backupdav
```

For a stack that does not persist `/var/www/html`, bind-mount the app read-only instead:

```yaml
volumes:
  - ./apps/backupdav:/var/www/html/custom_apps/backupdav:ro
```

On a brand-new volume, Docker creates `custom_apps` as `root` for that mount point and Nextcloud
can no longer install apps there. Fix it once:
`docker exec -u root <nextcloud_container> chown www-data:www-data /var/www/html/custom_apps`.

Enabling the app registers the endpoint. It stays off (`404`) until a secret exists **and** it is
enabled.

## Service credential

```bash
occ backupdav:credentials:generate   # prints the secret once
occ backupdav:enable
occ backupdav:status                 # state, URL, users, group folders, versions
```

The secret is 64 hexadecimal characters (256 random bits). Only its SHA-256 hash is stored, as a
sensitive app config value. Store the secret in your password manager or directly in the rclone
config: it cannot be displayed again.

## Restrict access to the rclone host (recommended)

```bash
occ config:app:set backupdav allowed_ips --value="203.0.113.10/32"
occ config:app:delete backupdav allowed_ips    # back to any address
```

Comma-separated IPs or CIDR ranges, checked before authentication. The client address is the one
Nextcloud computes, so `trusted_proxies` must list your reverse proxies; otherwise every request
appears to come from the proxy.

## HTTPS

Basic authentication sends the secret with every request: expose the endpoint over HTTPS only
(TLS is terminated by Traefik in the managed config-server stack).

## rclone

```ini
[nextcloud-export]
type = webdav
url = https://cloud.example.com/remote.php/backupdav/
vendor = owncloud
user = backup-service
pass = <output of: rclone obscure 'THE_SECRET'>
```

```bash
rclone lsd nextcloud-export:users
rclone ls nextcloud-export:
rclone copy nextcloud-export: backup:nextcloud-export/ \
  --create-empty-src-dirs --transfers 8 --checkers 16 --progress
```

- Use `vendor = owncloud`. With `vendor = other`, rclone ignores modification times and compares
  sizes only, so a file edited without changing its size is never copied again.
- Incremental runs compare size and modification time; unchanged files are not transferred again.
- ETags are change markers, not content checksums: do not use `--checksum`.
- `--create-empty-src-dirs` keeps empty folders.
- `copy` never deletes at the destination: files of deleted users or deleted files stay there.
  `rclone sync` mirrors deletions; pair it with `--backup-dir` or destination-side versioning.
  With `sync`, anything missing from a listing is deleted at the destination, including a group
  folder that is temporarily unreachable (see Known limits). Prefer `copy` plus retention on B.

### Example: nightly A → C → B

On server C, as the user owning the rclone config:

```cron
15 2 * * * rclone copy nextcloud-export: backup:nextcloud-export/ --create-empty-src-dirs --transfers 8 --checkers 16 --log-file /var/log/rclone-nextcloud.log --log-level INFO
```

## Rotate, revoke, disable

| Command | Effect (within a few seconds) |
|---|---|
| `occ backupdav:credentials:rotate` | New secret; the old one gets `401` (then `429`, see Logs). Update `pass` in rclone. |
| `occ backupdav:credentials:revoke` | Secret deleted; the endpoint answers `404`. |
| `occ backupdav:disable` | Endpoint answers `404`; the secret is kept. |
| `occ backupdav:enable` | Endpoint answers again (requires a secret). |

Nextcloud web workers cache app settings for up to 3 seconds, so a change made with `occ` reaches
every request within that delay. A download already in progress finishes.

## Logs

Entries go to the Nextcloud log with `"app": "backupdav"`:

- warning: failed authentication (client IP only), requests from non-allowed IPs, storage read
  errors (including a groupfolders API mismatch), group folders that cannot be reached, PROPFIND
  requests slower than 10 s;
- info: secret generated, rotated, revoked; endpoint enabled, disabled;
- error: unexpected failures.

Never logged: the `Authorization` header, secrets, submitted usernames or passwords, file contents.
To log this app at debug level without raising the global level, add to `config.php`:

```php
'log.condition' => ['apps' => ['backupdav']],
```

Failed authentications feed Nextcloud's brute-force protection (action `backupdav`): after 10
failures within 30 minutes, further wrong credentials from that IP get `429` until the window
expires or `occ security:bruteforce:reset <ip>` is run. The correct secret is never blocked, so an
rclone job still running with a rotated-out secret cannot lock out the new one.

## Resource impact

- **PHP workers:** each rclone transfer or checker holds one Apache/PHP worker; a download holds
  it for its whole duration. Keep `--transfers` + `--checkers` well below `MaxRequestWorkers` so
  users keep being served.
- **PostgreSQL:** one file cache listing per directory, plus the mount setup of one user per request.
- **S3:** one GET per downloaded file (ranged GETs on resume). Listings do not touch S3.
- **Network:** the full data volume on the first run, then only changed files.
- **CPU:** low; streaming only. TLS runs on the reverse proxy.
- **nginx:** the config-server nginx buffers proxied responses to temp files (up to 1 GiB per
  response by default); consider `proxy_buffering off` for `/remote.php/backupdav/`.

Throttle from the rclone side with `--transfers`, `--checkers`, `--tpslimit 10` or
`--bwlimit 50M`. Stop everything with `occ backupdav:disable`.

## Known limits

- Users who never logged in are not exported.
- External storage and federated shares are not exported.
- A group folder is read through one member's mount. It is skipped (and logged) until at least one
  member has used Nextcloud since the folder was created; a new folder appears once a member's
  mounts are refreshed (`fs_mount_cache_duration`, 5 minutes by default).
- Group folders with advanced permissions (ACL) are exported as the resolving member sees them and
  may be incomplete. `occ backupdav:status` counts them.
- ETags are not checksums; `--checksum` is unsupported.
- `HEAD` on a file opens the storage stream like `GET` (Sabre behaviour), then discards it.
- One service identity per instance.

## Update

Replace `custom_apps/backupdav` with the new version, then run `occ upgrade` if Nextcloud asks for
it. The secret and settings are kept.

## Uninstall

```bash
occ backupdav:disable
occ backupdav:credentials:revoke
occ app:disable backupdav
occ app:remove backupdav
occ config:app:delete core remote_backupdav
for key in endpoint_enabled secret_hash secret_created_at allowed_ips; do occ config:app:delete backupdav "$key"; done
```

## Development

```bash
make test          # unit tests, inside the nextcloud:35.0.1-apache image
make integration   # end-to-end test with rclone against the config-server dev stack
make build         # build/backupdav.tar.gz
```

The integration test expects the `nextcloud-config-server` dev stack on branch
`feat/backupdav-dev`, which bind-mounts this repository into `custom_apps/backupdav`. It creates
and removes `bd-*` test users, groups and group folders, and turns brute-force protection and the
`userlimit` cap off during the run (restored afterwards). Variables: `BASE_URL`, `NC_CONTAINER`,
`DB_CONTAINER`, `BIG_MB`.
