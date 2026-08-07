/**
 * Every bit of confidentiality this service offers comes from this file.
 * There is deliberately no server-side counterpart.
 *
 * ---------------------------------------------------------------------------
 * Wire format
 * ---------------------------------------------------------------------------
 *
 * Fragment (never sent to the server), base64url of:
 *
 *     [0]      version = 1
 *     [1]      flags   — bit 0 set when a password is required
 *     [2..33]  32 random bytes, the link secret
 *
 * The flags byte lets the reading page know a password is needed *before* it
 * asks the server to destroy anything. Without it we would have to fetch and
 * destroy the note first and only then discover we cannot open it.
 *
 * Payload (stored server-side as opaque bytes), base64url of:
 *
 *     [0]      version = 1
 *     [1]      flags
 *     [2..17]  16-byte PBKDF2 salt   (only when the password flag is set)
 *     then     12-byte AES-GCM IV
 *     then     ciphertext || 16-byte GCM tag  (WebCrypto returns them joined)
 *
 * ---------------------------------------------------------------------------
 * Why derivation rather than key wrapping
 * ---------------------------------------------------------------------------
 *
 * The original design wrapped a master key with a password-derived key and
 * stored the wrapped key alongside the ciphertext. That hands the server an
 * offline oracle: it holds the wrapped key and the salt, so it can grind a
 * human-chosen password at its leisure. 600k PBKDF2 iterations raises the cost
 * but does not change the outcome for a weak password, and "the server can
 * eventually read this note" is exactly the property the project exists to
 * deny.
 *
 * Instead the content key is derived from the link secret and the password
 * *together*:
 *
 *     contentKey = HKDF-SHA256(ikm = linkSecret || PBKDF2(password, salt))
 *
 * With no password the PBKDF2 half is simply absent. Because the link secret
 * never reaches the server, an attacker holding the entire database has
 * nothing to grind against — a weak password stays safe, and the password is a
 * genuine second factor rather than a substitute for the link.
 *
 * The trade-off: no cheap "is this password right?" check. A wrong password
 * surfaces as a GCM tag failure on the content itself. Since retries happen
 * locally on already-fetched ciphertext, this costs nothing but a slightly
 * less specific error message, and it removes a verification oracle.
 */

import * as b64u from "./base64url";
import type { Bytes } from "./base64url";

export const VERSION = 1;
export const FLAG_PASSWORD = 0x01;

const KEY_BYTES = 32;
const IV_BYTES = 12;
const SALT_BYTES = 16;
const PBKDF2_ITERATIONS = 600_000;
const HKDF_INFO = "note.my content key v1";

export interface Fragment {
  linkSecret: Bytes;
  passwordRequired: boolean;
}

export interface Sealed {
  /** base64url, goes to the server */
  payload: string;
  /** base64url, goes in the URL fragment */
  fragment: string;
}

/** Guard against a browser without the primitives we need. */
export function isSupported(): boolean {
  return (
    typeof crypto !== "undefined" &&
    typeof crypto.subtle?.deriveBits === "function" &&
    typeof crypto.getRandomValues === "function"
  );
}

export async function seal(plaintext: string, password?: string): Promise<Sealed> {
  const linkSecret = crypto.getRandomValues(new Uint8Array(new ArrayBuffer(KEY_BYTES)));
  const iv = crypto.getRandomValues(new Uint8Array(new ArrayBuffer(IV_BYTES)));
  const usePassword = typeof password === "string" && password.length > 0;
  const flags = usePassword ? FLAG_PASSWORD : 0;
  const salt: Bytes = usePassword
    ? crypto.getRandomValues(new Uint8Array(new ArrayBuffer(SALT_BYTES)))
    : new Uint8Array(new ArrayBuffer(0));

  const key = await deriveContentKey(linkSecret, usePassword ? password! : undefined, salt);

  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt(
      { name: "AES-GCM", iv, tagLength: 128 },
      key,
      new TextEncoder().encode(plaintext),
    ),
  );

  const header = Uint8Array.from([VERSION, flags]) as Bytes;
  const payload = concat(header, salt, iv, ciphertext as Bytes);
  const fragment = concat(header, linkSecret);

  return { payload: b64u.encode(payload), fragment: b64u.encode(fragment) };
}

export class WrongPassword extends Error {}
export class CorruptPayload extends Error {}

export async function open(payloadB64: string, fragment: Fragment, password?: string): Promise<string> {
  let bytes: Bytes;
  try {
    bytes = b64u.decode(payloadB64);
  } catch {
    throw new CorruptPayload("payload is not base64url");
  }

  if (bytes.length < 2 || bytes[0] !== VERSION) {
    throw new CorruptPayload("unsupported payload version");
  }

  const usePassword = (bytes[1] & FLAG_PASSWORD) !== 0;
  let offset = 2;

  let salt: Bytes = new Uint8Array(new ArrayBuffer(0));
  if (usePassword) {
    if (typeof password !== "string" || password.length === 0) {
      throw new WrongPassword("password required");
    }
    salt = bytes.slice(offset, offset + SALT_BYTES);
    offset += SALT_BYTES;
  }

  const iv = bytes.slice(offset, offset + IV_BYTES);
  offset += IV_BYTES;
  const ciphertext = bytes.slice(offset);

  // 16 bytes of GCM tag plus at least one byte of content.
  if (iv.length !== IV_BYTES || ciphertext.length < 17) {
    throw new CorruptPayload("truncated payload");
  }

  const key = await deriveContentKey(fragment.linkSecret, usePassword ? password : undefined, salt);

  try {
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv, tagLength: 128 }, key, ciphertext);
    return new TextDecoder().decode(plain);
  } catch {
    // A GCM failure is indistinguishable between a wrong password, a wrong
    // link secret and a tampered ciphertext — by construction, not by
    // oversight. Report the case the user can actually act on.
    if (usePassword) {
      throw new WrongPassword("wrong password");
    }
    throw new CorruptPayload("authentication failed");
  }
}

export function parseFragment(raw: string): Fragment | null {
  const text = raw.startsWith("#") ? raw.slice(1) : raw;
  if (text === "") return null;

  let bytes: Bytes;
  try {
    bytes = b64u.decode(text);
  } catch {
    return null;
  }

  if (bytes.length !== 2 + KEY_BYTES || bytes[0] !== VERSION) {
    return null;
  }

  return {
    passwordRequired: (bytes[1] & FLAG_PASSWORD) !== 0,
    linkSecret: bytes.slice(2),
  };
}

/**
 * contentKey = HKDF-SHA256(linkSecret || PBKDF2(password, salt))
 *
 * HKDF is used for extraction rather than feeding the raw secret to AES
 * directly so that the two inputs are mixed rather than concatenated into a
 * key, and so the salt is bound into the derivation.
 */
async function deriveContentKey(
  linkSecret: Bytes,
  password: string | undefined,
  salt: Bytes,
): Promise<CryptoKey> {
  let ikm: Bytes = linkSecret;

  if (password !== undefined) {
    const passwordKey = await crypto.subtle.importKey(
      "raw",
      new TextEncoder().encode(password.normalize("NFC")),
      "PBKDF2",
      false,
      ["deriveBits"],
    );
    const stretched: Bytes = new Uint8Array(
      await crypto.subtle.deriveBits(
        { name: "PBKDF2", salt, iterations: PBKDF2_ITERATIONS, hash: "SHA-256" },
        passwordKey,
        256,
      ),
    );
    ikm = concat(linkSecret, stretched);
  }

  const hkdfKey = await crypto.subtle.importKey("raw", ikm, "HKDF", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "HKDF", hash: "SHA-256", salt, info: new TextEncoder().encode(HKDF_INFO) },
    hkdfKey,
    256,
  );

  return crypto.subtle.importKey("raw", bits, { name: "AES-GCM", length: 256 }, false, [
    "encrypt",
    "decrypt",
  ]);
}

function concat(...parts: Bytes[]): Bytes {
  const total = parts.reduce((n, p) => n + p.length, 0);
  const out = new Uint8Array(new ArrayBuffer(total));
  let at = 0;
  for (const p of parts) {
    out.set(p, at);
    at += p.length;
  }
  return out;
}
