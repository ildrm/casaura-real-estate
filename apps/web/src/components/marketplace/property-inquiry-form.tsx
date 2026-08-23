"use client";

import { useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, type ApiError } from "@/lib/api-client";

type InquiryResult = { data: { id: string; conversation_id: string | null } };

function inquiryKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `inquiry-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function PropertyInquiryForm({ listingId, agencyName }: { listingId: string; agencyName: string }) {
  const [idempotencyKey, setIdempotencyKey] = useState(inquiryKey);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ kind: "success" | "error"; message: string } | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const values = new FormData(form);
    setBusy(true);
    setResult(null);
    try {
      await apiMutation<InquiryResult>(`/api/v1/public/listings/${listingId}/leads`, {
        name: String(values.get("name") ?? ""),
        email: String(values.get("email") ?? ""),
        phone: String(values.get("phone") ?? "") || null,
        message: String(values.get("message") ?? ""),
        consent: values.get("consent") === "on",
      }, { idempotencyKey });
      setResult({ kind: "success", message: `Your inquiry has been sent to ${agencyName} with your contact details. Signed-in users can continue from Account.` });
      form.reset();
      setIdempotencyKey(inquiryKey());
    } catch (caught) {
      const error = caught as ApiError;
      const fieldMessage = error.fields ? Object.values(error.fields).flat()[0] : null;
      setResult({ kind: "error", message: fieldMessage ?? error.message });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="property-inquiry" id="property-inquiry" onSubmit={(event) => void submit(event)}>
      <div className="property-inquiry__heading">
        <Icon name="message" />
        <div><h3>Ask about this property</h3><p>Send a secure inquiry directly to the listing agency.</p></div>
      </div>
      <div className="property-inquiry__grid">
        <label><span>Your name</span><input name="name" autoComplete="name" minLength={2} maxLength={160} required /></label>
        <label><span>Email</span><input name="email" type="email" autoComplete="email" required /></label>
      </div>
      <label><span>Phone <small>Optional</small></span><input name="phone" type="tel" autoComplete="tel" maxLength={40} /></label>
      <label><span>Message</span><textarea name="message" minLength={10} maxLength={5000} defaultValue="I’m interested in this property. Please share the next available viewing times." required /></label>
      <label className="property-inquiry__consent"><input name="consent" type="checkbox" required /><span>I agree that Casaura can share these details with {agencyName} for this inquiry.</span></label>
      <button className="button button--primary" type="submit" disabled={busy}>{busy ? "Sending inquiry…" : "Send inquiry"}</button>
      <p className={`property-inquiry__result${result?.kind === "error" ? " is-error" : ""}`} role={result?.kind === "error" ? "alert" : "status"} aria-live="polite">{result?.message ?? "One inquiry creates one trackable conversation; safe retries will not duplicate it."}</p>
    </form>
  );
}
