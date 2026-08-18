const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type ApiError = {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
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
  body: Record<string, unknown>,
  options: { method?: "POST" | "PATCH"; agencyId?: string } = {},
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
      "Content-Type": "application/json",
      ...(options.agencyId ? { "Agency-ID": options.agencyId } : {}),
      ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
    },
    body: JSON.stringify(body),
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
