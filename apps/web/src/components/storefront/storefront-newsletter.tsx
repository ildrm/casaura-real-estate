"use client";

import { useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, type ApiError } from "@/lib/api-client";

function subscriptionKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `subscription-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function StorefrontNewsletter({ agencyId, agencyName }: { agencyId: string; agencyName: string }) {
  const [key, setKey] = useState(subscriptionKey);
  const [busy, setBusy] = useState(false);
  const [disabled, setDisabled] = useState(false);
  const [notice, setNotice] = useState<{ kind: "success" | "error"; message: string } | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); setBusy(true); setNotice(null);
    try {
      await apiMutation(`/api/v1/public/agencies/${agencyId}/newsletter/subscriptions`, { email: String(values.get("email") ?? ""), consent: values.get("consent") === "on", consent_source: "public_storefront" }, { idempotencyKey: key });
      setNotice({ kind: "success", message: `You’re subscribed to updates from ${agencyName}.` }); form.reset(); setKey(subscriptionKey());
    } catch (caught) {
      const error = caught as ApiError;
      if (error.code === "FEATURE_DISABLED") setDisabled(true);
      setNotice({ kind: "error", message: error.message });
    } finally { setBusy(false); }
  }

  if (disabled) return <section className="storefront-newsletter feature-disabled" aria-labelledby="newsletter-title"><Icon name="mail" /><h2 id="newsletter-title">Agency updates are unavailable</h2><p>This agency has not enabled newsletter subscriptions.</p></section>;

  return <section className="storefront-newsletter" aria-labelledby="newsletter-title"><div><p>Local notes, thoughtfully sent</p><h2 id="newsletter-title">Follow {agencyName}</h2><span>Occasional new-listing and neighborhood updates directly from the agency.</span></div><form onSubmit={(event) => void submit(event)}><label htmlFor="storefront-email">Email address</label><div><input id="storefront-email" name="email" type="email" autoComplete="email" required placeholder="you@example.com" /><button className="button button--terracotta" type="submit" disabled={busy}>{busy ? "Joining…" : "Join updates"}</button></div><label className="property-inquiry__consent"><input name="consent" type="checkbox" required /><span>I consent to receive this agency’s newsletter. I can unsubscribe at any time.</span></label><p className={notice?.kind === "error" ? "is-error" : ""} role={notice?.kind === "error" ? "alert" : "status"} aria-live="polite">{notice?.message}</p></form></section>;
}
