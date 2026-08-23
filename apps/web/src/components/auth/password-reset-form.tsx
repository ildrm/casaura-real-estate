"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function PasswordResetForm({ token, email }: { token: string; email: string }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      await apiMutation("/api/v1/auth/reset-password", {
        token,
        email,
        password: String(form.get("password") ?? ""),
        password_confirmation: String(form.get("password_confirmation") ?? ""),
      });
      router.push("/sign-in?reset=complete");
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  if (!token || !email) {
    return <div className="form-alert" role="alert">This recovery link is incomplete. Request a new one from the <Link href="/forgot-password">password recovery page</Link>.</div>;
  }

  return <form className="auth-form" onSubmit={submit} noValidate>
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    <label><span>Account email</span><input value={email} type="email" readOnly /></label>
    <label><span>New password</span><input name="password" type="password" minLength={12} autoComplete="new-password" required /><small>Use 12+ characters with upper and lower case letters and a number.</small></label>
    <label><span>Confirm new password</span><input name="password_confirmation" type="password" minLength={12} autoComplete="new-password" required /></label>
    <button className="button button--primary auth-submit" type="submit" disabled={pending}>{pending ? "Resetting…" : "Reset password"}</button>
  </form>;
}
