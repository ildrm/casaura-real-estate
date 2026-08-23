"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { apiMutation, type ApiError } from "@/lib/api-client";

type AcceptanceResponse = {
  data: {
    email_verified_at: string | null;
    memberships: Array<{ status: string; agency: { id: string } }>;
  };
};

export function InvitationAcceptanceForm({ token }: { token: string }) {
  const router = useRouter();
  const [error, setError] = useState<ApiError | null>(token ? null : {
    code: "INVITATION_INVALID",
    message: "This invitation link is incomplete.",
  });
  const [pending, setPending] = useState(false);

  async function accept(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token) return;
    const values = new FormData(event.currentTarget);
    setPending(true);
    setError(null);
    try {
      const response = await apiMutation<AcceptanceResponse>(`/api/v1/auth/invitations/${encodeURIComponent(token)}/accept`, {
        password: String(values.get("password") ?? ""),
        password_confirmation: String(values.get("password_confirmation") ?? ""),
      });
      const agency = response.data.memberships.find((membership) => membership.status === "active")?.agency.id;
      if (agency) window.localStorage.setItem("casaura.activeAgencyId", agency);
      router.replace("/agency/dashboard");
      router.refresh();
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return <form className="auth-form" onSubmit={accept} noValidate>
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    <p>If you already have a Casaura account, sign in first and leave the password fields blank. New users must choose a password.</p>
    <label><span>New password</span><input name="password" type="password" autoComplete="new-password" minLength={12} /><small>{error?.fields?.password?.[0] ?? "At least 12 characters with upper/lowercase letters and a number."}</small></label>
    <label><span>Confirm password</span><input name="password_confirmation" type="password" autoComplete="new-password" minLength={12} /></label>
    <button className="button button--primary auth-submit" type="submit" disabled={pending || !token}>{pending ? "Accepting invitation…" : "Accept invitation"}</button>
    <p className="auth-switch">Already have an account? <Link href={`/sign-in?next=${encodeURIComponent(`/invitations/accept?token=${token}`)}`}>Sign in first</Link></p>
  </form>;
}
