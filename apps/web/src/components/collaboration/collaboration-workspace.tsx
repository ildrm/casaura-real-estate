"use client";

import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, apiTextQuery, type ApiError } from "@/lib/api-client";
import { formatDate as localizedDate } from "@/lib/localization";
import type { CollaborationAnalytics, Lead, LeadStatus, Message, Reminder, UserNotification, Viewing } from "@/lib/operations-types";

type WorkspaceData = {
  leads: Lead[];
  viewings: Viewing[];
  reminders: Reminder[];
  notifications: UserNotification[];
  analytics: CollaborationAnalytics;
};

const leadStatuses: LeadStatus[] = ["new", "contacted", "qualified", "viewing", "won", "lost"];

export function CollaborationWorkspace() {
  const [data, setData] = useState<WorkspaceData | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [filter, setFilter] = useState<LeadStatus | "all">("all");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [agencyId, setAgencyId] = useState<string | null>(null);

  async function load() {
    const agencyId = activeAgencyId();
    setAgencyId(agencyId);
    if (!agencyId) {
      setError({ code: "AGENCY_CONTEXT_REQUIRED", message: "Choose an agency workspace before opening collaboration." });
      return;
    }
    try {
      const [leads, viewings, reminders, notifications, analytics] = await Promise.all([
        apiQuery<{ data: Lead[] }>("/api/v1/leads?limit=50", agencyId),
        apiQuery<{ data: Viewing[] }>("/api/v1/viewings", agencyId),
        apiQuery<{ data: Reminder[] }>("/api/v1/reminders", agencyId),
        apiQuery<{ data: UserNotification[] }>("/api/v1/notifications"),
        apiQuery<{ data: CollaborationAnalytics }>("/api/v1/agency/analytics/collaboration", agencyId),
      ]);
      const next = { leads: leads.data, viewings: viewings.data, reminders: reminders.data, notifications: notifications.data, analytics: analytics.data };
      setData(next);
      setSelectedId((current) => current && next.leads.some((lead) => lead.id === current) ? current : next.leads[0]?.id ?? null);
      setError(null);
    } catch (caught) { setError(caught as ApiError); }
  }

  async function refreshNotifications() {
    try {
      const response = await apiQuery<{ data: UserNotification[] }>("/api/v1/notifications");
      setData((current) => current ? { ...current, notifications: response.data } : current);
    } catch { /* Keep the last honest projection; the full refresh remains available. */ }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    const notifications = window.setInterval(() => void refreshNotifications(), 15_000);
    return () => { window.clearTimeout(timer); window.clearInterval(notifications); };
  }, []);

  const selected = data?.leads.find((lead) => lead.id === selectedId) ?? null;
  const filtered = useMemo(() => data?.leads.filter((lead) => filter === "all" || lead.status === filter) ?? [], [data, filter]);
  const unread = data?.notifications.filter((item) => !item.read).length ?? 0;

  async function updateLead(changes: Partial<Pick<Lead, "status" | "priority">>) {
    if (!selected || !agencyId) return;
    setNotice(null);
    try {
      const response = await apiMutation<{ data: Lead }>(`/api/v1/leads/${selected.id}`, { version: selected.version, ...changes }, { method: "PATCH", agencyId });
      setData((current) => current ? { ...current, leads: current.leads.map((lead) => lead.id === response.data.id ? response.data : lead) } : current);
      setNotice("Lead updated.");
    } catch (caught) {
      const next = caught as ApiError;
      setNotice(next.code === "LEAD_VERSION_CONFLICT" ? "This lead changed elsewhere. Refresh before updating it again." : next.message);
    }
  }

  async function createViewing(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected || !agencyId) return;
    const form = event.currentTarget;
    const values = new FormData(form);
    const start = new Date(String(values.get("starts_at")));
    const durationMinutes = Number(values.get("duration") ?? 30);
    setNotice(null);
    try {
      const response = await apiMutation<{ data: Viewing }>("/api/v1/viewings", {
        lead_id: selected.id,
        starts_at: start.toISOString(),
        ends_at: new Date(start.getTime() + durationMinutes * 60_000).toISOString(),
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        notes: String(values.get("notes") ?? "") || null,
      }, { agencyId });
      setData((current) => current ? { ...current, viewings: [...current.viewings, response.data].sort((a, b) => a.starts_at.localeCompare(b.starts_at)) } : current);
      form.reset();
      setNotice("Viewing request created.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function createReminder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!agencyId) return;
    const form = event.currentTarget;
    const values = new FormData(form);
    try {
      const response = await apiMutation<{ data: Reminder }>("/api/v1/reminders", {
        title: String(values.get("title") ?? ""), due_at: new Date(String(values.get("due_at"))).toISOString(), lead_id: selected?.id ?? null,
      }, { agencyId });
      setData((current) => current ? { ...current, reminders: [...current.reminders, response.data].sort((a, b) => a.due_at.localeCompare(b.due_at)) } : current);
      form.reset(); setNotice("Reminder added.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function updateReminder(reminder: Reminder, status: "completed" | "cancelled") {
    if (!agencyId) return;
    try {
      const response = await apiMutation<{ data: Reminder }>(`/api/v1/reminders/${reminder.id}`, { status }, { method: "PATCH", agencyId });
      setData((current) => current ? { ...current, reminders: current.reminders.map((item) => item.id === response.data.id ? response.data : item) } : current);
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function markRead(notification: UserNotification) {
    try {
      const response = await apiMutation<{ data: UserNotification }>(`/api/v1/notifications/${notification.id}`, { read: true }, { method: "PATCH" });
      setData((current) => current ? { ...current, notifications: current.notifications.map((item) => item.id === response.data.id ? response.data : item) } : current);
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  return <main className="workspace-canvas collaboration-canvas">
    <header className="workspace-title collaboration-title">
      <div><p>Inquiry → response → viewing → outcome</p><h1>Leads & collaboration</h1><p>One calm queue for every customer handoff.</p></div>
      <div className="workspace-title__actions"><button className="button button--outline" type="button" onClick={() => void load()}><Icon name="sparkle" /> Refresh</button><a className="notification-summary" href="#notifications"><span aria-hidden="true" />{unread} unread</a></div>
    </header>

    {error ? <section className="operations-state" role="alert"><Icon name={error.code === "UNAUTHENTICATED" ? "user" : "shield"} /><h2>{error.code === "UNAUTHENTICATED" ? "Sign in to collaborate" : "Collaboration is unavailable"}</h2><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => void load()}>Try again</button></section> : null}
    {!error && !data ? <section className="operations-state" role="status"><span className="inline-spinner" /><h2>Opening the collaboration desk</h2><p>Loading leads, viewings, reminders, notifications, and response metrics.</p></section> : null}
    {data ? <>
      <section className="metric-strip collaboration-metrics" aria-label="Response performance">
        <Metric value={data.analytics.total_leads} label="Total leads" icon="team" />
        <Metric value={data.analytics.responded_leads} label="Responded" icon="message" />
        <Metric value={`${data.analytics.response_rate}%`} label="Response coverage" icon="chart" />
        <Metric value={formatDuration(data.analytics.average_first_response_seconds)} label="Average first response" icon="calendar" />
      </section>
      <p className="async-notice" role="status" aria-live="polite">{notice}</p>
      <div className="collaboration-layout">
        <section className="dashboard-panel lead-queue" aria-labelledby="lead-queue-title">
          <header className="panel-heading"><h2 id="lead-queue-title">Lead queue</h2><label>Stage<select value={filter} onChange={(event) => setFilter(event.target.value as LeadStatus | "all")}><option value="all">All stages</option>{leadStatuses.map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}</select></label></header>
          {filtered.length ? <div className="lead-queue__list">{filtered.map((lead) => <button className={selectedId === lead.id ? "is-active" : undefined} type="button" key={lead.id} onClick={() => setSelectedId(lead.id)}><i className={`signal-dot signal-dot--${lead.status}`} /><span><strong>{lead.contact.name}</strong><small>{lead.contact.email}</small></span><span><b>{titleCase(lead.status)}</b><small>{relativeTime(lead.created_at)}</small></span></button>)}</div> : <div className="panel-empty"><strong>No {filter === "all" ? "" : `${filter} `}leads</strong><p>New property inquiries will enter this queue automatically.</p></div>}
        </section>

        <section className="dashboard-panel lead-detail" aria-labelledby="lead-detail-title">
          {selected ? <><header><div><p>Listing {selected.listing_id.slice(0, 8)}</p><h2 id="lead-detail-title">{selected.contact.name}</h2><a href={`mailto:${selected.contact.email}`}>{selected.contact.email}</a>{selected.contact.phone ? <a href={`tel:${selected.contact.phone}`}>{selected.contact.phone}</a> : null}</div><span className={`status-chip status-chip--${selected.status}`}>{titleCase(selected.status)}</span></header>
            <div className="lead-detail__controls"><label>Status<select value={selected.status} onChange={(event) => void updateLead({ status: event.target.value as LeadStatus })}>{leadStatuses.map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}</select></label><label>Priority<select value={selected.priority} onChange={(event) => void updateLead({ priority: event.target.value as Lead["priority"] })}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></label></div>
            {selected.conversation_id ? <ConversationPanel conversationId={selected.conversation_id} agencyId={agencyId ?? ""} /> : <div className="panel-empty">This lead has no conversation.</div>}
            <form className="viewing-form" onSubmit={(event) => void createViewing(event)}><h3>Request a viewing</h3><label>Starts<input name="starts_at" type="datetime-local" required /></label><label>Duration<select name="duration" defaultValue="30"><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option></select></label><label className="viewing-form__wide">Note<input name="notes" maxLength={3000} placeholder="Access or appointment context" /></label><button className="button button--primary" type="submit">Create request</button></form>
          </> : <div className="lead-detail__empty"><Icon name="message" /><h2 id="lead-detail-title">Select a lead</h2><p>Choose a lead to update its stage, reply, or schedule a viewing.</p></div>}
        </section>

        <aside className="collaboration-rail">
          <section className="dashboard-panel" id="viewings"><header className="panel-heading"><h2>Upcoming viewings</h2><span>{data.viewings.length}</span></header>{data.viewings.length ? <ol className="signal-list">{data.viewings.map((viewing) => <AgencyViewing key={viewing.id} viewing={viewing} agencyId={agencyId ?? ""} onUpdate={(next) => setData((current) => current ? { ...current, viewings: current.viewings.map((item) => item.id === next.id ? next : item) } : current)} onError={setNotice} />)}</ol> : <p className="panel-empty">No viewings scheduled.</p>}</section>
          <section className="dashboard-panel reminders-panel"><header className="panel-heading"><h2>Reminders</h2><span>{data.reminders.filter((item) => item.status === "pending").length} pending</span></header><form onSubmit={(event) => void createReminder(event)}><label>Reminder<input name="title" minLength={2} maxLength={200} required placeholder="Follow up with this lead" /></label><label>Due<input name="due_at" type="datetime-local" required /></label><button className="button button--outline" type="submit">Add reminder</button></form>{data.reminders.length ? <ul>{data.reminders.map((reminder) => <li key={reminder.id}><i className={`signal-dot${reminder.status !== "pending" ? " is-muted" : ""}`} /><span><strong>{reminder.title}</strong><small>{formatDate(reminder.due_at)}</small></span>{reminder.status === "pending" ? <div className="reminder-actions"><button type="button" onClick={() => void updateReminder(reminder, "completed")}>Complete</button><button type="button" onClick={() => void updateReminder(reminder, "cancelled")}>Cancel</button></div> : <em>{titleCase(reminder.status)}</em>}</li>)}</ul> : null}</section>
          <section className="dashboard-panel notifications-panel" id="notifications"><header className="panel-heading"><h2>Notifications</h2><span>{unread} unread</span></header>{data.notifications.length ? <ul>{data.notifications.map((notification) => <li key={notification.id} className={notification.read ? "is-read" : undefined}><i className="signal-dot" /><span><strong>{notification.title}</strong><small>{notification.body ?? relativeTime(notification.created_at)}</small></span>{!notification.read ? <button type="button" onClick={() => void markRead(notification)}>Mark read</button> : null}</li>)}</ul> : <p className="panel-empty">You’re all caught up.</p>}</section>
        </aside>
      </div>
    </> : null}
  </main>;
}

function Metric({ value, label, icon }: { value: string | number; label: string; icon: "team" | "message" | "chart" | "calendar" }) {
  return <div><span className="metric-icon"><Icon name={icon} /></span><strong>{value}</strong><small>{label}</small></div>;
}

function ConversationPanel({ conversationId, agencyId }: { conversationId: string; agencyId: string }) {
  const [messages, setMessages] = useState<Message[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const cursorRef = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    cursorRef.current = null;
    async function poll() {
      try {
        const suffix = cursorRef.current ? `?after=${encodeURIComponent(cursorRef.current)}` : "";
        const response = await apiQuery<{ data: Message[]; meta: { next_cursor: string | null } }>(`/api/v1/conversations/${conversationId}/messages${suffix}`, agencyId);
        if (cancelled) return;
        setMessages((current) => cursorRef.current ? mergeMessages(current, response.data) : response.data);
        cursorRef.current = response.meta.next_cursor ?? cursorRef.current;
        setError(null);
      } catch (caught) { if (!cancelled) setError((caught as ApiError).message); }
    }
    void poll();
    const interval = window.setInterval(() => void poll(), 8_000);
    return () => { cancelled = true; window.clearInterval(interval); };
  }, [agencyId, conversationId]);

  async function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const body = String(new FormData(form).get("body") ?? "").trim();
    if (!body) return;
    setBusy(true); setError(null);
    try {
      const response = await apiMutation<{ data: Message }>(`/api/v1/conversations/${conversationId}/messages`, { body }, { agencyId });
      setMessages((current) => mergeMessages(current, [response.data])); cursorRef.current = response.data.id; form.reset();
    } catch (caught) { setError((caught as ApiError).message); }
    finally { setBusy(false); }
  }

  return <section className="conversation-panel" id="conversation" aria-labelledby="conversation-title"><div className="conversation-panel__heading"><h3 id="conversation-title">Conversation</h3><span><i /> Polling safely</span></div><div className="conversation-panel__messages" role="log" aria-live="polite">{messages.length ? messages.map((message) => <article key={message.id}><p>{message.body}</p><time dateTime={message.created_at}>{formatDate(message.created_at)}</time></article>) : <p className="panel-empty">No messages yet.</p>}</div><form onSubmit={(event) => void send(event)}><label className="sr-only" htmlFor={`message-${conversationId}`}>Message</label><textarea id={`message-${conversationId}`} name="body" maxLength={5000} placeholder="Write a plain-text reply…" required /><button className="button button--primary" type="submit" disabled={busy}>{busy ? "Sending…" : "Send"}</button></form>{error ? <p className="inline-error" role="alert">{error}</p> : null}</section>;
}

function AgencyViewing({ viewing, agencyId, onUpdate, onError }: { viewing: Viewing; agencyId: string; onUpdate: (viewing: Viewing) => void; onError: (message: string) => void }) {
  async function update(status: Viewing["status"]) {
    try {
      const response = await apiMutation<{ data: Viewing }>(`/api/v1/viewings/${viewing.id}`, { version: viewing.version, status }, { method: "PATCH", agencyId });
      onUpdate(response.data);
    } catch (caught) { onError((caught as ApiError).message); }
  }
  async function calendar() {
    try {
      const response = await apiTextQuery(`/api/v1/viewings/${viewing.id}/calendar`, agencyId);
      const url = URL.createObjectURL(new Blob([response.body], { type: response.contentType }));
      const link = document.createElement("a"); link.href = url; link.download = `viewing-${viewing.id}.ics`; link.click(); URL.revokeObjectURL(url);
    } catch (caught) { onError((caught as ApiError).message); }
  }
  return <li><i className={`signal-dot signal-dot--${viewing.status}`} /><div><strong>{formatDate(viewing.starts_at)}</strong><span>{titleCase(viewing.status)} · {viewing.timezone}</span>{viewing.warnings?.map((warning) => <small key={warning.code}>{warning.message}{warning.overlap_count ? ` (${warning.overlap_count})` : ""}</small>)}</div><div className="viewing-actions">{viewing.status === "requested" ? <><button type="button" onClick={() => void update("confirmed")}>Confirm</button><button type="button" onClick={() => void update("cancelled")}>Cancel</button></> : null}{viewing.status === "confirmed" ? <><button type="button" onClick={() => void update("completed")}>Complete</button><button type="button" onClick={() => void update("cancelled")}>Cancel</button><button type="button" onClick={() => void update("no_show")}>No-show</button><button type="button" onClick={() => void calendar()}>Calendar</button></> : null}</div></li>;
}

function mergeMessages(current: Message[], incoming: Message[]): Message[] {
  const byId = new Map(current.map((message) => [message.id, message]));
  incoming.forEach((message) => byId.set(message.id, message));
  return [...byId.values()].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id.localeCompare(b.id));
}

function titleCase(value: string): string { return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function formatDate(value: string): string { return localizedDate(value, { dateStyle: "medium", timeStyle: "short" }); }
function relativeTime(value: string): string { const minutes = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 60_000)); return minutes < 60 ? `${minutes}m ago` : minutes < 1440 ? `${Math.floor(minutes / 60)}h ago` : formatDate(value); }
function formatDuration(seconds: number | null): string { if (seconds === null) return "—"; if (seconds < 60) return `${seconds}s`; if (seconds < 3600) return `${Math.round(seconds / 60)}m`; return `${(seconds / 3600).toFixed(1)}h`; }
