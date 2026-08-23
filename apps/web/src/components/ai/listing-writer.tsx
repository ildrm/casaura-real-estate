"use client";

import Link from "next/link";
import { type FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { ListingProjection } from "@/lib/listing-types";

type Suggestion = { id: string; listing_id: string; source_listing_version: number; suggested_fields: { title: string; description: string }; applied_fields: string[] | null; applied_at: string | null };

export function ListingWriter({ listingId }: { listingId: string }) {
  const agencyIdRef = useRef<string | null>(null);
  const [listing, setListing] = useState<ListingProjection | null>(null);
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
  const [fields, setFields] = useState<Array<"title" | "description">>(["title", "description"]);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const agencyId = activeAgencyId(); agencyIdRef.current = agencyId;
    if (!agencyId) { setError({ code: "AGENCY_REQUIRED", message: "Select an agency or sign in again." }); setLoading(false); return; }
    try { setListing((await apiQuery<{ data: ListingProjection }>(`/api/v1/listings/${listingId}`, agencyId)).data); }
    catch (caught) { setError(caught as ApiError); }
    finally { setLoading(false); }
  }, [listingId]);

  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  async function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!listing || !agencyIdRef.current) return;
    const instruction = String(new FormData(event.currentTarget).get("instruction") ?? "").trim();
    setWorking(true); setError(null); setNotice(null);
    try {
      const response = await apiMutation<{ data: Suggestion }>(`/api/v1/listings/${listing.id}/ai-suggestions`, { instruction, version: listing.version }, { agencyId: agencyIdRef.current });
      setSuggestion(response.data); setFields(["title", "description"]);
      setNotice("Suggestion generated from current listing facts. The draft has not changed.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function apply() {
    if (!listing || !suggestion || !agencyIdRef.current || !fields.length) return;
    setWorking(true); setError(null);
    try {
      await apiMutation(`/api/v1/listings/${listing.id}/ai-suggestions/${suggestion.id}/apply`, { fields, version: listing.version }, { agencyId: agencyIdRef.current });
      setNotice(`Applied ${fields.join(" and ")} after a fresh listing-version check.`);
      setSuggestion((current) => current ? { ...current, applied_fields: fields, applied_at: new Date().toISOString() } : current);
      await load();
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  function toggle(field: "title" | "description") { setFields((current) => current.includes(field) ? current.filter((item) => item !== field) : [...current, field]); }

  return <main className="workspace-canvas release-canvas listing-writer"><header className="workspace-title release-title"><div><h1>Grounded listing writer</h1><p>Generate title and description suggestions from tenant-owned facts. Nothing applies automatically.</p></div><Link className="button button--outline" href={`/agency/properties/${listingId}/edit`}>Back to editor</Link></header>
    {error ? <section className="release-inline-error" role="alert"><Icon name="shield" /><span><strong>{error.code === "LISTING_VERSION_CONFLICT" ? "The listing changed" : error.code === "FEATURE_DISABLED" ? "Listing assistant unavailable" : "Suggestion request failed"}</strong><small>{error.message}</small></span><button className="button button--outline" type="button" onClick={() => void load()}>Refresh listing</button></section> : null}
    {notice ? <p className="async-notice release-notice" role="status">{notice}</p> : null}
    {loading ? <section className="operations-state release-state" role="status"><span className="inline-spinner" /><h2>Loading canonical listing facts</h2></section> : null}
    {listing ? <div className="listing-writer-layout"><section className="release-panel listing-source"><header className="release-panel__heading"><div><h2>Current draft</h2><p>Version {listing.version} · {listing.reference}</p></div><em className={`release-status release-status--${listing.status === "published" ? "active" : "pending"}`}>{listing.status}</em></header><dl><div><dt>Title</dt><dd>{listing.title || "Not provided"}</dd></div><div><dt>Description</dt><dd>{listing.description || "Not provided"}</dd></div><div><dt>Property type</dt><dd>{listing.property.property_type.name}</dd></div><div><dt>Bedrooms / bathrooms</dt><dd>{listing.property.bedrooms ?? "—"} / {listing.property.bathrooms ?? "—"}</dd></div><div><dt>Location</dt><dd>{[listing.property.address?.locality, listing.property.address?.region].filter(Boolean).join(", ") || "Not provided"}</dd></div></dl></section>
      <section className="release-panel listing-suggestion"><header className="release-panel__heading"><div><h2>Human-approved suggestion</h2><p>Generated text is treated as untrusted until validation and apply.</p></div><Icon name="sparkle" /></header><form onSubmit={(event) => void generate(event)}><label htmlFor="writer-instruction">Writing instruction<textarea id="writer-instruction" name="instruction" minLength={3} maxLength={1000} defaultValue="Write a concise, factual title and description using only the supplied listing details. Do not infer neighborhood quality, investment returns, or unavailable facts." rows={5} required /></label><button className="button button--primary" type="submit" disabled={working}>{working ? "Generating…" : suggestion ? "Generate another" : "Generate suggestion"}</button></form>{suggestion ? <div className="suggestion-review"><label><input type="checkbox" checked={fields.includes("title")} onChange={() => toggle("title")} /><span><strong>Suggested title</strong><p>{suggestion.suggested_fields.title}</p></span></label><label><input type="checkbox" checked={fields.includes("description")} onChange={() => toggle("description")} /><span><strong>Suggested description</strong><p>{suggestion.suggested_fields.description}</p></span></label><div className="release-inline-warning"><Icon name="shield" /><span><strong>Explicit apply required</strong><small>Applying revalidates selected fields against listing version {suggestion.source_listing_version} and appends history.</small></span></div><button className="button button--primary" type="button" disabled={working || !fields.length || Boolean(suggestion.applied_at)} onClick={() => void apply()}>{suggestion.applied_at ? "Suggestion applied" : working ? "Applying…" : `Apply ${fields.length} selected ${fields.length === 1 ? "field" : "fields"}`}</button></div> : null}</section></div> : null}
  </main>;
}
