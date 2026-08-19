"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, apiTextQuery, type ApiError } from "@/lib/api-client";
import type { AccountCollaboration as AccountCollaborationData, Message } from "@/lib/operations-types";

export function AccountCollaboration() {
  const [data, setData] = useState<AccountCollaborationData | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [selected, setSelected] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try { setData((await apiQuery<{ data: AccountCollaborationData }>("/api/v1/account/collaboration")).data); }
      catch (caught) { setError(caught as ApiError); }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  if (error?.code === "UNAUTHENTICATED") return null;

  return (
    <section className="account-collaboration" aria-labelledby="account-collaboration-title">
      <header>
        <div><p>Inquiry → viewing → outcome</p><h2 id="account-collaboration-title">Agency collaboration</h2></div>
        {data ? <span>{data.conversations.length} conversation{data.conversations.length === 1 ? "" : "s"}</span> : null}
      </header>
      {error ? <div className="account-collaboration__state" role="alert"><Icon name="message" /><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => window.location.reload()}>Try again</button></div> : null}
      {!error && !data ? <div className="account-collaboration__state" role="status"><span className="inline-spinner" /> Loading your agency conversations…</div> : null}
      {data ? <div className="account-collaboration__grid">
        <section className="account-collaboration__viewings" aria-labelledby="account-viewings-title">
          <h3 id="account-viewings-title">Viewings</h3>
          {data.viewings.length ? <ol className="signal-list">{data.viewings.map((viewing) => <ViewingRow key={viewing.id} viewing={viewing} />)}</ol> : <p className="account-empty">Confirmed and requested viewing times will appear here.</p>}
        </section>
        <section className="account-collaboration__conversations" aria-labelledby="account-conversations-title">
          <h3 id="account-conversations-title">Conversations</h3>
          {data.conversations.length ? <div className="conversation-picker">{data.conversations.map((conversation) => <button type="button" className={selected === conversation.id ? "is-active" : undefined} aria-pressed={selected === conversation.id} key={conversation.id} onClick={() => setSelected(conversation.id)}><Icon name="message" /><span><strong>{conversation.subject}</strong><small>Updated {formatDate(conversation.last_message_at)}</small></span><Icon name="chevron-right" /></button>)}</div> : <p className="account-empty">Conversations start after you send a property inquiry.</p>}
          {selected ? <Conversation conversationId={selected} /> : null}
        </section>
      </div> : null}
    </section>
  );
}

function ViewingRow({ viewing }: { viewing: AccountCollaborationData["viewings"][number] }) {
  const [notice, setNotice] = useState<string | null>(null);

  async function downloadCalendar() {
    setNotice("Preparing calendar…");
    try {
      const response = await apiTextQuery(`/api/v1/viewings/${viewing.id}/calendar`);
      const url = URL.createObjectURL(new Blob([response.body], { type: response.contentType }));
      const link = document.createElement("a");
      link.href = url;
      link.download = `casaura-viewing-${viewing.id}.ics`;
      link.click();
      URL.revokeObjectURL(url);
      setNotice("Calendar file downloaded.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  return <li><i aria-hidden="true" /><div><strong>{formatDate(viewing.starts_at)}</strong><span>{viewing.timezone} · {viewing.status.replaceAll("_", " ")}</span></div>{viewing.status === "confirmed" ? <button type="button" onClick={() => void downloadCalendar()}>Add to calendar</button> : null}{notice ? <small role="status" aria-live="polite">{notice}</small> : null}</li>;
}

function Conversation({ conversationId }: { conversationId: string }) {
  const [messages, setMessages] = useState<Message[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const cursorRef = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    cursorRef.current = null;
    const load = async () => {
      try {
        const suffix = cursorRef.current ? `?after=${encodeURIComponent(cursorRef.current)}` : "";
        const response = await apiQuery<{ data: Message[]; meta: { next_cursor: string | null } }>(`/api/v1/conversations/${conversationId}/messages${suffix}`);
        if (cancelled) return;
        setMessages((current) => cursorRef.current ? mergeMessages(current, response.data) : response.data);
        cursorRef.current = response.meta.next_cursor ?? cursorRef.current;
        setError(null);
      } catch (caught) {
        if (!cancelled) setError((caught as ApiError).message);
      }
    };
    void load();
    const interval = window.setInterval(() => void load(), 10_000);
    return () => { cancelled = true; window.clearInterval(interval); };
  }, [conversationId]);

  async function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const body = String(new FormData(form).get("body") ?? "").trim();
    if (!body) return;
    setBusy(true); setError(null);
    try {
      const response = await apiMutation<{ data: Message }>(`/api/v1/conversations/${conversationId}/messages`, { body });
      setMessages((current) => mergeMessages(current, [response.data]));
      cursorRef.current = response.data.id;
      form.reset();
    } catch (caught) { setError((caught as ApiError).message); }
    finally { setBusy(false); }
  }

  return <div className="consumer-conversation"><div className="consumer-conversation__messages" role="log" aria-live="polite">{messages.length ? messages.map((message) => <article key={message.id}><p>{message.body}</p><time dateTime={message.created_at}>{formatDate(message.created_at)}</time></article>) : <p className="account-empty">No messages in this conversation yet.</p>}</div><form onSubmit={(event) => void send(event)}><label htmlFor={`reply-${conversationId}`}>Reply</label><textarea id={`reply-${conversationId}`} name="body" maxLength={5000} required /><button className="button button--primary" type="submit" disabled={busy}>{busy ? "Sending…" : "Send reply"}</button>{error ? <p role="alert">{error}</p> : null}</form></div>;
}

function mergeMessages(current: Message[], incoming: Message[]): Message[] {
  const byId = new Map(current.map((message) => [message.id, message]));
  incoming.forEach((message) => byId.set(message.id, message));
  return [...byId.values()].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id.localeCompare(b.id));
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}
