"use client";

import { FormEvent, useEffect, useState } from "react";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { Agency } from "@/lib/operations-types";

type ProfileFields = {
  name: string;
  phone: string;
  shortDescription: string;
  website: string;
};

function fieldsFromAgency(agency: Agency): ProfileFields {
  return {
    name: agency.name,
    phone: agency.phone ?? "",
    shortDescription: agency.short_description ?? "",
    website: agency.website ?? "",
  };
}

export function AgencyProfileForm() {
  const [agencyId, setAgencyId] = useState<string | null>(null);
  const [fields, setFields] = useState<ProfileFields | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const [loading, setLoading] = useState(true);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      const currentAgencyId = activeAgencyId();
      if (!currentAgencyId) {
        if (!cancelled) {
          setError({ code: "TENANT_REQUIRED", message: "Sign in and select an agency before editing its profile." });
          setLoading(false);
        }
        return;
      }

      setAgencyId(currentAgencyId);
      try {
        const response = await apiQuery<{ data: Agency }>("/api/v1/agency", currentAgencyId);
        if (!cancelled) {
          setFields(fieldsFromAgency(response.data));
          setError(null);
        }
      } catch (caught) {
        if (!cancelled) setError(caught as ApiError);
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, 0);

    return () => { cancelled = true; window.clearTimeout(timer); };
  }, []);

  function updateField(field: keyof ProfileFields, value: string) {
    setFields((current) => current ? { ...current, [field]: value } : current);
    setSaved(false);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSaved(false);

    if (!agencyId || !fields) {
      setError({ code: "TENANT_REQUIRED", message: "The active agency profile must finish loading before it can be saved." });
      return;
    }

    setPending(true);
    try {
      const response = await apiMutation<{ data: Agency }>(
        "/api/v1/agency",
        {
          name: fields.name,
          short_description: fields.shortDescription || null,
          phone: fields.phone || null,
          website: fields.website || null,
        },
        { method: "PATCH", agencyId },
      );
      setFields(fieldsFromAgency(response.data));
      setSaved(true);
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setPending(false);
    }
  }

  if (loading) return <div className="profile-form__state" role="status"><span className="inline-spinner" /> Loading the active agency profile…</div>;
  if (!fields) return <div className="profile-form__state" role="alert"><p>{error?.message ?? "The agency profile could not be loaded."}</p><button className="button button--outline" type="button" onClick={() => window.location.reload()}>Try again</button></div>;

  return (
    <form className="profile-form" onSubmit={submit}>
      {error ? <div className="form-alert" role="alert">{error.message}</div> : null}
      {saved ? <div className="form-success" role="status">Agency profile saved.</div> : null}
      <div className="profile-form__grid">
        <label><span>Public agency name</span><input name="name" value={fields.name} onChange={(event) => updateField("name", event.target.value)} minLength={2} maxLength={160} required /></label>
        <label><span>Telephone</span><input name="phone" value={fields.phone} onChange={(event) => updateField("phone", event.target.value)} type="tel" autoComplete="tel" placeholder="Enter the public telephone number" /></label>
        <label className="profile-form__wide"><span>Short description</span><textarea name="short_description" value={fields.shortDescription} onChange={(event) => updateField("shortDescription", event.target.value)} maxLength={320} rows={4} placeholder="Describe the agency’s public focus and service area" /></label>
        <label className="profile-form__wide"><span>Website</span><input name="website" value={fields.website} onChange={(event) => updateField("website", event.target.value)} type="url" placeholder="https://" /></label>
      </div>
      <div className="profile-form__actions"><button className="button button--primary" type="submit" disabled={pending}>{pending ? "Saving…" : "Save agency profile"}</button></div>
    </form>
  );
}
