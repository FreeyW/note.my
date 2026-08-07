# Security model

This document is written to be useful when deciding whether to trust note.my
with something. It spends more space on what the design *cannot* do than on
what it can, because the failures are the part you need in order to decide.

## What the design does

Your note is encrypted in your browser with AES-256-GCM. The key is 32 bytes
from `crypto.getRandomValues()`, encoded into the URL fragment — the part after
the `#`. Browsers do not transmit the fragment when they request a URL, so the
key never reaches the server on any request, and is never present in access
logs, proxy logs, or TLS-terminating middleboxes.

The server holds ciphertext and an expiry timestamp. It has no decryption code
anywhere; if you find `openssl_decrypt` or similar in `src/`, that is a bug and
a serious one.

When the note is read, one SQL statement fetches and deletes it atomically:

```sql
DELETE FROM notes WHERE id_hash = ? AND expires_at > NOW() RETURNING payload
```

Exactly one concurrent request can win. The others get an empty result set and
the same "nothing here" response as a link that never existed.

## What it cannot defend against

### A compromised server delivering malicious JavaScript

**This is the fundamental weakness of the entire architecture, and nothing in
this repository fully solves it.**

The same server that stores your ciphertext also serves the JavaScript that
does the encrypting. An attacker who controls the server can serve a modified
bundle that copies your key, or your plaintext, somewhere else. Subresource
Integrity and reproducible builds narrow this: they let a third party check
that the bundle being served *right now, to them* matches the published source.
They do not prevent a server from serving an honest bundle to everyone except
one targeted person.

Every browser-delivered end-to-end encryption scheme has this property. Treat
claims to the contrary with suspicion. If your adversary is capable of
compromising a specific web server in order to reach you specifically, use
software you install and can pin, not a web page.

`scripts/verify.sh` is the mitigation available, and its own output says the
same thing.

### A compromised device at either end

If the sender's or recipient's machine is compromised — malware, a hostile
browser extension, someone reading over a shoulder, a screenshot tool — the
plaintext is visible at the moment it is typed or displayed. Nothing on the
server side is relevant to this.

### Sharing the link over an insecure channel

The link *is* the key. Anyone who obtains the full URL can read the note, once.
Posting it in a group chat, a ticket comment, or an email that gets archived
means it is readable by whoever can see that channel.

The optional password helps here, but only if you send it separately. A
password in the same message as the link protects against nothing.

### Traffic analysis and metadata

The server does not store IP addresses, user agents, or referrers, and does not
log requests to `/n/` or `/api/`. But it necessarily observes, in the moment:

- that some address created a note, and roughly how large it was
- that some address read a note

Rate limiting stores an HMAC of a *network* — IPv4 to /24, IPv6 to /64, keyed
with a server-side secret — not of a host. A stolen Redis dump therefore cannot
be brute-forced back to a list of visitors, which a plain hash of an IPv4
address certainly could be.

A network observer sees TLS to note.my, and can infer from timing and size that
a note was created or read. It cannot see which note.

### Ciphertext persisting on disk

Notes are stored in MariaDB, on disk, not in memory. This is deliberate and it
is a real trade-off:

- **What it buys:** a server restart, power loss, crash, or VPS migration does
  not lose unread notes. A recipient clicking a link and finding their note
  intact is the basic promise of the product.
- **What it costs:** ciphertext is written to disk and may linger.

Specifically, after a note is deleted the bytes may remain for some time in:

- InnoDB pages that have not yet been reclaimed
- the undo log
- the binary log, if binlogging is enabled

`OPTIMIZE TABLE notes` on a weekly cron reclaims pages. MariaDB does not enable
the binary log by default (unlike MySQL 8); if you enable it for replication,
set a short `binlog_expire_logs_seconds`, or you have silently defeated the
entire destruction mechanism.

**Back up with `--ignore-table=notemy.notes`.** The table is volatile data with
no backup value, and a backup file extends the retention of that ciphertext
indefinitely.

Why this is tolerable in practice: everything written to disk is ciphertext
without a key, and the key was never on the server to begin with. Somebody who
obtains a disk image obtains meaningless bytes.

**This service is not for you if** your threat model includes an adversary who
can seize a disk image now and obtain the key later.

### Timing

The four ways a read can fail — never existed, already read, expired,
malformed ID — return byte-identical responses with the same status code. That
is enforced by a test.

Response *timing* is not identical. A hit transmits a payload and a miss does
not, so a sufficiently precise observer measuring against a quiet server could
distinguish them. Hit and miss paths deliberately issue the same statistics
statement so the database work matches, which narrows the gap, but the payload
transfer cannot be equalised without padding every response to the maximum
note size. **We do not claim timing indistinguishability.**

### Deletion versus destruction

"Destroyed" here means the database row is deleted and the service will never
serve those bytes again. It does not mean the bytes have been overwritten on
the physical medium. See the persistence section above.

## Cryptographic details

| | |
|---|---|
| Content encryption | AES-256-GCM, 12-byte IV, 128-bit tag |
| IV | fresh from `crypto.getRandomValues()` for every note |
| Link secret | 32 bytes from `crypto.getRandomValues()`, in the URL fragment |
| Password stretching | PBKDF2-SHA256, 600,000 iterations, 16-byte random salt |
| Key derivation | HKDF-SHA256 over the link secret, and the stretched password when set |
| Note ID | 16 random bytes, base64url, 22 characters |
| Database key | `sha256(noteId)` — the ID itself is never stored |

### Why derivation instead of key wrapping

An earlier design wrapped a master key with a password-derived key and stored
the wrapped key next to the ciphertext. That hands the server an offline
oracle: it holds the salt and the wrapped key, so it can grind a human-chosen
password at leisure. 600,000 PBKDF2 iterations raise the cost per guess but do
not change the outcome for a weak password, and "the server can eventually read
this note" is precisely the property this project exists to deny.

Instead the content key is derived from both inputs together:

```
contentKey = HKDF-SHA256(ikm = linkSecret ‖ PBKDF2(password, salt, 600000))
```

With no password, the PBKDF2 half is absent. Because the link secret never
reaches the server, an attacker holding the entire database has nothing to
grind against. A weak password stays safe, and the password is a genuine second
factor rather than a substitute for the link.

The cost: there is no cheap "is this password correct?" check. A wrong password
surfaces as a GCM authentication failure on the content. Retries happen locally
on already-fetched ciphertext, so this costs a less specific error message and
removes a verification oracle.

### Why storing `sha256(noteId)` matters

The primary key is the hash, not the ID. If the database is dumped or a backup
leaks, an attacker has hashes, not URLs — they cannot construct a working link
from what they hold. They would still need the fragment key, which was never
there, but this removes even the ability to enumerate what once existed.

## Reporting a vulnerability

Open a GitHub issue for anything that is not itself sensitive. For a finding
that would put current users at risk if published, use GitHub's private
security advisory feature on this repository.

There is no bug bounty. There is no server-side log to correlate your report
against, which is the point.
