"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { Agency, AgencyAnalytics, Campaign, Closure, FeatureResolution, OpeningHour, OpeningHours, TeamMember } from "@/lib/operations-types";

type GrowthData = {
  agency: Agency;
  hours: OpeningHours;
  team: TeamMember[];
  quota: number;
  analytics: AgencyAnalytics;
  features: Record<string, FeatureResolution>;
  campaigns: Campaign[];
  newsletterError: ApiError | null;
};

const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
const roles = [
  ["agency_manager", "Manager"], ["agent", "Agent"], ["content_manager", "Content manager"], ["agency_analyst", "Agency analyst"],
] as const;

function todayInput(offsetDays = 0): string {
  const date = new Date(); date.setDate(date.getDate() + offsetDays); return date.toISOString().slice(0, 10);
}

export function GrowthWorkspace() {
  const [data, setData] = useState<GrowthData | null>(null);
  const [hoursDraft, setHoursDraft] = useState<OpeningHour[]>([]);
  const [closuresDraft, setClosuresDraft] = useState<Closure[]>([]);
  const [closureDate, setClosureDate] = useState("");
  const [closureClosed, setClosureClosed] = useState(true);
  const [closureOpens, setClosureOpens] = useState("09:00");
  const [closureCloses, setClosureCloses] = useState("13:00");
  const [closureReason, setClosureReason] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [from, setFrom] = useState(() => todayInput(-29));
  const [to, setTo] = useState(() => todayInput());
  const agencyIdRef = useRef<string | null>(null);

  const load = useCallback(async (rangeFrom: string, rangeTo: string) => {
    const agencyId = activeAgencyId(); agencyIdRef.current = agencyId;
    if (!agencyId) { setError({ code: "AGENCY_CONTEXT_REQUIRED", message: "Choose an agency workspace before opening growth." }); return; }
    try {
      const [agency, hours, team, analytics, features, campaigns] = await Promise.all([
        apiQuery<{ data: Agency }>("/api/v1/agency", agencyId),
        apiQuery<{ data: OpeningHours }>("/api/v1/agency/opening-hours", agencyId),
        apiQuery<{ data: TeamMember[]; meta: { quota: number } }>("/api/v1/agency/team", agencyId),
        apiQuery<{ data: AgencyAnalytics }>(`/api/v1/agency/analytics?from=${encodeURIComponent(rangeFrom)}&to=${encodeURIComponent(rangeTo)}`, agencyId),
        apiQuery<{ data: Record<string, FeatureResolution> }>("/api/v1/agency/feature-flags", agencyId),
        apiQuery<{ data: Campaign[] }>("/api/v1/agency/newsletter/campaigns", agencyId).then((result) => ({ result, error: null as ApiError | null })).catch((caught: ApiError) => ({ result: { data: [] as Campaign[] }, error: caught })),
      ]);
      const nextHours = normalizedHours(hours.data.hours);
      setHoursDraft(nextHours);
      setClosuresDraft(hours.data.closures);
      setData({ agency: agency.data, hours: { ...hours.data, hours: nextHours }, team: team.data, quota: team.meta.quota, analytics: analytics.data, features: features.data, campaigns: campaigns.result.data, newsletterError: campaigns.error });
      setError(null);
    } catch (caught) { setError(caught as ApiError); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => void load(todayInput(-29), todayInput()), 0); return () => window.clearTimeout(timer); }, [load]);

  const readiness = useMemo(() => data ? [
    { label: "Agency story", complete: Boolean(data.agency.short_description && data.agency.description) },
    { label: "Contact details", complete: Boolean(data.agency.phone || data.agency.website) },
    { label: "Weekly hours", complete: data.hours.hours.some((hour) => !hour.closed) },
    { label: "Active team", complete: data.team.some((member) => member.status === "active") },
  ] : [], [data]);
  const completion = readiness.length ? Math.round(readiness.filter((item) => item.complete).length / readiness.length * 100) : 0;
  const newslettersEnabled = data?.features.newsletters?.enabled ?? data?.newsletterError?.code !== "FEATURE_DISABLED";

  async function saveHours(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!agencyIdRef.current || !data) return;
    setNotice(null);
    try {
      const response = await apiMutation<{ data: OpeningHours }>("/api/v1/agency/opening-hours", { hours: hoursDraft, closures: closuresDraft.map((closure) => ({ date: closure.date, opens_at: closure.opens_at, closes_at: closure.closes_at, closed: closure.closed, reason: closure.reason })) }, { method: "PUT", agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, hours: response.data } : current);
      setHoursDraft(normalizedHours(response.data.hours)); setClosuresDraft(response.data.closures); setNotice("Opening hours and exceptional closures saved.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  function addClosure() {
    if (!closureDate) { setNotice("Choose a date for the exceptional schedule."); return; }
    if (!closureClosed && (!closureOpens || !closureCloses || closureCloses <= closureOpens)) { setNotice("Reduced hours require a closing time after the opening time."); return; }
    const closure: Closure = { date: closureDate, closed: closureClosed, opens_at: closureClosed ? null : closureOpens, closes_at: closureClosed ? null : closureCloses, reason: closureReason || null };
    setClosuresDraft((current) => [...current.filter((item) => item.date !== closureDate), closure].sort((a, b) => a.date.localeCompare(b.date)));
    setClosureDate(""); setClosureReason(""); setNotice("Exceptional date staged. Save hours to publish it.");
  }

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!agencyIdRef.current) return;
    const form = event.currentTarget; const values = new FormData(form); setNotice(null);
    try {
      const response = await apiMutation<{ data: TeamMember }>("/api/v1/agency/team", {
        name: String(values.get("name") ?? ""), email: String(values.get("email") ?? ""), job_title: String(values.get("job_title") ?? "") || null, role_slug: String(values.get("role_slug") ?? "agent"),
      }, { agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, team: [...current.team, response.data] } : current); form.reset(); setNotice("Team invitation created.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function updateMember(member: TeamMember, status: TeamMember["status"]) {
    if (!agencyIdRef.current) return;
    try {
      const response = await apiMutation<{ data: TeamMember }>(`/api/v1/agency/team/${member.id}`, { status }, { method: "PATCH", agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, team: current.team.map((item) => item.id === response.data.id ? response.data : item) } : current);
      setNotice(`Team member marked ${status}.`);
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function createCampaign(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!agencyIdRef.current) return;
    const form = event.currentTarget; const values = new FormData(form);
    try {
      const response = await apiMutation<{ data: Campaign }>("/api/v1/agency/newsletter/campaigns", { subject: String(values.get("subject") ?? ""), body: String(values.get("body") ?? "") }, { agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, campaigns: [response.data, ...current.campaigns] } : current); form.reset(); setNotice("Campaign saved as a draft.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function sendCampaign(campaign: Campaign) {
    if (!agencyIdRef.current) return;
    try {
      const response = await apiMutation<{ data: Campaign }>(`/api/v1/agency/newsletter/campaigns/${campaign.id}/send`, {}, { agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, campaigns: current.campaigns.map((item) => item.id === response.data.id ? response.data : item) } : current); setNotice("Campaign send completed through the configured delivery adapter.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function updateCampaign(campaign: Campaign, subject: string, body: string) {
    if (!agencyIdRef.current) return;
    try {
      const response = await apiMutation<{ data: Campaign }>(`/api/v1/agency/newsletter/campaigns/${campaign.id}`, { subject, body }, { method: "PATCH", agencyId: agencyIdRef.current });
      setData((current) => current ? { ...current, campaigns: current.campaigns.map((item) => item.id === response.data.id ? response.data : item) } : current); setNotice("Campaign draft updated.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  return <main className="workspace-canvas growth-canvas">
    <header className="workspace-title growth-title"><div><p>Storefront → team → campaign → performance</p><h1>Agency growth</h1><p>Keep the public experience and operating rhythm in one place.</p></div>{data ? <div className="workspace-title__actions"><Link className="button button--outline" href={`/professionals/${data.agency.slug}`} prefetch={false}><Icon name="building" /> View storefront</Link><Link className="button button--primary" href="/agency/profile">Edit profile</Link></div> : null}</header>
    {error ? <section className="operations-state" role="alert"><Icon name="shield" /><h2>Growth workspace is unavailable</h2><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => void load(from, to)}>Try again</button></section> : null}
    {!error && !data ? <section className="operations-state" role="status"><span className="inline-spinner" /><h2>Assembling your growth desk</h2><p>Loading storefront, team, campaign, and analytics state.</p></section> : null}
    {data ? <>
      <section className="growth-summary">
        <div className="storefront-readiness"><div><span className="readiness-score">{completion}%</span><div><h2>Storefront readiness</h2><p>{data.agency.name} · {data.agency.verification_status}</p></div></div><progress value={completion} max="100">{completion}%</progress><ul>{readiness.map((item) => <li key={item.label} className={item.complete ? "is-complete" : undefined}><Icon name={item.complete ? "check" : "plus"} /> {item.label}</li>)}</ul></div>
        <form className="range-selector" aria-label="Analytics date range" onSubmit={(event) => { event.preventDefault(); void load(from, to); }}><h2>Performance window</h2><label>From<input type="date" value={from} onChange={(event) => setFrom(event.target.value)} required /></label><label>To<input type="date" value={to} onChange={(event) => setTo(event.target.value)} required /></label><button className="button button--outline" type="submit">Apply range</button></form>
      </section>
      <section className="metric-strip growth-metrics" id="analytics" aria-label="Agency performance"><GrowthMetric value={data.analytics.storefront_views} label="Storefront views" /><GrowthMetric value={data.analytics.listing_views} label="Listing views" /><GrowthMetric value={data.analytics.favorites} label="Favorites" /><GrowthMetric value={data.analytics.leads} label="Leads" /><GrowthMetric value={data.analytics.viewings} label="Viewings" /><GrowthMetric value={data.analytics.newsletter_deliveries} label="Deliveries" /></section>
      <p className="async-notice" role="status" aria-live="polite">{notice}</p>
      <div className="growth-layout">
        <section className="dashboard-panel hours-panel" aria-labelledby="hours-title"><header className="panel-heading"><div><h2 id="hours-title">Weekly opening hours</h2><span>{data.hours.timezone}</span></div><span>Public storefront</span></header><form onSubmit={(event) => void saveHours(event)}><div className="hours-list">{hoursDraft.map((hour, index) => <div className="hours-row" key={hour.weekday}><strong>{weekdays[hour.weekday] ?? `Day ${hour.weekday}`}</strong><label className="hours-closed"><input type="checkbox" checked={hour.closed} onChange={(event) => setHoursDraft((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, closed: event.target.checked, opens_at: event.target.checked ? null : item.opens_at ?? "09:00", closes_at: event.target.checked ? null : item.closes_at ?? "17:00" } : item))} /> Closed</label><label><span>Opens</span><input type="time" value={hour.opens_at ?? ""} disabled={hour.closed} onChange={(event) => setHoursDraft((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, opens_at: event.target.value } : item))} required={!hour.closed} /></label><label><span>Closes</span><input type="time" value={hour.closes_at ?? ""} disabled={hour.closed} onChange={(event) => setHoursDraft((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, closes_at: event.target.value } : item))} required={!hour.closed} /></label></div>)}</div><div className="exceptional-hours"><h3>Exceptional dates</h3><div className="closure-editor"><label>Date<input type="date" value={closureDate} onChange={(event) => setClosureDate(event.target.value)} /></label><label className="hours-closed"><input type="checkbox" checked={closureClosed} onChange={(event) => setClosureClosed(event.target.checked)} /> Closed all day</label><label>Opens<input type="time" value={closureOpens} disabled={closureClosed} onChange={(event) => setClosureOpens(event.target.value)} /></label><label>Closes<input type="time" value={closureCloses} disabled={closureClosed} onChange={(event) => setClosureCloses(event.target.value)} /></label><label className="closure-reason">Reason<input value={closureReason} maxLength={200} onChange={(event) => setClosureReason(event.target.value)} placeholder="Holiday or team event" /></label><button className="button button--outline" type="button" onClick={addClosure}>Stage date</button></div>{closuresDraft.length ? <ul>{closuresDraft.map((closure) => <li key={closure.date}><span><strong>{closure.date}</strong><small>{closure.closed ? "Closed" : `${closure.opens_at}–${closure.closes_at}`}{closure.reason ? ` · ${closure.reason}` : ""}</small></span><button type="button" onClick={() => setClosuresDraft((current) => current.filter((item) => item.date !== closure.date))}>Remove</button></li>)}</ul> : <p>No exceptional dates staged.</p>}</div><button className="button button--primary" type="submit">Save hours & closures</button></form></section>

        <section className="dashboard-panel team-panel" id="team" aria-labelledby="team-title"><header className="panel-heading"><div><h2 id="team-title">Team</h2><span>{data.team.length} of {data.quota} seats used</span></div><span>{Math.max(0, data.quota - data.team.length)} available</span></header>{data.team.length ? <ul>{data.team.map((member) => <li key={member.id}><span className="team-monogram">{initials(member.user.name)}</span><span><strong>{member.user.name}</strong><small>{member.job_title ?? member.roles[0]?.name ?? "Team member"} · {member.user.email}</small></span><em className={`status-chip status-chip--${member.status}`}>{member.status}</em>{member.status === "invited" ? <button type="button" onClick={() => void updateMember(member, "active")}>Activate</button> : member.status === "active" ? <button type="button" onClick={() => void updateMember(member, "inactive")}>Deactivate</button> : <button type="button" onClick={() => void updateMember(member, "active")}>Reactivate</button>}</li>)}</ul> : <p className="panel-empty">No team members are visible yet.</p>}<form className="team-invite" onSubmit={(event) => void invite(event)}><h3>Invite a team member</h3><label>Name<input name="name" minLength={2} maxLength={160} required /></label><label>Email<input name="email" type="email" required /></label><label>Job title<input name="job_title" maxLength={120} /></label><label>Role<select name="role_slug" defaultValue="agent">{roles.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><button className="button button--outline" type="submit" disabled={data.team.length >= data.quota}>{data.team.length >= data.quota ? "Team quota reached" : "Create invitation"}</button></form></section>

        <section className="dashboard-panel campaign-panel" aria-labelledby="campaign-title"><header className="panel-heading"><div><h2 id="campaign-title">Newsletter campaigns</h2><span>Draft and send from one verified record</span></div><span>{newslettersEnabled ? "Enabled" : "Unavailable"}</span></header>{!newslettersEnabled ? <div className="feature-disabled"><Icon name="mail" /><h3>Newsletters are not enabled</h3><p>{data.newsletterError?.message ?? "This agency’s current feature configuration does not include newsletter campaigns."}</p><small>No draft or send action is simulated while the feature is disabled.</small></div> : <><form className="campaign-composer" onSubmit={(event) => void createCampaign(event)}><label>Subject<input name="subject" minLength={2} maxLength={200} required /></label><label>Message<textarea name="body" minLength={2} maxLength={50000} required /></label><button className="button button--primary" type="submit">Save draft</button></form>{data.campaigns.length ? <ol className="campaign-history">{data.campaigns.map((campaign) => <CampaignRow campaign={campaign} key={campaign.id} onSave={updateCampaign} onSend={sendCampaign} />)}</ol> : <p className="panel-empty">No campaigns yet. Save the first draft above.</p>}</>}</section>
      </div>
    </> : null}
  </main>;
}

function normalizedHours(hours: OpeningHour[]): OpeningHour[] {
  const byDay = new Map(hours.map((hour) => [hour.weekday, hour]));
  return Array.from({ length: 7 }, (_, weekday) => byDay.get(weekday) ?? { weekday, opens_at: null, closes_at: null, closed: true });
}
function CampaignRow({ campaign, onSave, onSend }: { campaign: Campaign; onSave: (campaign: Campaign, subject: string, body: string) => Promise<void>; onSend: (campaign: Campaign) => Promise<void> }) {
  const [editing, setEditing] = useState(false);
  const [subject, setSubject] = useState(campaign.subject);
  const [body, setBody] = useState(campaign.body);
  if (editing && campaign.status === "draft") return <li className="campaign-edit"><form onSubmit={(event) => { event.preventDefault(); void onSave(campaign, subject, body).then(() => setEditing(false)); }}><label>Subject<input value={subject} minLength={2} maxLength={200} onChange={(event) => setSubject(event.target.value)} required /></label><label>Message<textarea value={body} minLength={2} maxLength={50000} onChange={(event) => setBody(event.target.value)} required /></label><div><button type="button" onClick={() => setEditing(false)}>Cancel</button><button type="submit">Save changes</button></div></form></li>;
  return <li><i className={`signal-dot${campaign.status === "sent" ? " is-muted" : ""}`} /><span><strong>{campaign.subject}</strong><small>{campaign.status === "sent" ? `${campaign.delivery_count} delivery events · ${campaign.sent_at ? formatDate(campaign.sent_at) : "sent"}` : "Draft"}</small></span>{campaign.status === "draft" ? <div><button type="button" onClick={() => setEditing(true)}>Edit</button><button type="button" onClick={() => void onSend(campaign)}>Send</button></div> : <em>Sent</em>}</li>;
}
function GrowthMetric({ value, label }: { value: number; label: string }) { return <div><i className="signal-dot" /><strong>{value.toLocaleString()}</strong><small>{label}</small></div>; }
function initials(name: string): string { return name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase(); }
function formatDate(value: string): string { return new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)); }
