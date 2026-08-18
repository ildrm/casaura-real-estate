"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiMutation, type ApiError } from "@/lib/api-client";

type PrincipalResponse = {
  data: {
    memberships: Array<{ agency: { id: string } }>;
  };
};

export function SignInForm({ nextPath = "/agency/dashboard" }: { nextPath?: string }) {
  const router = useRouter();
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);

    try {
      const response = await apiMutation<PrincipalResponse>("/api/v1/auth/login", {
        email: String(form.get("email") ?? ""),
        password: String(form.get("password") ?? ""),
        remember: form.get("remember") === "on",
      });
      const agencyId = response.data.memberships.at(0)?.agency.id;
      if (agencyId) window.localStorage.setItem("casaura.activeAgencyId", agencyId);
      router.push(nextPath.startsWith("/") ? nextPath : "/agency/dashboard");
      router.refresh();
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return (
    <form className="auth-form" onSubmit={submit} noValidate>
      {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
      <label>
        <span>Email address</span>
        <input name="email" type="email" autoComplete="email" required />
        {error?.fields?.email?.[0] ? <small>{error.fields.email[0]}</small> : null}
      </label>
      <label>
        <span>Password</span>
        <input name="password" type="password" autoComplete="current-password" required />
        {error?.fields?.password?.[0] ? <small>{error.fields.password[0]}</small> : null}
      </label>
      <div className="form-options">
        <label className="check-field"><input name="remember" type="checkbox" /> <span>Keep me signed in</span></label>
        <Link href="mailto:support@casaura.test?subject=Password%20help">Forgot password?</Link>
      </div>
      <button className="button button--primary auth-submit" type="submit" disabled={pending}>
        {pending ? "Signing in…" : "Sign in"}
      </button>
      <p className="auth-switch">New agency? <Link href="/register/agency">Create your free workspace</Link></p>
    </form>
  );
}
