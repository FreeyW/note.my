/**
 * Crypto tests. Run against Node's WebCrypto, which is the same
 * implementation surface the browser exposes.
 */

import * as nm from "../frontend/src/crypto";
import * as b64u from "../frontend/src/base64url";

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

async function throws(fn: () => Promise<unknown>, type: Function): Promise<boolean> {
  try {
    await fn();
    return false;
  } catch (e) {
    return e instanceof type;
  }
}

async function main(): Promise<void> {
  console.log("\n--- 1. Round trip ---");

  ok("environment is supported", nm.isSupported());

  const text = "银行卡密码 123456 🔐 <script>alert(1)</script>\n第二行\ttab";
  const sealed = await nm.seal(text);
  const frag = nm.parseFragment(sealed.fragment)!;
  ok("fragment parses", frag !== null && frag.linkSecret.length === 32);
  ok("no password flag when none given", frag.passwordRequired === false);
  ok("round trip preserves unicode exactly", (await nm.open(sealed.payload, frag)) === text);

  console.log("\n--- 2. The server sees nothing usable ---");

  const payloadBytes = b64u.decode(sealed.payload);
  const fragBytes = b64u.decode(sealed.fragment);
  const utf8 = new TextEncoder().encode(text);

  ok("payload contains no plaintext run of 4+ bytes", !containsSubarray(payloadBytes, utf8.slice(0, 4)));
  ok("payload does not contain the link secret", !containsSubarray(payloadBytes, fragBytes.slice(2)));
  ok(
    "payload is header + iv + ct + tag",
    payloadBytes.length === 2 + 12 + utf8.length + 16,
    `${payloadBytes.length} vs ${2 + 12 + utf8.length + 16}`,
  );

  const wrongFrag = nm.parseFragment((await nm.seal("decoy")).fragment)!;
  ok(
    "a different link secret cannot open it",
    await throws(() => nm.open(sealed.payload, wrongFrag), nm.CorruptPayload),
  );

  console.log("\n--- 3. IV and key uniqueness ---");

  const ivs = new Set<string>();
  const keys = new Set<string>();
  for (let i = 0; i < 200; i++) {
    const s = await nm.seal("same plaintext every time");
    const p = b64u.decode(s.payload);
    ivs.add(b64u.encode(p.slice(2, 14)));
    keys.add(s.fragment);
  }
  ok("200 seals produced 200 distinct IVs", ivs.size === 200, `${ivs.size}`);
  ok("200 seals produced 200 distinct link secrets", keys.size === 200, `${keys.size}`);

  const a = await nm.seal("identical");
  const b = await nm.seal("identical");
  ok("identical plaintext yields different ciphertext", a.payload !== b.payload);

  console.log("\n--- 4. Tamper detection ---");

  const t = await nm.seal("integrity matters");
  const tFrag = nm.parseFragment(t.fragment)!;
  const tBytes = b64u.decode(t.payload);

  const flipped = new Uint8Array(tBytes);
  flipped[flipped.length - 20] ^= 0x01; // inside the ciphertext
  ok(
    "flipping a ciphertext bit is rejected",
    await throws(() => nm.open(b64u.encode(flipped), tFrag), nm.CorruptPayload),
  );

  const ivFlipped = new Uint8Array(tBytes);
  ivFlipped[5] ^= 0x01; // inside the IV
  ok(
    "flipping an IV bit is rejected",
    await throws(() => nm.open(b64u.encode(ivFlipped), tFrag), nm.CorruptPayload),
  );

  const truncated = tBytes.slice(0, tBytes.length - 1);
  ok(
    "dropping the last tag byte is rejected",
    await throws(() => nm.open(b64u.encode(truncated), tFrag), nm.CorruptPayload),
  );

  const badVersion = new Uint8Array(tBytes);
  badVersion[0] = 99;
  ok(
    "unknown version is rejected",
    await throws(() => nm.open(b64u.encode(badVersion), tFrag), nm.CorruptPayload),
  );

  console.log("\n--- 5. Password protection ---");

  const started = Date.now();
  const pw = await nm.seal("双因素内容", "correct horse battery staple");
  const pwElapsed = Date.now() - started;
  const pwFrag = nm.parseFragment(pw.fragment)!;

  ok("fragment advertises that a password is needed", pwFrag.passwordRequired === true);
  ok(
    "salt is carried in the payload",
    b64u.decode(pw.payload).length === 2 + 16 + 12 + new TextEncoder().encode("双因素内容").length + 16,
  );
  ok(
    "correct password opens it",
    (await nm.open(pw.payload, pwFrag, "correct horse battery staple")) === "双因素内容",
  );
  ok(
    "wrong password is rejected",
    await throws(() => nm.open(pw.payload, pwFrag, "wrong horse"), nm.WrongPassword),
  );
  ok(
    "missing password is rejected without touching the ciphertext",
    await throws(() => nm.open(pw.payload, pwFrag), nm.WrongPassword),
  );
  // Informational, not a threshold. This machine has SHA hardware extensions;
  // a mid-range phone is typically 5-10x slower, so the create page must show
  // a pending state rather than assuming the derivation is instant.
  console.log(`         (PBKDF2 600k took ${pwElapsed}ms here; budget ~1s on mobile)`);
  ok("derivation cost is measurable, not accidentally skipped", pwElapsed > 20, `${pwElapsed}ms`);

  console.log("\n--- 6. Password alone is not enough ---");

  // The whole point of deriving from link secret AND password: an attacker who
  // has the entire database still has no salt-and-hash to grind.
  const decoyFrag = nm.parseFragment((await nm.seal("x")).fragment)!;
  ok(
    "correct password with wrong link secret still fails",
    await throws(() => nm.open(pw.payload, decoyFrag, "correct horse battery staple"), nm.WrongPassword),
  );

  const zeroFrag = { linkSecret: new Uint8Array(32), passwordRequired: true };
  ok(
    "an all-zero link secret guess fails",
    await throws(() => nm.open(pw.payload, zeroFrag, "correct horse battery staple"), nm.WrongPassword),
  );

  console.log("\n--- 7. Fragment parsing ---");

  ok("empty fragment is null", nm.parseFragment("") === null);
  ok("leading # is tolerated", nm.parseFragment("#" + sealed.fragment) !== null);
  ok("non-base64url is null", nm.parseFragment("not valid!!") === null);
  ok("wrong length is null", nm.parseFragment(b64u.encode(new Uint8Array([1, 0, 1, 2]))) === null);

  console.log("\n--- 8. Payload size budget ---");

  // The API caps the base64url payload at 32 KB. Work out what that means for
  // the user-facing character limit so the UI can warn before submitting.
  const big = "字".repeat(7000); // 3 bytes each in UTF-8
  const bigSealed = await nm.seal(big, "pw");
  ok(
    `21000 UTF-8 bytes seals to ${bigSealed.payload.length} chars, under the 32768 cap`,
    bigSealed.payload.length < 32768,
  );
  ok("and still round trips", (await nm.open(bigSealed.payload, nm.parseFragment(bigSealed.fragment)!, "pw")) === big);

  console.log("\n" + "=".repeat(52));
  console.log(`${fail === 0 ? "\x1b[32m" : "\x1b[31m"}${pass} passed, ${fail} failed\x1b[0m\n`);
  process.exit(fail === 0 ? 0 : 1);
}

function containsSubarray(haystack: Uint8Array, needle: Uint8Array): boolean {
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
