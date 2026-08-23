"use client";

import Link from "next/link";
import { type FormEvent, useCallback, useEffect, useState } from "react";
import { BrandMark } from "@/components/brand/logo";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import { formatDate, formatMoney } from "@/lib/localization";
import type { PromotionPolicy } from "@/lib/release-types";

type PlanOption = { id: string; name: string; slug: string; price_amount_minor: number; price_currency: string; billing_interval: string };
type SafetyEvent = { id: string; category: string; action: string; rule_version: string; created_at: string };

export function AdminReleaseControls() {
  const [policies, setPolicies] = useState<PromotionPolicy[]>([]);
  const [plans, setPlans] = useState<PlanOption[]>([]);
  const [events, setEvents] = useState<SafetyEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [policyResponse, eventResponse] = await Promise.all([
        apiQuery<{ data: PromotionPolicy[]; meta: { plans: PlanOption[] } }>("/api/v1/admin/promotion-policies"),
        apiQuery<{ data: SafetyEvent[] }>("/api/v1/admin/ai-safety-events"),
      ]);
      setPolicies(policyResponse.data); setPlans(policyResponse.meta.plans); setEvents(eventResponse.data);
    } catch (caught) { setError(caught as ApiError); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget; const values = new FormData(form);
    setWorking(true); setError(null);
    try {
      const response = await apiMutation<{ data: PromotionPolicy }>("/api/v1/admin/promotion-policies", {
        name: String(values.get("name") ?? ""), placement: String(values.get("placement") ?? "search"),
        label: String(values.get("label") ?? "Sponsored"), disclosure: String(values.get("disclosure") ?? ""),
        eligible_plan_ids: values.getAll("eligible_plan_ids").map(String),
        starts_at: new Date(String(values.get("starts_at") ?? "")).toISOString(),
        ends_at: new Date(String(values.get("ends_at") ?? "")).toISOString(),
        max_impressions: Number(values.get("max_impressions") ?? 0),
      });
      setPolicies((current) => [response.data, ...current]); form.reset();
      setNotice("Immutable promotion policy v1 created and audited.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function change(policy: PromotionPolicy, status: "active" | "paused" | "ended") {
    setWorking(true);
    try {
      const response = await apiMutation<{ data: PromotionPolicy }>(`/api/v1/admin/promotion-policies/${policy.id}`, { version: policy.version, status }, { method: "PATCH" });
      setPolicies((current) => [response.data, ...current.map((item) => item.id === policy.id ? { ...item, status: "ended" as const } : item)]);
      setNotice(`Policy replacement v${response.data.version} created with status ${status}.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  return <div className="admin-shell"><header className="admin-topbar"><BrandMark /><span>Release controls</span><Link href="/admin">Control room</Link></header><main className="admin-canvas release-admin"><header className="admin-heading"><div><p>Immutable policy · redacted safety evidence</p><h1>AI safety &amp; promotion controls</h1></div><button className="button button--outline" type="button" onClick={() => void load()}><Icon name="sparkle" /> Refresh projections</button></header>
    {error ? <section className="admin-state" role="alert"><Icon name="shield" /><h1>Release controls unavailable</h1><span>{error.message}</span><button className="button button--outline" type="button" onClick={() => void load()}>Try again</button></section> : null}
    {loading && !error ? <section className="admin-state" role="status"><span className="inline-spinner" /><h1>Checking platform permissions</h1></section> : null}
    {!loading && !error ? <div className="release-admin-grid"><section className="admin-panel"><header><div><p>Clearly disclosed inventory</p><h2>Promotion policies</h2></div><span>{policies.length} immutable versions</span></header><form className="policy-form" onSubmit={(event) => void create(event)}><h3>Create a policy family</h3><label>Name<input name="name" minLength={2} maxLength={160} required /></label><label>Placement<select name="placement" defaultValue="search"><option value="search">Search</option><option value="detail">Listing detail</option><option value="storefront">Storefront</option></select></label><label>Public label<input name="label" defaultValue="Sponsored" maxLength={80} required /></label><label>Public disclosure<input name="disclosure" defaultValue="Paid placement by the publishing agency" maxLength={255} required /></label><fieldset><legend>Eligible plans</legend>{plans.map((plan) => <label key={plan.id}><input type="checkbox" name="eligible_plan_ids" value={plan.id} /> {plan.name} · {formatMoney(plan.price_amount_minor, plan.price_currency)}/{plan.billing_interval}</label>)}</fieldset><label>Starts<input name="starts_at" type="datetime-local" required /></label><label>Ends<input name="ends_at" type="datetime-local" required /></label><label>Maximum impressions<input name="max_impressions" type="number" min="1" max="100000000" required /></label><button className="button button--primary" type="submit" disabled={working || !plans.length}>Create immutable policy</button></form><div className="policy-list">{policies.map((policy) => <article key={policy.id}><span><strong>{policy.name} · v{policy.version}</strong><small>{policy.placement} · {policy.label} · {policy.disclosure}</small><small>{formatDate(policy.starts_at, { dateStyle: "medium" })}–{formatDate(policy.ends_at, { dateStyle: "medium" })} · cap {policy.max_impressions.toLocaleString()}</small></span><em className={`release-status release-status--${policy.status === "active" ? "active" : policy.status === "paused" ? "pending" : "ended"}`}>{policy.status}</em>{policy.status !== "ended" ? <div><button type="button" disabled={working} onClick={() => void change(policy, policy.status === "active" ? "paused" : "active")}>{policy.status === "active" ? "Pause" : "Activate"} as new version</button><button type="button" disabled={working} onClick={() => void change(policy, "ended")}>End as new version</button></div> : null}</article>)}</div></section>
      <section className="admin-panel ai-safety-panel"><header><div><p>Prompt content excluded</p><h2>AI safety events</h2></div><span>{events.length} redacted events</span></header>{events.length ? <ol>{events.map((event) => <li key={event.id}><i className="signal-dot signal-dot--warning" /><span><strong>{event.category.replaceAll("_", " ")}</strong><small>{event.action} · rules v{event.rule_version}</small></span><time>{formatDate(event.created_at, { dateStyle: "medium", timeStyle: "short" })}</time></li>)}</ol> : <p className="admin-empty">No safety events are present in the retained evidence window.</p>}<div className="release-inline-warning"><Icon name="shield" /><span><strong>Redacted by design</strong><small>This view contains category, action, rule version, and time only—never prompt text, credentials, or personal identifiers.</small></span></div></section></div> : null}
    <p className="async-notice" role="status" aria-live="polite">{notice}</p>
  </main></div>;
}
