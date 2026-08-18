"use client";

import { FormEvent, useState } from "react";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function AgencyProfileForm() {
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const [pending, setPending] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSaved(false);
    const agencyId = window.localStorage.getItem("casaura.activeAgencyId");

    if (!agencyId) {
      setError({ code: "TENANT_REQUIRED", message: "Sign in and select an agency before editing its profile." });
      return;
    }

    const form = new FormData(event.currentTarget);
    setPending(true);

    try {
      await apiMutation(
        "/api/v1/agency",
        {
          name: String(form.get("name") ?? ""),
          short_description: String(form.get("short_description") ?? ""),
          phone: String(form.get("phone") ?? ""),
          website: String(form.get("website") ?? ""),
        },
        { method: "PATCH", agencyId },
      );
      setSaved(true);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  return (
    <form className="profile-form" onSubmit={submit}>
      {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
      {saved ? <div className="form-success" role="status">Agency profile saved.</div> : null}
      <div className="profile-form__grid">
        <label><span>Public agency name</span><input name="name" defaultValue="Greenway Realty" minLength={2} maxLength={160} required /></label>
        <label><span>Telephone</span><input name="phone" type="tel" autoComplete="tel" placeholder="+1 512 555 0142" /></label>
        <label className="profile-form__wide"><span>Short description</span><textarea name="short_description" maxLength={320} rows={4} defaultValue="Local expertise, clear guidance, and homes selected around the way you want to live." /></label>
        <label className="profile-form__wide"><span>Website</span><input name="website" type="url" placeholder="https://greenway.example" /></label>
      </div>
      <div className="profile-form__actions"><button className="button button--primary" type="submit" disabled={pending}>{pending ? "Saving…" : "Save agency profile"}</button></div>
    </form>
  );
}
