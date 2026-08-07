/**
 * Full-stack test. Runs the real browser crypto module against the real HTTP
 * API, so the encrypt -> store -> destroy -> decrypt loop is exercised end to
 * end rather than in two separately-mocked halves.
 *
 * Needs the app served at NOTEMY_BASE (default http://127.0.0.1:8080).
 */

import { open, parseFragment, seal } from "../frontend/src/crypto";
import * as b64u from "../frontend/src/base64url";

const BASE = process.env.NOTEMY_BASE ?? "http://127.0.0.1:8080";

let pass = 0;
let fail = 0;

function ok(name: string, cond: boolean, detail = ""): void {
  if (cond) {
    pass++;
    console.log(`  \x1b[32mPASS\x1b[0m  ${name}`);
  } else {
    fail++;
    console.log(`  \x1b[31mFAIL\x1b[0m  ${name}${detail ? ` — ${detail}` : ""}`);
  }
}

async function api(path: string, body?: unknown): Promise<{ status: number; json: any }> {
  const res = await fetch(`${BASE}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Origin: BASE },
    body: JSON.stringify(body ?? {}),
  });
  let json: any = null;
  try {
    json = await res.json();
  } catch {
    /* empty */
  }
  return { status: res.status, json };
}

async function main(): Promise<void> {
  console.log("\n--- 1. The full loop ---");

  const secret = "服务器永远看不到这行字 — server must never see this line";
  const sealed = await seal(secret);

  const created = await api("/api/note", { payload: sealed.payload, ttl: "1h" });
  ok("note created", created.status === 201 && typeof created.json.id === "string");

  const id: string = created.json.id;
  const link = `${BASE}/n/${id}#${sealed.fragment}`;

  // What a recipient's browser does: split the link, keep the fragment local.
  const [urlPart, fragmentPart] = link.split("#");
  const recovered = parseFragment(fragmentPart)!;

  const taken = await api(`/api/note/${urlPart.split("/n/")[1]}`);
  ok("note retrieved", taken.status === 200 && typeof taken.json.payload === "string");
  ok("decrypts to the original", (await open(taken.json.payload, recovered)) === secret);

  console.log("\n--- 2. What the server actually held ---");

  const serverBytes = b64u.decode(sealed.payload);
  const plainBytes = new TextEncoder().encode(secret);
  const keyBytes = b64u.decode(sealed.fragment).slice(2);

  ok("stored bytes contain no fragment of the plaintext", !contains(serverBytes, plainBytes.slice(0, 6)));
  ok("stored bytes contain no fragment of the key", !contains(serverBytes, keyBytes.slice(0, 8)));
  ok(
    "the key never appeared in any request path or body",
    !link.split("#")[0].includes(sealed.fragment) && !sealed.payload.includes(sealed.fragment),
  );

  console.log("\n--- 3. Destruction is real ---");

  const again = await api(`/api/note/${id}`);
  ok("second retrieval is 404", again.status === 404, String(again.status));
  ok("and carries no distinguishing detail", JSON.stringify(again.json) === '{"error":"not_found"}');

  console.log("\n--- 4. Password-protected loop ---");

  const pwSecret = "两把钥匙才能打开";
  const pwSealed = await seal(pwSecret, "s3nd-me-separately");
  const pwFrag = parseFragment(pwSealed.fragment)!;

  ok("fragment tells the reader a password is needed before any request", pwFrag.passwordRequired === true);

  const pwCreated = await api("/api/note", { payload: pwSealed.payload, ttl: "1h" });
  const pwTaken = await api(`/api/note/${pwCreated.json.id}`);

  ok("password note round trips", (await open(pwTaken.json.payload, pwFrag, "s3nd-me-separately")) === pwSecret);

  let rejected = false;
  try {
    await open(pwTaken.json.payload, pwFrag, "guess");
  } catch {
    rejected = true;
  }
  ok("wrong password fails locally, on already-fetched ciphertext", rejected);

  console.log("\n--- 5. Read shell is inert ---");

  const shell = await fetch(`${BASE}/n/${id}`);
  const html = await shell.text();
  ok("shell serves 200 even for a destroyed note", shell.status === 200);
  ok("shell body carries no note-specific content", !html.includes(id));
  ok("shell references the hashed bundle with SRI", /integrity="sha384-[A-Za-z0-9+/=]+"/.test(html));
  ok("shell marks itself noindex", html.includes("noindex"));

  console.log("\n--- 6. Size ceiling agrees with the server ---");

  // The create UI computes its byte budget from the same constants; confirm the
  // largest note it would allow is one the server accepts.
  const maxBytes = Math.floor((32768 * 3) / 4) - (2 + 16 + 12 + 16);
  const atLimit = "a".repeat(maxBytes);
  const limitSealed = await seal(atLimit, "pw");
  const limitCreated = await api("/api/note", { payload: limitSealed.payload, ttl: "1h" });
  ok(
    `a note at the UI's ${maxBytes}-byte limit is accepted (payload ${limitSealed.payload.length} chars)`,
    limitCreated.status === 201,
    String(limitCreated.status),
  );

  const overSealed = await seal("a".repeat(maxBytes + 64), "pw");
  const overCreated = await api("/api/note", { payload: overSealed.payload, ttl: "1h" });
  ok("one comfortably past it is refused with 413", overCreated.status === 413, String(overCreated.status));

  console.log("\n" + "=".repeat(52));
  console.log(`${fail === 0 ? "\x1b[32m" : "\x1b[31m"}${pass} passed, ${fail} failed\x1b[0m\n`);
  process.exit(fail === 0 ? 0 : 1);
}

function contains(haystack: Uint8Array, needle: Uint8Array): boolean {
  if (needle.length === 0) return false;
  outer: for (let i = 0; i <= haystack.length - needle.length; i++) {
    for (let j = 0; j < needle.length; j++) {
      if (haystack[i + j] !== needle[j]) continue outer;
    }
    return true;
  }
  return false;
}

main();
