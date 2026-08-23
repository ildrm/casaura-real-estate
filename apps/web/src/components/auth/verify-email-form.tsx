"use client";

import { useState } from "react";
import Link from "next/link";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function VerifyEmailForm() {
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function resend() {
    setPending(true);
    setError(null);
    try {
      await apiMutation("/api/v1/auth/email/verification-notification", {});
      setSent(true);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return <div className="auth-form">
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    {sent ? <div className="identity-success" role="status">A fresh verification link has been requested.</div> : null}
    <button className="button button--primary auth-submit" type="button" onClick={resend} disabled={pending}>{pending ? "Sending…" : "Resend verification email"}</button>
    <p className="auth-switch"><Link href="/sign-in">Use a different account</Link></p>
  </div>;
}
