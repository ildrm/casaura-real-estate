"use client";

import Link from "next/link";
import Image from "next/image";
import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, publicApiQuery, type ApiError, publicAssetUrl } from "@/lib/api-client";
import { formatMoney } from "@/lib/localization";
import type { AiCitation, ComparisonItem } from "@/lib/release-types";

type AiComparison = { id: string; message: string; citations: AiCitation[]; provider: { adapter: string; model: string }; safety: { status: string } };
type ComparisonHistory = { id: string; listing_ids: string[]; created_at: string };

export function ComparisonWorkspace({ initialIds }: { initialIds: string[] }) {
  const [ids, setIds] = useState(initialIds.slice(0, 5));
  const [items, setItems] = useState<ComparisonItem[]>([]);
  const [loading, setLoading] = useState(Boolean(initialIds.length));
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [analysis, setAnalysis] = useState<AiComparison | null>(null);
  const [history, setHistory] = useState<ComparisonHistory[]>([]);

  const load = useCallback(async (listingIds: string[]) => {
    if (listingIds.length < 2 || listingIds.length > 5) {
      setItems([]); setLoading(false);
      if (listingIds.length) setError({ code: "COMPARISON_SIZE_INVALID", message: "Choose between two and five unique homes." });
      return;
    }
    setLoading(true); setError(null); setAnalysis(null);
    try {
      const response = await publicApiQuery<{ data: { items: ComparisonItem[] } }>(`/api/v1/public/compare?ids=${encodeURIComponent(listingIds.join(","))}`);
      setItems(response.data.items);
    } catch (caught) { setError(caught as ApiError); setItems([]); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => { void load(ids); }, 0); return () => window.clearTimeout(timer); }, [ids, load]);
  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try {
        const response = await apiQuery<{ data: ComparisonHistory[] }>("/api/v1/account/comparisons");
        setHistory(response.data);
      } catch { /* Private history remains hidden for signed-out visitors. */ }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  async function saveComparison() {
    setWorking(true);
    try {
      const response = await apiMutation<{ data: ComparisonHistory }>("/api/v1/account/comparisons", { listing_ids: ids });
      setHistory((current) => [response.data, ...current.filter((item) => item.id !== response.data.id)]);
      setNotice("Comparison saved to your private history.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function explain(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const message = String(new FormData(event.currentTarget).get("message") ?? "").trim();
    setWorking(true); setAnalysis(null); setError(null);
    try {
      const response = await apiMutation<{ data: AiComparison }>("/api/v1/ai/comparisons", { message, listing_ids: ids });
      setAnalysis(response.data);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  function remove(id: string) {
    setIds((current) => current.filter((item) => item !== id));
  }

  async function deleteHistory(id: string) {
    try {
      await apiMutation(`/api/v1/account/comparisons/${id}`, {}, { method: "DELETE" });
      setHistory((current) => current.filter((item) => item.id !== id));
      setNotice("Saved comparison deleted.");
    } catch (caught) { setError(caught as ApiError); }
  }

  const rows = useMemo(() => [
    { label: "Price", value: (item: ComparisonItem) => item.price ? formatMoney(item.price.amount_minor, item.price.currency) : "Not listed" },
    { label: "Location", value: (item: ComparisonItem) => item.location.label },
    { label: "Property type", value: (item: ComparisonItem) => item.property_type.name },
    { label: "Bedrooms", value: (item: ComparisonItem) => item.bedrooms?.toString() ?? "Not provided" },
    { label: "Bathrooms", value: (item: ComparisonItem) => item.bathrooms?.toString() ?? "Not provided" },
    { label: "Interior area", value: (item: ComparisonItem) => item.interior_area ? `${Math.round(item.interior_area.sq_ft).toLocaleString()} sq ft` : "Not provided" },
    { label: "Amenities", value: (item: ComparisonItem) => item.amenities.length ? item.amenities.join(", ") : "Not provided" },
    { label: "Data freshness", value: (item: ComparisonItem) => `Projection v${item.freshness.projection_version}` },
  ], []);

  return <main className="comparison-page shell"><header className="comparison-heading"><div><h1>Compare homes</h1><p>Review two to five current published listings side by side. Missing facts stay visible as missing.</p></div><div><Link className="button button--outline" href="/assistant">Back to assistant</Link><button className="button button--primary" type="button" disabled={working || items.length < 2} onClick={() => void saveComparison()}>Save comparison</button></div></header>
    {notice ? <p className="async-notice release-notice" role="status">{notice}</p> : null}
    {error ? <section className="release-inline-error" role="alert"><Icon name="shield" /><span><strong>Comparison unavailable</strong><small>{error.message}</small></span><Link className="button button--outline" href="/search">Choose homes</Link></section> : null}
    {loading ? <section className="operations-state release-state" role="status"><span className="inline-spinner" /><h2>Loading current listing facts</h2></section> : null}
    {history.length ? <details className="comparison-history"><summary>Private comparison history ({history.length}) <Icon name="chevron-down" /></summary><ul>{history.map((entry) => <li key={entry.id}><Link href={`/compare?ids=${entry.listing_ids.join(",")}`}>{entry.listing_ids.length} homes · {new Date(entry.created_at).toLocaleDateString()}</Link><button type="button" onClick={() => void deleteHistory(entry.id)}>Delete</button></li>)}</ul></details> : null}
    {!loading && items.length >= 2 ? <>
      <div className="comparison-scroll" tabIndex={0} aria-label="Home comparison; horizontally scrollable"><table><thead><tr><th scope="col">Fact</th>{items.map((item) => <th scope="col" key={item.id}><span className="comparison-home-media">{item.primary_media?.thumbnail_url ? <Image src={publicAssetUrl(item.primary_media.thumbnail_url) ?? ""} alt={item.primary_media.alt_text ?? ""} width={220} height={108} unoptimized /> : <Icon name="home" />}</span><Link href={item.url}>{item.title}</Link><small>{item.location.label}</small><button type="button" onClick={() => remove(item.id)}>Remove</button></th>)}</tr></thead><tbody>{rows.map((row) => <tr key={row.label}><th scope="row">{row.label}</th>{items.map((item) => <td key={item.id}>{row.value(item)}</td>)}</tr>)}</tbody></table></div>
      <section className="comparison-ai"><header><Icon name="sparkle" /><div><h2>Grounded comparison assistant</h2><p>Ask for a concise explanation using only the facts and citations above.</p></div></header><form onSubmit={(event) => void explain(event)}><label htmlFor="comparison-question">Comparison question</label><div><input id="comparison-question" name="message" minLength={3} maxLength={2000} defaultValue="Summarize the practical trade-offs without giving financial advice." required /><button className="button button--primary" type="submit" disabled={working}>{working ? "Analyzing…" : "Explain differences"}</button></div></form>{analysis ? <div className="comparison-analysis" role="status"><p>{analysis.message}</p><ol>{analysis.citations.map((citation, index) => <li key={citation.listing_id}><Link href={citation.url}>Source {index + 1}</Link><span>{citation.fields.join(", ")}</span></li>)}</ol><small>{analysis.provider.adapter === "deterministic_fallback" ? "Deterministic grounded fallback" : "Grounded AI"}; verify material facts on each listing.</small></div> : null}</section>
    </> : !loading && !error ? <section className="release-empty"><Icon name="home" /><h2>Choose at least two homes</h2><p>Start from search, a private collection, or the property assistant.</p><div><Link className="button button--primary" href="/search">Explore homes</Link><Link className="button button--outline" href="/collections">Open collections</Link></div></section> : null}
  </main>;
}
