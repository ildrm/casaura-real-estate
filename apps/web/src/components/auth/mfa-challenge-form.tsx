"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function MfaChallengeForm({ nextPath }: { nextPath: string }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      await apiMutation("/api/v1/auth/mfa/challenge", { code: String(form.get("code") ?? "") });
      router.push(nextPath.startsWith("/") ? nextPath : "/agency/dashboard");
      router.refresh();
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return <form className="auth-form" onSubmit={submit} noValidate>
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    <label><span>Authentication code</span><input name="code" type="text" inputMode="numeric" autoComplete="one-time-code" minLength={6} maxLength={32} required autoFocus /><small>Enter the six-digit authenticator code or one unused recovery code.</small></label>
    <button className="button button--primary auth-submit" type="submit" disabled={pending}>{pending ? "Verifying…" : "Verify and continue"}</button>
  </form>;
}
