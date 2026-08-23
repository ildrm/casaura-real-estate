"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function PasswordRecoveryForm() {
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      await apiMutation("/api/v1/auth/forgot-password", { email: String(form.get("email") ?? "") });
      setSent(true);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  if (sent) {
    return <div className="identity-status" role="status"><h2>Check your inbox</h2><p>If an eligible account exists, recovery instructions will be sent.</p><Link className="button button--outline" href="/sign-in">Return to sign in</Link></div>;
  }

  return <form className="auth-form" onSubmit={submit} noValidate>
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    <label><span>Email address</span><input name="email" type="email" autoComplete="email" required /></label>
    <button className="button button--primary auth-submit" type="submit" disabled={pending}>{pending ? "Sending…" : "Send recovery link"}</button>
    <p className="auth-switch"><Link href="/sign-in">Return to sign in</Link></p>
  </form>;
}
