# note.my

One-time encrypted notes. Write something, get a link, the recipient reads it
once, and it is gone.

The point of this one is that you can check the claim. The encryption happens
in your browser, the key travels in the URL fragment and never reaches the
server, and `scripts/verify.sh` will rebuild the frontend from this source and
compare it byte for byte against what the live site is serving.

Readability and having almost no dependencies matter more here than features or
shipping speed. If a change makes the code harder to audit, it is probably the
wrong change.

## How it works

1. Your browser generates a 32-byte random key and encrypts the note with
   AES-256-GCM.
2. Only ciphertext is uploaded. The key is encoded after the `#` in the link,
   and browsers never send that part to a server.
3. The recipient opens the link and clicks to confirm. One SQL statement
   atomically fetches and deletes the ciphertext; their browser decrypts it.
4. Opening the link again returns nothing — indistinguishable from a link that
   never existed.

Read [SECURITY.md](SECURITY.md) before trusting this with anything that
matters. It is specific about what this design cannot protect you from.

## Requirements

- **MariaDB 10.5+** (10.11 LTS or 11.4 LTS recommended)
- PHP 8.3+ with `pdo_mysql` and `redis`
- Redis (rate limiting only)
- Nginx + PHP-FPM
- Node 20+ (build only — nothing Node runs in production)

### MariaDB is not optional

This project uses `DELETE ... RETURNING`, which MySQL does not have. That is a
deliberate choice, not an accident of development: it makes fetch-and-destroy a
single atomic statement with no explicit transaction and no `SELECT ... FOR
UPDATE`. Do not rewrite it for MySQL compatibility. The application checks the
server version at startup and exits with a clear error rather than degrading
silently.

## Dependencies

**Composer packages: 0.** The target is zero and the ceiling is three; each one
added must justify in its PR why the standard library will not do.

**npm packages at runtime: 0.** Build-time only: `esbuild`, `tailwindcss`,
`typescript`, all version-pinned.

No framework, no ORM, no React. The router is under 100 lines.

## Install

```bash
git clone https://github.com/YOURNAME/note.my && cd note.my

# database
mariadb -e "CREATE DATABASE notemy; \
  CREATE USER 'notemy'@'localhost' IDENTIFIED BY 'CHANGEME'; \
  GRANT ALL ON notemy.* TO 'notemy'@'localhost';"
mariadb notemy < config/schema.sql

# config
cp config/config.php.example config/config.php
php -r 'echo bin2hex(random_bytes(32)), "\n";'   # for ip_hash_secret
$EDITOR config/config.php

# fonts — see frontend/fonts/README.md, they are not fetched from Google
# frontend
cd frontend && npm ci && cd ..
./scripts/build.sh

# server config
cp config/nginx.conf.example /etc/nginx/sites-available/note.my
cp config/my.cnf.example /etc/mysql/mariadb.conf.d/99-notemy.cnf
```

Then confirm binlogging is off, because it would retain deleted ciphertext:

```bash
mariadb -e "SHOW VARIABLES LIKE 'log_bin'"     # expect OFF
```

Cron:

```cron
*/5 * * * * /usr/bin/php /srv/note.my/scripts/purge-expired.php >/dev/null 2>&1
17 4 * * 0  /usr/bin/mariadb notemy -e "OPTIMIZE TABLE notes" >/dev/null 2>&1
```

Backups must skip the notes table:

```bash
mariadb-dump --ignore-table=notemy.notes notemy > backup.sql
```

## Verifying what the site serves

```bash
git clone https://github.com/YOURNAME/note.my && cd note.my
./scripts/verify.sh https://note.my
```

This installs the pinned toolchain, rebuilds the bundle, downloads what the
live site serves, and checks three things:

1. the page's own `integrity` attribute matches the bytes it served
2. those bytes are identical to the bytes this source produces
3. there is no executable inline script on the page

Check 1 alone proves nothing — a hostile server can update the attribute to
match whatever it is serving. Check 2 is the one that matters, and it is why
the build has to be deterministic.

**What a green result means:** the bundle served to you, just now, was built
from this source. **What it does not mean:** that the same bundle is served to
everyone. A compromised server can target one visitor. See SECURITY.md.

Every GitHub release publishes the artifact hashes so you can compare against a
specific tag rather than whatever `main` happens to be.

## Development

```bash
cd frontend && npm ci && cd ..
./scripts/build.sh
php -S 127.0.0.1:8080 -t public public/index.php
```

Run everything:

```bash
./tests/run-all.sh
```

Needs MariaDB and Redis running. Eight steps: TypeScript typecheck, PHP lint,
crypto (against Node's WebCrypto, the same surface the browser exposes), the
storage layer, a concurrency race, the HTTP API, SEO markup, and a full-stack
pass that runs the real browser crypto against the real API.

The concurrency suite is worth understanding before touching `NoteStore`. It
starts N processes that all hit the same note at the same instant and asserts
that exactly one receives the payload. Run it with more contenders if you have
changed anything about the destroy path:

```bash
./tests/race.sh 50 32 "READ COMMITTED"
```

## Layout

```
public/index.php        the only entry point
src/
  Router.php            86 lines, no framework
  Controller/           NoteController (API), PageController (static shells)
  Store/                NoteStore, StatsStore, Database
  Http/                 Request, Response, ClientIp
  RateLimiter.php       Redis, fails open
  I18n.php              reads the same catalogues the frontend bundles
frontend/
  src/crypto.ts         all confidentiality lives here
  src/ui/               create and read pages
  i18n/                 zh-CN.json, en.json
config/                 schema.sql, nginx, my.cnf, config.php.example
scripts/                build.sh, verify.sh, purge-expired.php, purge-note.php
tests/
```

## Design decisions worth knowing about

### Ciphertext is stored on disk, not in memory

A restart, power cut, or migration must not lose unread notes. Losing someone's
note because a server rebooted is worse than ciphertext sitting on a disk,
given that the key was never on that disk. The costs are documented honestly in
SECURITY.md — including what lingers in InnoDB pages, the undo log, and
binlogs.

Where confidentiality and availability conflict, availability wins. The same
principle applies to Redis: if it is unreachable, rate limiting degrades to
"allow and log a warning" rather than refusing to create notes.

### `GET /n/{id}` never touches the database

Slack, WhatsApp, Outlook, Telegram and every mail scanner will fetch that URL
before a human clicks it. If reading the page destroyed the note, unfurling a
link would destroy it.

So the read page is a static shell with no ID validation and no lookup, and
the note is only destroyed by an explicit `POST` after the visitor clicks. This
is enforced structurally, not by convention: `PageController` has no storage
dependency, and the database connection is a lazy closure it cannot reach. The
test suite asserts that 25 prefetches of a note URL open zero database
connections and issue zero `DELETE`s.

### There is no grace period

Reading is irreversible. No "re-read within 5 minutes" window, because that
window is exactly the thing the product promises does not exist.

### The fragment carries a flags byte

The fragment is `base64url(version ‖ flags ‖ 32-byte key)`. The flags byte
tells the reading page a password is needed *before* it destroys anything.
Without it, a reader lacking the password would have to destroy the note in
order to discover they cannot open it.

### The live-note quota uses a sorted set, not a counter

Each network may hold 50 unread notes. This cannot be a counter that decrements
on read, because `notes` stores no creator identifier — neither the read path
nor the purge job knows whose note it just destroyed, by design. So Redis holds
a sorted set scored by expiry, with random tokens as members. Entries age out
on their own.

This overcounts: a note read early still occupies quota until its original
expiry. Overcounting is the safe direction for a quota, and it avoids building
the note-to-network mapping we deliberately do not keep.

### `closeCursor()` after `DELETE ... RETURNING`

Measured on PHP 8.3.6 and MariaDB 10.11.14: with PDO's default buffered
queries, omitting `closeCursor()` is harmless. With `MYSQL_ATTR_USE_BUFFERED_
QUERY` set to false, the next query on that connection dies with *"Cannot
execute queries while other unbuffered queries are active."*

That makes the omission more dangerous than it looks, not less — the bug lies
dormant until somebody turns buffering off. `Database.php` pins buffering on,
and `NoteStore` calls `closeCursor()` anyway.

### Language lives in the URL

`/` is English, `/zh` is Chinese, and both are server-rendered. `hreflang`
annotates alternate *pages*; it cannot describe one URL that swaps language in
JavaScript. Server rendering is also what gives crawlers something to index —
JSON-LD does not help a page whose body is an empty `<div>`.

The reading page is `noindex`, excluded from the sitemap, and carries no
canonical, `hreflang`, or JSON-LD. Each of those tells a crawler to come back
and fetch the URL, and fetching that URL costs a note.

## Explicitly out of scope

No accounts, no login, no note history. No file uploads. No read receipts —
that needs an email address, which contradicts the whole point. No server-side
encryption. No ORM. No full-text search. No rich text editor. No caching layer
in front of the database, which would reintroduce "destroyed note still
readable from cache".

The `notes` table has exactly four columns. Not five.

## License

AGPL-3.0. Fetch the license text into place if it is not already there:

```bash
curl -o LICENSE https://www.gnu.org/licenses/agpl-3.0.txt
```
