"use client";

import Link from "next/link";
import { type FormEvent, useEffect, useMemo, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import { formatMoney } from "@/lib/localization";
import type { AiMatch, AiSearchResult, ConsumerCollection } from "@/lib/release-types";

type AiSession = { id: string; purpose: string; status: string; created_at: string; updated_at: string };

export function PropertyAssistant() {
  const [question, setQuestion] = useState("Find 3-bedroom homes under $850,000");
  const [submittedQuestion, setSubmittedQuestion] = useState<string | null>(null);
  const [result, setResult] = useState<AiSearchResult | null>(null);
  const [collections, setCollections] = useState<ConsumerCollection[]>([]);
  const [sessions, setSessions] = useState<AiSession[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      const [collectionResult, sessionResult] = await Promise.allSettled([
        apiQuery<{ data: ConsumerCollection[] }>("/api/v1/account/collections"),
        apiQuery<{ data: AiSession[] }>("/api/v1/account/ai-sessions"),
      ]);
      if (collectionResult.status === "fulfilled") setCollections(collectionResult.value.data);
      if (sessionResult.status === "fulfilled") setSessions(sessionResult.value.data);
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  async function ask(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const prompt = question.trim();
    setLoading(true); setError(null); setNotice(null); setSubmittedQuestion(prompt); setSelected([]);
    try {
      const response = await apiMutation<{ data: AiSearchResult }>("/api/v1/ai/search", { message: prompt });
      setResult(response.data);
    } catch (caught) { setError(caught as ApiError); setResult(null); }
    finally { setLoading(false); }
  }

  function toggle(listingId: string) {
    setSelected((current) => current.includes(listingId) ? current.filter((id) => id !== listingId) : current.length < 5 ? [...current, listingId] : current);
  }

  async function addToCollection(listingId: string, collectionId: string) {
    if (!collectionId) return;
    try {
      const response = await apiMutation<{ data: ConsumerCollection }>(`/api/v1/account/collections/${collectionId}/items`, { listing_id: listingId }, { method: "PUT" });
      setCollections((current) => current.map((collection) => collection.id === collectionId ? response.data : collection));
      setNotice(`Home added to ${response.data.name}.`);
    } catch (caught) { setError(caught as ApiError); }
  }

  async function sendFeedback(helpful: boolean) {
    if (!result) return;
    try {
      await apiMutation(`/api/v1/account/ai-generations/${result.id}/feedback`, { helpful });
      setNotice("Thanks. Your feedback was recorded without retaining the search text in logs.");
    } catch (caught) {
      if ((caught as ApiError).code !== "UNAUTHENTICATED") setError(caught as ApiError);
    }
  }

  async function deleteSession(sessionId: string) {
    try {
      await apiMutation(`/api/v1/account/ai-sessions/${sessionId}`, {}, { method: "DELETE" });
      setSessions((current) => current.filter((session) => session.id !== sessionId));
      setNotice("Conversation content deleted.");
    } catch (caught) { setError(caught as ApiError); }
  }

  const filters = useMemo(() => Object.entries(result?.parsed_filters ?? {}).map(([key, value]) => `${filterLabel(key)} ${filterValue(key, value)}`), [result]);
  const confirmedSearchUrl = useMemo(() => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(result?.parsed_filters ?? {})) {
      if (key === "bedrooms_min") params.set("beds", String(value));
      if (key === "price_max_minor" && typeof value === "number") params.set("max_price", String(Math.round(value / 100)));
      if (key === "property_type_slug") params.set("type", String(value));
      if (key === "locality") params.set("q", String(value));
    }
    return `/search?${params.toString()}`;
  }, [result]);

  return <main className="assistant-page">
    <div className="assistant-main"><header className="assistant-heading"><h1>Property assistant</h1><p>Search and compare homes with answers grounded in current listing data.</p></header>
      <form className="assistant-composer" onSubmit={(event) => void ask(event)}><label htmlFor="assistant-question">Ask about published homes</label><div><textarea id="assistant-question" value={question} minLength={3} maxLength={2000} rows={2} onChange={(event) => setQuestion(event.target.value)} placeholder="Find three-bedroom homes under $850,000" required /><button className="button button--primary" type="submit" disabled={loading}>{loading ? "Searching…" : "Search with assistant"}</button></div><small>Do not include private contact details. Casaura does not provide legal, tax, mortgage, or investment advice.</small></form>
      {submittedQuestion ? <section className="assistant-exchange" aria-live="polite"><div className="assistant-message assistant-message--user"><span><Icon name="user" /></span><div><strong>You</strong><p>{submittedQuestion}</p></div></div>{loading ? <div className="assistant-message"><span><span className="inline-spinner" /></span><div><strong>Casaura assistant</strong><p>Retrieving current published listing facts…</p></div></div> : null}{result ? <div className="assistant-message"><span><Icon name="sparkle" /></span><div><strong>Casaura assistant</strong><p>{result.message}</p><div className="filter-confirmation"><Icon name="check" /><span><strong>Filters proposed—not applied</strong><small>{filters.length ? filters.join(" · ") : "No structured filters were inferred. Review the matches below."}</small></span>{filters.length ? <Link className="button button--outline" href={confirmedSearchUrl}>Confirm filters in search</Link> : null}</div><p className="assistant-provider">{result.provider.adapter === "deterministic_fallback" ? "Grounded fallback used" : "Grounded response"} · {result.citations.length} current sources</p></div></div> : null}</section> : null}
      {error ? <section className={`release-inline-error ${error.code === "AI_SAFETY_REFUSAL" ? "assistant-refusal" : ""}`} role="alert"><Icon name="shield" /><span><strong>{error.code === "AI_SAFETY_REFUSAL" ? "That request cannot be answered" : error.code === "FEATURE_DISABLED" ? "Property assistant is unavailable" : "The assistant could not complete this search"}</strong><small>{error.message}{error.code === "AI_SAFETY_REFUSAL" ? " Try asking about current published listings, property facts, or market aggregates." : ""}</small></span><button className="button button--outline" type="button" onClick={() => setError(null)}>Dismiss</button></section> : null}
      {notice ? <p className="async-notice release-notice" role="status">{notice}</p> : null}
      {result ? <section className="assistant-results" aria-labelledby="assistant-results-title"><header><div><h2 id="assistant-results-title">{result.matches.length} matching homes</h2><p>Confirm facts on each live listing before making a decision.</p></div><span>{selected.length}/5 selected</span></header>{result.matches.length ? <div className="assistant-result-list">{result.matches.map((match, index) => <AssistantResult match={match} citation={result.citations.find((citation) => citation.listing_id === match.listing_id)?.url} selected={selected.includes(match.listing_id)} collections={collections} onToggle={() => toggle(match.listing_id)} onAdd={(collectionId) => void addToCollection(match.listing_id, collectionId)} index={index} key={match.listing_id} />)}</div> : <section className="release-empty"><Icon name="search" /><h2>No current homes match</h2><p>Adjust the price, bedrooms, property type, or location and try again.</p></section>}<footer className="assistant-feedback"><span>Was this grounded result useful?</span><button type="button" onClick={() => void sendFeedback(true)}>Yes</button><button type="button" onClick={() => void sendFeedback(false)}>No</button></footer></section> : null}
    </div>
    <aside className="assistant-rail">
      <section><h2>Sources used</h2>{result?.citations.length ? <ol>{result.citations.map((citation, index) => <li key={citation.listing_id}><span>{index + 1}</span><div><strong>{result.matches.find((match) => match.listing_id === citation.listing_id)?.title ?? "Published listing"}</strong><small>Projection v{citation.projection_version} · {citation.fields.join(", ")}</small></div><Link href={citation.url}>View source</Link></li>)}</ol> : <p>Sources appear here after a grounded search.</p>}</section>
      <section><header><h2>My collections</h2><Link href="/collections">Manage</Link></header>{collections.length ? <ul>{collections.slice(0, 5).map((collection) => <li key={collection.id}><Icon name="heart" /><span><strong>{collection.name}</strong><small>{collection.items.length} homes · Private</small></span></li>)}</ul> : <p>Sign in and create a private collection to save assistant matches.</p>}</section>
      <details><summary>Assumptions &amp; limits <Icon name="chevron-down" /></summary><ul>{result?.assumptions.map((assumption) => <li key={assumption}>{assumption}</li>) ?? <li>Only current published Casaura data may be used.</li>}</ul></details>
      {sessions.length ? <details><summary>Conversation privacy <Icon name="chevron-down" /></summary><p>Delete retained conversation content at any time.</p><ul>{sessions.slice(0, 5).map((session) => <li key={session.id}><span><strong>{session.purpose}</strong><small>{new Date(session.updated_at).toLocaleDateString()}</small></span><button type="button" onClick={() => void deleteSession(session.id)}>Delete</button></li>)}</ul></details> : null}
    </aside>
    {selected.length ? <div className="assistant-compare-tray"><span><strong>{selected.length} {selected.length === 1 ? "home" : "homes"} selected</strong><small>Choose 2–5 homes to compare.</small></span><Link className={`button ${selected.length >= 2 ? "button--outline" : "button--disabled"}`} aria-disabled={selected.length < 2} tabIndex={selected.length < 2 ? -1 : undefined} href={selected.length >= 2 ? `/compare?ids=${selected.join(",")}` : "#"}>Compare homes ({selected.length}) <Icon name="arrow-right" /></Link></div> : null}
  </main>;
}

function AssistantResult({ match, citation, selected, collections, onToggle, onAdd, index }: { match: AiMatch; citation?: string; selected: boolean; collections: ConsumerCollection[]; onToggle: () => void; onAdd: (collectionId: string) => void; index: number }) {
  const reason = [match.bedrooms != null ? `${match.bedrooms} bedrooms` : null, match.locality, match.amenities?.slice(0, 2).join(", ")].filter(Boolean).join(" · ");
  return <article className={selected ? "is-selected" : undefined}><label><input type="checkbox" checked={selected} onChange={onToggle} /><span className="sr-only">Add {match.title} to comparison</span></label><span className="assistant-result-number">{index + 1}</span><div><h3>{match.title}</h3><p>{[match.locality, match.region].filter(Boolean).join(", ") || "Location available on listing"}</p></div><strong>{match.price_amount_minor && match.currency ? formatMoney(match.price_amount_minor, match.currency) : "Contact for price"}</strong><span>{match.bedrooms ?? "—"} bd / {match.bathrooms ?? "—"} ba{match.interior_area_sqm ? ` · ${Math.round(match.interior_area_sqm)} m²` : ""}</span><p>{reason || "Matched from current published facts."}</p><div>{citation ? <Link className="button button--outline" href={citation}>View home</Link> : null}{collections.length ? <label className="assistant-collection-select"><span className="sr-only">Add {match.title} to collection</span><select defaultValue="" onChange={(event) => { onAdd(event.target.value); event.currentTarget.value = ""; }}><option value="" disabled>Add to collection</option>{collections.filter((collection) => collection.role !== "viewer").map((collection) => <option value={collection.id} key={collection.id}>{collection.name}</option>)}</select></label> : <Link href="/collections">Create collection</Link>}</div></article>;
}

function filterLabel(key: string): string {
  return ({ bedrooms_min: "Bedrooms", price_max_minor: "Price", property_type_slug: "Type", locality: "Location", currency: "Currency" } as Record<string, string>)[key] ?? key.replaceAll("_", " ");
}

function filterValue(key: string, value: string | number): string {
  if (key === "price_max_minor" && typeof value === "number") return `≤ ${formatMoney(value, "USD")}`;
  if (key === "bedrooms_min") return `≥ ${value}`;
  return String(value);
}
