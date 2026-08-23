"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { apiMutation, type ApiError } from "@/lib/api-client";

type SetupResponse = { data: { secret: string; provisioning_uri: string } };
type ConfirmResponse = { data: { recovery_codes: string[] } };

export function MfaSetupForm() {
  const router = useRouter();
  const [setup, setSetup] = useState<SetupResponse["data"] | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function begin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      const response = await apiMutation<SetupResponse>("/api/v1/auth/mfa/setup", { password: String(form.get("password") ?? "") });
      setSetup(response.data);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      const response = await apiMutation<ConfirmResponse>("/api/v1/auth/mfa/confirm", { code: String(form.get("code") ?? "") });
      setRecoveryCodes(response.data.recovery_codes);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  if (recoveryCodes.length) {
    return <div className="identity-status"><h2>Save your recovery codes</h2><p>Each code works once. Store them somewhere separate from your authenticator.</p><ul className="recovery-code-list">{recoveryCodes.map((code) => <li key={code}><code>{code}</code></li>)}</ul><button className="button button--primary auth-submit" type="button" onClick={() => { router.push("/agency/dashboard"); router.refresh(); }}>I saved these codes</button></div>;
  }

  if (!setup) {
    return <form className="auth-form" onSubmit={begin} noValidate>
      {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
      <label><span>Current password</span><input name="password" type="password" autoComplete="current-password" required /></label>
      <button className="button button--primary auth-submit" type="submit" disabled={pending}>{pending ? "Preparing…" : "Start MFA setup"}</button>
    </form>;
  }

  return <form className="auth-form" onSubmit={confirm} noValidate>
    {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
    <div className="identity-status"><h2>Add Casaura to your authenticator</h2><p>Scan or enter this secret manually, then submit the current six-digit code.</p><code className="mfa-secret">{setup.secret}</code><a className="auth-technical-link" href={setup.provisioning_uri}>Open authenticator link</a></div>
    <label><span>Six-digit code</span><input name="code" type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required autoFocus /></label>
    <button className="button button--primary auth-submit" type="submit" disabled={pending}>{pending ? "Confirming…" : "Enable MFA"}</button>
  </form>;
}
