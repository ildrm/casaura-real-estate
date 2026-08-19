const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type ApiError = {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
  current_version?: number;
  checklist?: Array<{ key: string; complete: boolean; message: string }>;
};

function cookieValue(name: string): string | undefined {
  if (typeof document === "undefined") return undefined;

  return document.cookie
    .split("; ")
    .find((entry) => entry.startsWith(`${name}=`))
    ?.split("=")
    .slice(1)
    .join("=");
}

export async function apiMutation<T>(
  path: string,
  body: Record<string, unknown> | FormData,
  options: { method?: "POST" | "PUT" | "PATCH" | "DELETE"; agencyId?: string; idempotencyKey?: string } = {},
): Promise<T> {
  const csrfResponse = await fetch(`${API_URL}/sanctum/csrf-cookie`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  if (!csrfResponse.ok) {
    throw { code: "CSRF_UNAVAILABLE", message: "The secure sign-in service is unavailable." } satisfies ApiError;
  }

  const xsrf = cookieValue("XSRF-TOKEN");
  const response = await fetch(`${API_URL}${path}`, {
    method: options.method ?? "POST",
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(body instanceof FormData ? {} : { "Content-Type": "application/json" }),
      ...(options.agencyId ? { "Agency-ID": options.agencyId } : {}),
      ...(options.idempotencyKey ? { "Idempotency-Key": options.idempotencyKey } : {}),
      ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
    },
    body: body instanceof FormData ? body : JSON.stringify(body),
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: ApiError } | null;
    throw payload?.error ?? {
      code: "REQUEST_FAILED",
      message: "The request could not be completed. Please try again.",
    } satisfies ApiError;
  }

  if (response.status === 204) return undefined as T;

  return (await response.json()) as T;
}

export async function apiQuery<T>(path: string, agencyId?: string): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(agencyId ? { "Agency-ID": agencyId } : {}),
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: ApiError } | null;
    throw payload?.error ?? {
      code: "REQUEST_FAILED",
      message: "The request could not be completed. Please try again.",
    } satisfies ApiError;
  }

  return (await response.json()) as T;
}

export function activeAgencyId(): string | null {
  if (typeof window === "undefined") return null;

  return window.localStorage.getItem("casaura.activeAgencyId");
}

export async function publicApiQuery<T>(path: string): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  });
  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: ApiError } | null;
    throw payload?.error ?? { code: "REQUEST_FAILED", message: "Property data is temporarily unavailable." } satisfies ApiError;
  }

  return (await response.json()) as T;
}

export async function apiTextQuery(path: string, agencyId?: string): Promise<{ body: string; contentType: string }> {
  const response = await fetch(`${API_URL}${path}`, {
    credentials: "include",
    headers: {
      Accept: "text/calendar, text/plain, application/json",
      ...(agencyId ? { "Agency-ID": agencyId } : {}),
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { error?: ApiError } | null;
    throw payload?.error ?? {
      code: "REQUEST_FAILED",
      message: "The requested file could not be prepared.",
    } satisfies ApiError;
  }

  return {
    body: await response.text(),
    contentType: response.headers.get("content-type") ?? "text/plain;charset=utf-8",
  };
}

export function publicAssetUrl(path?: string | null): string | null {
  if (!path) return null;
  if (/^https?:\/\//.test(path)) return path;
  return `${API_URL}${path}`;
}
