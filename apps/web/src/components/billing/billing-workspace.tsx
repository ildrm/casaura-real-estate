"use client";

import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { ListingProjection } from "@/lib/listing-types";
import { formatDate, formatMoney } from "@/lib/localization";
import type { BillingWorkspaceData, PromotionCampaign } from "@/lib/release-types";

type ListingPage = { data: ListingProjection[] };

export function BillingWorkspace() {
  const agencyIdRef = useRef<string | null>(null);
  const [billing, setBilling] = useState<BillingWorkspaceData | null>(null);
  const [campaigns, setCampaigns] = useState<PromotionCampaign[]>([]);
  const [listings, setListings] = useState<ListingProjection[]>([]);
  const [showCampaignForm, setShowCampaignForm] = useState(false);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const agencyId = activeAgencyId();
    agencyIdRef.current = agencyId;
    if (!agencyId) {
      setError({ code: "AGENCY_REQUIRED", message: "Select an agency or sign in again to manage billing." });
      setLoading(false);
      return;
    }
    setLoading(true); setError(null);
    try {
      const [billingResponse, campaignsResponse, listingResponse] = await Promise.all([
        apiQuery<{ data: BillingWorkspaceData }>("/api/v1/billing", agencyId),
        apiQuery<{ data: PromotionCampaign[] }>("/api/v1/billing/promotion-campaigns", agencyId),
        apiQuery<ListingPage>("/api/v1/listings?limit=50", agencyId),
      ]);
      setBilling(billingResponse.data);
      setCampaigns(campaignsResponse.data);
      setListings(listingResponse.data.filter((listing) => listing.status === "published"));
    } catch (caught) { setError(caught as ApiError); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const currentPlan = billing?.plans.find((plan) => plan.id === billing.subscription?.plan_id) ?? null;
  const planEntitlements = useMemo(() => new Set(billing?.subscription?.entitlements
    .filter((entitlement) => entitlement.value !== false && entitlement.value !== null)
    .map((entitlement) => entitlement.key) ?? []), [billing]);

  async function openCheckout(planId: string) {
    if (!agencyIdRef.current) return;
    setWorking("checkout"); setError(null); setNotice("Preparing a secure Stripe-hosted checkout…");
    try {
      const response = await apiMutation<{ data: { url: string } }>("/api/v1/billing/checkout-sessions", { plan_id: planId }, {
        agencyId: agencyIdRef.current, idempotencyKey: crypto.randomUUID(),
      });
      window.location.assign(response.data.url);
    } catch (caught) { setError(caught as ApiError); setNotice(null); setWorking(null); }
  }

  async function openPortal() {
    if (!agencyIdRef.current) return;
    setWorking("portal"); setError(null); setNotice("Opening your secure Stripe billing portal…");
    try {
      const response = await apiMutation<{ data: { url: string } }>("/api/v1/billing/portal-sessions", {}, { agencyId: agencyIdRef.current });
      window.location.assign(response.data.url);
    } catch (caught) { setError(caught as ApiError); setNotice(null); setWorking(null); }
  }

  async function createCampaign(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!agencyIdRef.current) return;
    const values = new FormData(event.currentTarget);
    setWorking("campaign"); setError(null); setNotice(null);
    try {
      const response = await apiMutation<{ data: PromotionCampaign }>("/api/v1/billing/promotion-campaigns", {
        listing_id: String(values.get("listing_id") ?? ""),
        policy_id: String(values.get("policy_id") ?? ""),
        starts_at: String(values.get("starts_at") ?? ""),
        ends_at: String(values.get("ends_at") ?? ""),
        impression_cap: Number(values.get("impression_cap") ?? 0),
      }, { agencyId: agencyIdRef.current });
      setCampaigns((current) => [response.data, ...current]);
      setShowCampaignForm(false);
      setNotice("Sponsored campaign created. Public placements will show the policy label and disclosure.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(null); }
  }

  async function changeCampaign(campaign: PromotionCampaign, status: "active" | "paused" | "ended") {
    if (!agencyIdRef.current) return;
    setWorking(campaign.id);
    try {
      const response = await apiMutation<{ data: PromotionCampaign }>(`/api/v1/billing/promotion-campaigns/${campaign.id}`, {
        status, version: campaign.version,
      }, { method: "PATCH", agencyId: agencyIdRef.current });
      setCampaigns((current) => current.map((item) => item.id === campaign.id ? response.data : item));
      setNotice(`Campaign ${status}.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(null); }
  }

  return <main className="workspace-canvas release-canvas billing-canvas">
    <header className="workspace-title release-title"><div><h1>Billing &amp; promotion</h1><p>Manage your plan, invoices, and clearly disclosed listing promotion.</p></div>{billing?.subscription ? <button className={`button ${billing.payments_enabled ? "button--primary" : "button--disabled"}`} type="button" disabled={!billing.payments_enabled || Boolean(working)} onClick={() => void openPortal()}>{working === "portal" ? "Opening…" : "Manage billing"}</button> : null}</header>
    {error ? <section className="release-inline-error" role="alert"><Icon name="shield" /><span><strong>{error.code === "FEATURE_DISABLED" ? "Billing action unavailable" : "Billing request failed"}</strong><small>{error.message}</small></span><button className="button button--outline" type="button" onClick={() => void load()}>Retry</button></section> : null}
    {notice ? <p className="async-notice release-notice" role="status" aria-live="polite">{notice}</p> : null}
    {loading && !billing ? <section className="operations-state release-state" role="status"><span className="inline-spinner" /><h2>Loading billing state</h2><p>Checking plans, invoices, entitlements, and promotion policies.</p></section> : null}
    {billing ? <div className="billing-layout">
      <div className="billing-main">
        <section className="release-panel plan-band" aria-labelledby="current-plan-title"><span className="plan-mark"><Icon name="home" /></span><div><h2 id="current-plan-title">{currentPlan ? `${currentPlan.name} · ${formatMoney(currentPlan.price.amount_minor, currentPlan.price.currency)}/${currentPlan.billing_interval}` : "No active paid plan"}</h2><p><em className={`release-status release-status--${billing.subscription?.billing_status === "paid" || billing.subscription?.billing_status === "not_required" ? "active" : "pending"}`}>{billing.subscription?.billing_status ?? "No subscription"}</em>{billing.subscription?.current_period_ends_at ? ` · Renews ${formatDate(billing.subscription.current_period_ends_at, { dateStyle: "medium" })}` : ""}</p><small>United States · USD · automatic tax is calculated on Stripe-hosted checkout.</small></div><div className="plan-actions">{billing.subscription ? <button className="button button--outline" type="button" disabled={!billing.payments_enabled || Boolean(working)} onClick={() => void openPortal()}>Open billing portal</button> : null}{billing.plans.filter((plan) => plan.id !== billing.subscription?.plan_id).map((plan) => <button className="button button--primary" type="button" disabled={!billing.payments_enabled || Boolean(working)} onClick={() => void openCheckout(plan.id)} key={plan.id}>{working === "checkout" ? "Preparing…" : `Choose ${plan.name}`}</button>)}</div></section>

        <section className="release-panel" aria-labelledby="invoice-title"><header className="release-panel__heading"><div><h2 id="invoice-title">Invoice history</h2><p>Processor-hosted receipts only</p></div><span>{billing.invoices.length} invoices</span></header><div className="release-table-scroll" tabIndex={0} aria-label="Invoice history; horizontally scrollable"><div className="release-table release-table--invoices" role="table"><div className="release-table__head" role="row"><span>Invoice</span><span>Period</span><span>Subtotal</span><span>Tax</span><span>Total</span><span>Status</span><span>Receipt</span></div>{billing.invoices.length ? billing.invoices.map((invoice) => <div className="release-table__row" role="row" key={invoice.id}><span>{invoice.number ?? invoice.id.slice(0, 8)}</span><span>{invoice.period_ends_at ? formatDate(invoice.period_ends_at, { dateStyle: "medium" }) : "—"}</span><span>{formatMoney(invoice.subtotal_minor, invoice.currency)}</span><span>{formatMoney(invoice.tax_minor, invoice.currency)}</span><strong>{formatMoney(invoice.total_minor, invoice.currency)}</strong><span><em className={`release-status release-status--${invoice.status === "paid" ? "active" : "pending"}`}>{invoice.status}</em></span><span>{invoice.hosted_invoice_url ? <a href={invoice.hosted_invoice_url} target="_blank" rel="noreferrer">View receipt</a> : "Unavailable"}</span></div>) : <p className="release-table__empty">No invoice has been projected yet.</p>}</div></div></section>

        <section className="release-panel campaigns-panel" aria-labelledby="campaigns-title"><header className="release-panel__heading"><div><h2 id="campaigns-title">Sponsored listing campaigns</h2><p>Paid placement never changes organic ranking.</p></div><button className={`button ${billing.promotion_policies.length && listings.length ? "button--primary" : "button--disabled"}`} type="button" disabled={!billing.promotion_policies.length || !listings.length} onClick={() => setShowCampaignForm((value) => !value)}><Icon name="plus" /> Create campaign</button></header>
          {showCampaignForm ? <form className="release-form campaign-form" onSubmit={(event) => void createCampaign(event)}><label>Published listing<select name="listing_id" required defaultValue=""><option value="" disabled>Select a listing</option>{listings.map((listing) => <option value={listing.id} key={listing.id}>{listing.reference} · {listing.title ?? "Untitled property"}</option>)}</select></label><label>Active policy<select name="policy_id" required defaultValue=""><option value="" disabled>Select a policy</option>{billing.promotion_policies.map((policy) => <option value={policy.id} key={policy.id}>{policy.name} · {policy.placement}</option>)}</select></label><label>Starts at<input name="starts_at" type="datetime-local" required /></label><label>Ends at<input name="ends_at" type="datetime-local" required /></label><label>Impression cap<input name="impression_cap" type="number" min="1" max={Math.max(...billing.promotion_policies.map((policy) => policy.max_impressions))} required /></label><footer><button type="button" className="button button--outline" onClick={() => setShowCampaignForm(false)}>Cancel</button><button className="button button--primary" disabled={working === "campaign"} type="submit">{working === "campaign" ? "Creating…" : "Create disclosed campaign"}</button></footer></form> : null}
          <div className="release-table-scroll" tabIndex={0} aria-label="Sponsored campaigns; horizontally scrollable"><div className="release-table release-table--campaigns" role="table"><div className="release-table__head" role="row"><span>Listing</span><span>Schedule</span><span>Disclosure</span><span>Impressions</span><span>Status</span><span>Actions</span></div>{campaigns.length ? campaigns.map((campaign) => { const listing = listings.find((item) => item.id === campaign.listing_id); const policy = billing.promotion_policies.find((item) => item.id === campaign.promotion_policy_id); return <div className="release-table__row" role="row" key={campaign.id}><span><strong>{listing?.title ?? listing?.reference ?? campaign.listing_id.slice(0, 8)}</strong></span><span>{formatDate(campaign.starts_at, { month: "short", day: "numeric" })}–{formatDate(campaign.ends_at, { month: "short", day: "numeric" })}</span><span><em className="sponsored-label">{policy?.label ?? "Sponsored"}</em><small>{policy?.disclosure ?? "Paid placement"}</small></span><span>{campaign.impression_count.toLocaleString()} / {campaign.impression_cap.toLocaleString()}</span><span><em className={`release-status release-status--${campaign.status === "active" ? "active" : campaign.status === "paused" ? "pending" : "ended"}`}>{campaign.status}</em></span><span className="table-actions">{campaign.status === "active" ? <button type="button" disabled={working === campaign.id} onClick={() => void changeCampaign(campaign, "paused")}>Pause</button> : campaign.status === "paused" ? <button type="button" disabled={working === campaign.id} onClick={() => void changeCampaign(campaign, "active")}>Resume</button> : null}{campaign.status !== "ended" ? <button type="button" disabled={working === campaign.id} onClick={() => void changeCampaign(campaign, "ended")}>End</button> : null}</span></div>; }) : <p className="release-table__empty">No sponsored campaign exists. Organic results remain unchanged.</p>}</div></div>
        </section>
      </div>

      <aside className="billing-rail">
        <section className="release-panel entitlement-panel"><h2>Your entitlements</h2><ul>{[["mls", "RESO integrations"], ["ai_search", "Grounded AI assistant"], ["collaborative_collections", "Private collections"], ["sponsored_listings", "Sponsored listings"]].map(([key, label]) => <li key={key}><Icon name={planEntitlements.has(key) ? "check" : "shield"} /><span>{label}</span><strong>{planEntitlements.has(key) ? "Included" : "Not included"}</strong></li>)}</ul></section>
        <section className="release-panel policy-panel"><h2>Promotion policy</h2>{billing.promotion_policies[0] ? <><h3>{billing.promotion_policies[0].name}</h3><dl><div><dt>Active version</dt><dd>v{billing.promotion_policies[0].version}</dd></div><div><dt>Placement</dt><dd>{billing.promotion_policies[0].placement}</dd></div><div><dt>Impression cap</dt><dd>{billing.promotion_policies[0].max_impressions.toLocaleString()}</dd></div><div><dt>Disclosure</dt><dd>{billing.promotion_policies[0].disclosure}</dd></div><div><dt>Effective until</dt><dd>{formatDate(billing.promotion_policies[0].ends_at, { dateStyle: "medium" })}</dd></div></dl>{billing.promotion_policies.length > 1 ? <p>{billing.promotion_policies.length - 1} more active {billing.promotion_policies.length === 2 ? "policy is" : "policies are"} available in the campaign form.</p> : null}</> : <p>No active policy is eligible for this plan. New campaigns remain disabled.</p>}</section>
        {!billing.payments_enabled ? <section className="release-inline-warning payments-disabled"><Icon name="shield" /><span><strong>Payments feature unavailable</strong><small>Plan changes and hosted billing sessions are disabled. Invoice history remains read-only.</small></span></section> : null}
      </aside>
    </div> : null}
  </main>;
}
