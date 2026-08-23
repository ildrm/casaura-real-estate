"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiMutation, type ApiError } from "@/lib/api-client";

type PrincipalResponse = {
  data: { email_verified_at: string | null; memberships: Array<{ agency: { id: string } }> };
};

const LEGAL_VERSION = process.env.NEXT_PUBLIC_LEGAL_DOCUMENT_VERSION ?? "2026-08-22";

export function AgencyRegistrationForm() {
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  function continueToOwner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (event.currentTarget.reportValidity()) setStep(2);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(event.currentTarget);

    try {
      const response = await apiMutation<PrincipalResponse>("/api/v1/auth/register-agency", {
        agency_name: String(form.get("agency_name") ?? ""),
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
        name: String(form.get("name") ?? ""),
        email: String(form.get("email") ?? ""),
        password: String(form.get("password") ?? ""),
        password_confirmation: String(form.get("password_confirmation") ?? ""),
        consent: form.get("consent") === "on",
        consent_version: LEGAL_VERSION,
      });
      const agencyId = response.data.memberships.at(0)?.agency.id;
      if (agencyId) window.localStorage.setItem("casaura.activeAgencyId", agencyId);
      router.push(response.data.email_verified_at ? "/mfa/setup" : "/verify-email");
      router.refresh();
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return (
    <form className="auth-form registration-form" onSubmit={step === 1 ? continueToOwner : submit} noValidate>
      <div className="form-progress" aria-label={`Step ${step} of 2`}>
        <span className="is-complete">Agency</span><i /><span className={step === 2 ? "is-complete" : undefined}>Owner account</span>
      </div>
      {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
      <div className={step === 1 ? "form-step" : "form-step is-hidden"} aria-hidden={step !== 1}>
        <label>
          <span>Agency name</span>
          <input name="agency_name" type="text" minLength={2} maxLength={160} autoComplete="organization" required readOnly={step !== 1} />
          <small>This becomes your public storefront name. You can add legal details during setup.</small>
        </label>
        <button className="button button--primary auth-submit" type="submit">Continue</button>
      </div>
      <div className={step === 2 ? "form-step" : "form-step is-hidden"} aria-hidden={step !== 2}>
        <label>
          <span>Your name</span>
          <input name="name" type="text" minLength={2} maxLength={120} autoComplete="name" required disabled={step !== 2} />
        </label>
        <label>
          <span>Work email</span>
          <input name="email" type="email" autoComplete="email" required disabled={step !== 2} />
          {error?.fields?.email?.[0] ? <small>{error.fields.email[0]}</small> : null}
        </label>
        <label>
          <span>Password</span>
          <input name="password" type="password" minLength={12} autoComplete="new-password" required disabled={step !== 2} />
          <small>Use 12+ characters with upper and lower case letters and a number.</small>
        </label>
        <label>
          <span>Confirm password</span>
          <input name="password_confirmation" type="password" minLength={12} autoComplete="new-password" required disabled={step !== 2} />
        </label>
        <label className="check-field consent-field">
          <input name="consent" type="checkbox" required disabled={step !== 2} />
          <span>I agree to the <Link href="/terms">Terms</Link> and acknowledge the <Link href="/privacy">Privacy Policy</Link>.</span>
        </label>
        <div className="registration-actions">
          <button className="button button--outline" type="button" onClick={() => setStep(1)}>Back</button>
          <button className="button button--primary" type="submit" disabled={pending}>{pending ? "Creating workspace…" : "Create agency workspace"}</button>
        </div>
      </div>
      <p className="auth-switch">Already registered? <Link href="/sign-in">Sign in</Link></p>
    </form>
  );
}
