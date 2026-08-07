/**
 * base64url, unpadded (RFC 4648 §5).
 *
 * The server never decodes anything but the payload, and never re-encodes a
 * key, so these must agree exactly with NoteStore's PHP equivalents.
 */

/**
 * Byte arrays are pinned to a plain ArrayBuffer rather than ArrayBufferLike.
 * TypeScript 5.9 will not accept a possibly-SharedArrayBuffer view as a
 * WebCrypto BufferSource, and threading the narrower type through from the
 * start is cleaner than casting at each subtle.* call.
 */
export type Bytes = Uint8Array<ArrayBuffer>;

export function encode(bytes: Bytes): string {
  let binary = "";
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

export function decode(text: string): Bytes {
  if (!/^[A-Za-z0-9_-]*$/.test(text)) {
    throw new Error("not base64url");
  }
  const padded = text.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (text.length % 4)) % 4);
  const binary = atob(padded);
  const out = new Uint8Array(new ArrayBuffer(binary.length));
  for (let i = 0; i < binary.length; i++) {
    out[i] = binary.charCodeAt(i);
  }
  return out;
}
