"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, type ApiError } from "@/lib/api-client";

function reportKey(): string { return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `report-${Date.now()}-${Math.random().toString(36).slice(2)}`; }

export function PropertyReportForm({ listingId, listingUrl }: { listingId: string; listingUrl: string }) {
  const router = useRouter();
  const [key, setKey] = useState(reportKey);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ kind: "success" | "error"; message: string } | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); setBusy(true); setNotice(null);
    try {
      await apiMutation(`/api/v1/public/listings/${listingId}/reports`, { category: String(values.get("category") ?? "other"), details: String(values.get("details") ?? "") || null }, { idempotencyKey: key });
      form.reset(); setKey(reportKey()); setNotice({ kind: "success", message: "Report received. Platform moderation can now review this listing." });
    } catch (caught) {
      const error = caught as ApiError;
      if (error.code === "UNAUTHENTICATED") { router.push(`/sign-in?next=${encodeURIComponent(listingUrl)}`); return; }
      setNotice({ kind: "error", message: error.message });
    } finally { setBusy(false); }
  }

  return <details className="property-report"><summary><Icon name="shield" /> Report this listing</summary><form onSubmit={(event) => void submit(event)}><p>Reports require a signed-in account and create a redacted moderation case.</p><label>Reason<select name="category" defaultValue="misleading"><option value="misleading">Misleading information</option><option value="fraud">Suspected fraud</option><option value="duplicate">Duplicate listing</option><option value="unavailable">No longer available</option><option value="inappropriate">Inappropriate content</option><option value="other">Other</option></select></label><label>Details <small>Optional</small><textarea name="details" maxLength={5000} /></label><button className="button button--outline" type="submit" disabled={busy}>{busy ? "Submitting…" : "Submit report"}</button><p className={notice?.kind === "error" ? "is-error" : ""} role={notice?.kind === "error" ? "alert" : "status"} aria-live="polite">{notice?.message}</p></form></details>;
}
