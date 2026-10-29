# Apache + PHP 8.3 development stack with FTP upload

A local development stack you can push projects into over FTP.

Every published port is bound to `127.0.0.1`, so the whole stack is reachable
from this machine and nowhere else. It is a deploy target for local testing,
not a server for the network.

| Service | Image | Reachable at | Purpose |
| --- | --- | --- | --- |
| `web` | `php:8.3-apache` (built) | http://localhost:8080 | Serves `./www` as the document root |
| `ftp` | `debian:12-slim` + vsftpd (built) | ftp://localhost:21 | Writes into the same `./www` directory |
| `mysql` | `mysql:8.4` | `mysql:3306` inside the network | Database, data kept in the `mysql_data` volume |
| `phpmyadmin` | `phpmyadmin:5-apache` | http://localhost:8081 | Database UI |
| `mailpit` | `axllent/mailpit:v1.31` | `mailpit:1125` inside the network | Catches every outgoing message; UI at http://localhost:8025 |

Apache and vsftpd share the bind mount `./www`, so an uploaded file is served
by the next HTTP request with no restart, no copy step, and no rebuild.

## Requirements

Docker with the Compose plugin. Nothing else runs on the host.

## Quick start

```bash
cp env.example .env      # then edit .env (at minimum: FTP_PASSWORD and the MySQL passwords)
docker compose up -d --build
```

If the stack was already started once with the placeholder passwords, wipe the
database volume first so the new credentials take effect:

```bash
docker compose down -v
docker compose up -d
```

Open http://localhost:8080 — the placeholder page reports the PHP version,
loaded extensions, and whether MySQL is reachable from PHP.

The `.env` file is not versioned (see `.gitignore`); `env.example` is the
template. Compose reads `.env` automatically.

### Ports

All host ports are configurable in `.env`:

| Variable | Default | Notes |
| --- | --- | --- |
| `WEB_PORT` | `8080` | Change it if something already listens on the host's port 80 |
| `PMA_PORT` | `8081` | phpMyAdmin |
| `FTP_PORT` | `21` | Control channel |
| `FTP_PASSIVE_MIN` / `FTP_PASSIVE_MAX` | `21000` / `21010` | Passive data range, must be published as-is |
| `MYSQL_PORT` | `3306` | Unused unless the `mysql` ports mapping is uncommented |
| `MAILPIT_SMTP_PORT` | `1125` | The port the app connects to, published on the host on the same number |
| `MAILPIT_UI_PORT` | `8025` | Host port for the Mailpit web UI |
| `MAILPIT_MAX_MESSAGES` | `500` | Messages kept before Mailpit prunes the oldest |

`WEB_PORT` is published as `${WEB_PORT}:80`, and Apache also listens on **8080
inside the container** (`web/conf/apache-dev.conf`). That second listener is not
published to the host: it exists so PHP can call the stack through the same
public URL the browser uses (`http://127.0.0.1:8080/...`). Inside a container
`127.0.0.1` is the container itself, not the host, so without it a
server-to-server OIDC call fails with `cURL error 7`. The internal listener is
fixed at 8080; keep `WEB_PORT=8080` if the apps call each other by that URL.

## Isolation: this machine only

Publishing a port with `-p 8080:80` binds it on `0.0.0.0`, and Docker inserts its
own iptables rules ahead of the host's firewall. A closed `ufw` or `firewalld`
therefore does not protect a published container port — anyone on the LAN, or on
a coffee-shop network, can reach it. The fix is the bind address, not a firewall
rule:

```yaml
ports:
  - "127.0.0.1:${WEB_PORT:-8080}:80"
```

That is what this stack does, for every port including the FTP passive range.
Two further consequences of the design:

- **MySQL has no host port.** Only `web` and `phpmyadmin` reach it, over the
  compose network, exactly as in a real deployment. Use phpMyAdmin at
  http://localhost:8081, or:
  `docker compose exec mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" app`.
- **Outbound internet still works.** The containers stay on a normal bridge
  network, so `composer install`, `apt-get`, and HTTP calls from PHP behave as
  they would on a real deploy host.

Verify it from the host:

```bash
# Every mapping must start with 127.0.0.1:
docker compose ps --format '{{.Names}}\t{{.Ports}}'

# Nothing of this stack may appear on 0.0.0.0 or on an external address:
ss -ltn | grep -E '8080|8081|8025|1125|:21 |2100'
```

Reaching the stack from another machine is deliberately not configured. It would
mean replacing `127.0.0.1` with the host's LAN IP in `docker-compose.yml`, and
accepting that plain FTP sends the password in clear text over that network.

## Uploading a project over FTP

Use any FTP client with **passive mode** (the default in FileZilla, Transmit,
WinSCP, and `curl`):

```
Host:     localhost        (or 127.0.0.1)
Port:     21
Protocol: FTP (plain)
User:     ftpuser          (FTP_USER)
Password: as set in .env   (FTP_PASSWORD)
```

Upload into `/`, which is the webroot: `/index.php` becomes
`http://localhost:8080/index.php`.

Command-line check:

```bash
curl -T ./my-project.zip ftp://ftpuser:ftppass@localhost:21/
curl http://localhost:8080/my-project.zip -o /dev/null -w '%{http_code}\n'   # 200
```

### File ownership

`FTP_UID` is the uid that writes the uploads: the default `1000` matches the
usual first desktop user, so files land owned by you and not by `root`. Set it to
`id -u` if yours differs.

`WEB_GID` is the group of the uploaded files and the FTP account's primary group.
It must be the group the web server runs as, otherwise the application cannot
write its cache and `0777` becomes the only workaround — see
[Permissions](#permissions-why-nothing-needs-0777).

Both are reported in the container log at startup, or on demand:

```bash
docker compose exec ftp id "$(cat /etc/vsftpd.userlist)"
```

### Connecting from another machine on the LAN

Out of scope by design; see [Isolation](#isolation-this-machine-only). Plain FTP
sends credentials and data unencrypted, so it belongs on loopback or a trusted
LAN only — never on the public internet. For real deployment, prefer SFTP/FTPS
or a deploy step over SSH.

## Project layout

```
.
├── docker-compose.yml        # the four services
├── env.example               # template for .env
├── web/
│   ├── Dockerfile            # php:8.3-apache + gd, zip, intl, pdo_mysql, mysqli, opcache
│   └── conf/
│       ├── apache-dev.conf   # AllowOverride All, so .htaccess works
│       └── php-dev.ini       # dev errors, 128M uploads, OPcache always revalidated
├── ftp/
│   ├── Dockerfile            # debian + vsftpd
│   ├── vsftpd.conf           # chrooted local user, passive range
│   └── entrypoint.sh         # creates the account from the environment at startup
├── mysql/
│   └── init/
│       └── 01-extra-databases.sh # creates the schemas listed in EXTRA_DATABASES
└── www/                      # webroot, shared by Apache and FTP
    └── index.php             # stack health page
```

### Frameworks whose entry point is `public/`

Put the project in `./www` and map only its public directory by editing the
`web` volumes in `docker-compose.yml`:

```yaml
    volumes:
      - ./www/public:/var/www/html
```

The FTP container keeps mounting `./www`, so uploads still land next to
`vendor/`, `src/`, and the rest of the project.

## Database

`MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` from `.env` are created on
first start of the `mysql` volume. From PHP, connect to host `mysql` (the
service name) — the `web` container receives `DB_HOST`, `DB_NAME`, `DB_USER`,
and `DB_PASSWORD` as environment variables, so the same code works against the
real deployment host by changing those values only.

### One schema per application

The official image creates **only** `MYSQL_DATABASE` and grants the user access
to that single schema. A project that keeps one schema per application points
each app's `DB_DATABASE` at its own database, so the extra ones have to exist or
the app fails with `1044 Access denied for user 'app'@'%' to database '...'`.

List them space-separated in `EXTRA_DATABASES` in `.env`:

```dotenv
EXTRA_DATABASES=app_one app_two
```

`mysql/init/01-extra-databases.sh` runs on the first initialisation of the
volume and `CREATE DATABASE IF NOT EXISTS` + `GRANT ALL PRIVILEGES` for each
name, plus `MYSQL_DATABASE`. The schemas are empty; migrations create the
tables.

The script only runs while the volume is empty. To create the schemas on an
already-initialised volume without deleting data, apply the same statements by
hand:

```bash
docker compose exec -T mysql sh -c '
  for db in app_one app_two; do
    mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
      CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      GRANT ALL PRIVILEGES ON \`$db\`.* TO '\''$MYSQL_USER'\''@'\''%'\'';"
  done
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "FLUSH PRIVILEGES;"'
```

To add another schema, append its name to `EXTRA_DATABASES` and re-run the block
above.

There is no MySQL port on the host. Data lives in the `mysql_data` named volume
and survives `docker compose down`; `docker compose down -v` deletes the
database.

`MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` are read **only while the
`mysql_data` volume is empty**. Editing `MYSQL_PASSWORD` later does not change
the existing database user, and `web` will start failing to connect while
phpMyAdmin still works with the old password. Either keep the value, or
re-initialise the volume:

```bash
docker compose down -v && docker compose up -d
```

## Mail

`mailpit` is an SMTP server that keeps every message and never forwards it, so
testing registration, password reset, or order mail cannot accidentally reach a
real inbox. Read it at http://localhost:8025.

Two different addresses, and this is the part that trips people up:

| Who connects | Host | Port |
| --- | --- | --- |
| PHP inside `web` | `mailpit` — the service name | `1125` |
| Anything on the host (Thunderbird, `curl`, a host script) | `127.0.0.1` | `1125` |

From inside a container, `127.0.0.1` is **that container**, not the host. The
`web` service therefore receives `MAIL_HOST=mailpit`, exactly like `DB_HOST=mysql`.
Hardcoding `127.0.0.1` in an application config will make it mail itself and
fail with `Connection refused`.

`MAILPIT_SMTP_PORT` is deliberately one variable driving three things — the
address Mailpit binds, the port published on the host, and `MAIL_PORT` handed to
the app — so they cannot drift apart.

Send a test message and read it back:

```bash
curl --url smtp://127.0.0.1:1125 \
     --mail-from app@example.test --mail-rcpt you@example.test \
     --upload-file <(printf 'Subject: hello\n\nbody\n')

curl -s http://127.0.0.1:8025/api/v1/messages | head -c 200
```

Credentials are accepted but not checked: Mailpit takes any username and
password without one configured (`MP_SMTP_AUTH_ACCEPT_ANY` is its behaviour by
default when no auth file is set). `MAIL_HOST`/`MAIL_PORT` are plain variables —
if your framework wants a DSN instead, build it as `smtp://mailpit:1125`.

## Permissions: why nothing needs 0777

Two identities write the same tree, and that is the whole problem. vsftpd writes
as `FTP_UID` (your uid), and Apache runs as `www-data` (uid 33). For Apache your
files are "other": at `644`/`755` that means read-only, so making `storage/`
writable used to end in `chmod -R 777`.

**Ownership is not the lever; the group is.** Making `www-data` the owner trades
one problem for another: your own FTP account would become "other" and could no
longer overwrite what it uploads. Instead the stack keeps you as the owner and
puts the web server in the group of the files.

| Piece | Setting | Effect |
| --- | --- | --- |
| FTP account's primary group | `WEB_GID` (default `33`, `www-data`) | uploads arrive owned by you, group `www-data` |
| `local_umask` in `ftp/vsftpd.conf` | `002` | uploads arrive `664` (files) / `775` (dirs), group-writable |
| `umask 002` for Apache in `web/Dockerfile` | `002` | runtime cache, view and log files stay group-writable |
| directories under `storage/`, `bootstrap/cache/` | setgid (`2775`) | new subdirectories inherit the same group |

The result: `www-data` writes because it is in the group, and you write because
you are the owner. No world-writable bit anywhere.

`WEB_GID` must be the group the web server actually runs as. It is `33` in the
`php:8.3-apache` image; confirm it with `docker compose exec web id www-data`.
If you ever change `APACHE_RUN_GROUP`, change `WEB_GID` with it.

Runtime files created *by* Apache are owned by `www-data`, so to manage them from
the host as yourself, join the group once (it takes effect on your next login):

```bash
sudo usermod -aG www-data "$USER"
```

Never `chmod -R 777`. It grants write access to any process in the container and
does not fix the direction that matters — the web server writing *its* own files
next to yours.

### Normalising a tree uploaded before the group was shared

New uploads are already correct and need no step. A tree that predates this change
carries `you:you` and possibly `777`, and so does a project restored from an
archive created earlier, because the archive preserves the old modes. Normalise it
once:

```bash
docker compose exec -T web sh -c '
  APP=/var/www/html/myapp            # the project root, wherever you uploaded it
  chgrp -R www-data "$APP"
  chmod -R o-w "$APP"
  chmod -R g+rwX "$APP/storage" "$APP/bootstrap/cache"
  find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod g+s {} +
'
```

Group write is granted only to the trees the application writes at runtime; the
rest of the source tree just needs to stay readable by `www-data`, which `644`/
`755` already provides. The capital `X` is deliberate: it adds the exec bit to
directories only, never to plain files.

## Common commands

```bash
docker compose up -d --build          # build and start
docker compose ps                     # status
docker compose logs -f web ftp        # follow logs
docker compose exec web bash          # shell in the web container
docker compose exec web php -m        # loaded extensions
docker compose exec ftp id ftpuser    # uid/gid of the FTP account
docker compose exec mysql mysql -u root -p    # database shell
docker compose restart ftp            # re-apply FTP credentials from .env
docker compose down                   # stop, keep the database
docker compose down -v                # stop, delete the database volume
```

Changing `FTP_PASSWORD` or `FTP_USER` requires `docker compose up -d --force-recreate ftp`:
the account is created when the container starts.

## Rebuilding the PHP extension set

`web/Dockerfile` installs `gd`, `pdo_mysql`, `mysqli`, `zip`, `intl`, and
`opcache`. Add more with the standard helper and rebuild:

```dockerfile
RUN docker-php-ext-install bcmath exif
```

```bash
docker compose build web && docker compose up -d web
```

## Troubleshooting

- **`530 Login incorrect`** — the account is created from `.env` at startup.
  Check that `.env` exists and that `FTP_PASSWORD` is not still `change-me`,
  then recreate the container.
- **Login works, directory listing hangs** — a passive-mode problem. Confirm the
  full `FTP_PASSIVE_MIN`–`FTP_PASSIVE_MAX` range is published and that
  `FTP_PASV_ADDRESS` is reachable from the client.
- **Upload succeeds but the file is not writable on the host** — uid mismatch:
  set `FTP_UID` to `id -u` and recreate the container.
- **`500 OOPS: vsftpd: refusing to run with writable root inside chroot()`** —
  `allow_writeable_chroot=YES` must stay in `ftp/vsftpd.conf`; the jail is the
  shared webroot and has to be writable.
- **Port already in use** — change `WEB_PORT` or `FTP_PORT` in `.env`. The
  host's port 80 is intentionally not used, since this stack never needs to be
  the machine's public web server.
- **PHP cannot reach MySQL after editing `MYSQL_PASSWORD`** — the credentials
  are applied when the volume is first created, not on every start. Run
  `docker compose down -v` to re-initialise (this deletes the data).
- **PHP changes not visible after an FTP upload** — OPcache is configured with
  `opcache.revalidate_freq=0` in `web/conf/php-dev.ini`, so this should not
  happen. If a file keeps serving old content, confirm that file is mounted:
  `docker compose exec web php --ini`.
- **Mail fails with `Connection refused`** — the application is pointed at
  `127.0.0.1`, which inside the `web` container is the container itself. Use
  `MAIL_HOST=mailpit`. Verify from the right place with
  `docker compose exec web php -r 'var_dump(fsockopen(getenv("MAIL_HOST"), (int) getenv("MAIL_PORT")));'`.
- **A message never appears in the UI** — check the recipient was accepted and
  look at the SMTP dialogue: `docker compose logs -f mailpit`.
- **The application "needs" `chmod -R 777` on `storage` or `bootstrap/cache`** —
  that is a group problem, not an ownership one. The uploaded files must carry
  `WEB_GID`, the group `www-data` runs as. See
  [Permissions](#permissions-why-nothing-needs-0777); `chmod 777` hides the cause
  and leaves the reverse direction broken.
