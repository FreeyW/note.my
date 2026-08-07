/**
 * Thin fetch wrapper. Same-origin only — the CSP's connect-src 'self' would
 * block anything else anyway, and there is nowhere else to go.
 */

export type ApiError = "not_found" | "rate_limited" | "quota" | "too_large" | "network" | "server";

export class ApiFailure extends Error {
  constructor(public readonly kind: ApiError) {
    super(kind);
  }
}

export interface CreateResult {
  id: string;
}

export async function createNote(payload: string, ttl: string): Promise<CreateResult> {
  const res = await post("/api/note", { payload, ttl });
  return { id: String((res as { id: string }).id) };
}

/**
 * Destroys the note. There is no way to undo this and no second attempt —
 * callers must be certain the user has asked for it.
 */
export async function takeNote(id: string): Promise<string> {
  const res = await post(`/api/note/${encodeURIComponent(id)}`, undefined);
  return String((res as { payload: string }).payload);
}

export async function reportNote(id: string, reason: string): Promise<void> {
  await post("/api/report", { id, reason });
}

async function post(path: string, body: unknown): Promise<unknown> {
  let res: Response;
  try {
    res = await fetch(path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: body === undefined ? "{}" : JSON.stringify(body),
      // No cookies exist, but be explicit: nothing ambient should ride along.
      credentials: "omit",
      cache: "no-store",
      redirect: "error",
    });
  } catch {
    throw new ApiFailure("network");
  }

  if (res.ok) {
    try {
      return await res.json();
    } catch {
      throw new ApiFailure("server");
    }
  }

  if (res.status === 404) throw new ApiFailure("not_found");
  if (res.status === 413) throw new ApiFailure("too_large");
  if (res.status === 429) {
    let kind: ApiError = "rate_limited";
    try {
      const body = (await res.json()) as { error?: string };
      if (body.error === "quota_exceeded") kind = "quota";
    } catch {
      /* keep the default */
    }
    throw new ApiFailure(kind);
  }

  throw new ApiFailure("server");
}
