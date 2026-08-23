"use client";

import Link from "next/link";
import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { publicApiQuery, type ApiError } from "@/lib/api-client";
import { formatMoney } from "@/lib/localization";

type MarketReport = {
  scope: { locality: string | null; region: string | null };
  range: { from: string; to: string };
  cohort_size: number;
  minimum_cohort: number;
  sufficient_cohort: boolean;
  active_inventory: number | null;
  median_price_minor: number | null;
  median_unit_price_minor_per_sqm: number | null;
  median_listing_age_days: number | null;
  currency: string | null;
  generated_at: string;
};

type MapLayer = { layer: "density" | "price_band" | "property_type"; buckets: Array<{ latitude: number; longitude: number; count: number; value: number | string | null }>; coordinate_policy: "public_approximate" };

function isoDate(date: Date): string { return date.toISOString().slice(0, 10); }

export function MarketIntelligence() {
  const today = useMemo(() => new Date(), []);
  const yearAgo = useMemo(() => new Date(today.getTime() - 365 * 86_400_000), [today]);
  const [from, setFrom] = useState(isoDate(yearAgo));
  const [to, setTo] = useState(isoDate(today));
  const [locality, setLocality] = useState("");
  const [region, setRegion] = useState("");
  const [layer, setLayer] = useState<MapLayer["layer"]>("density");
  const [report, setReport] = useState<MarketReport | null>(null);
  const [mapData, setMapData] = useState<MapLayer | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const reportParams = new URLSearchParams({ from, to });
      if (locality.trim()) reportParams.set("locality", locality.trim());
      if (region.trim()) reportParams.set("region", region.trim());
      const [reportResponse, mapResponse] = await Promise.all([
        publicApiQuery<{ data: MarketReport }>(`/api/v1/public/market-analytics?${reportParams}`),
        publicApiQuery<{ data: MapLayer }>(`/api/v1/public/map-layers?layer=${layer}`),
      ]);
      setReport(reportResponse.data); setMapData(mapResponse.data);
    } catch (caught) { setError(caught as ApiError); }
    finally { setLoading(false); }
  }, [from, layer, locality, region, to]);

  useEffect(() => { const timer = window.setTimeout(() => { void load(); }, 0); return () => window.clearTimeout(timer); }, [load]);

  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); void load(); }

  const plot = useMemo(() => {
    if (!mapData?.buckets.length) return [];
    const latitudes = mapData.buckets.map((bucket) => bucket.latitude);
    const longitudes = mapData.buckets.map((bucket) => bucket.longitude);
    const minLat = Math.min(...latitudes); const maxLat = Math.max(...latitudes);
    const minLng = Math.min(...longitudes); const maxLng = Math.max(...longitudes);
    const maxCount = Math.max(...mapData.buckets.map((bucket) => bucket.count), 1);
    return mapData.buckets.map((bucket) => ({
      ...bucket,
      x: 32 + ((bucket.longitude - minLng) / Math.max(maxLng - minLng, 0.01)) * 536,
      y: 288 - ((bucket.latitude - minLat) / Math.max(maxLat - minLat, 0.01)) * 256,
      radius: 5 + Math.sqrt(bucket.count / maxCount) * 18,
    }));
  }, [mapData]);

  return <main className="market-page shell"><header className="market-heading"><div><h1>Market insights</h1><p>Privacy-bounded aggregates and public-safe map layers from current published inventory.</p></div><Link className="button button--outline" href="/assistant"><Icon name="sparkle" /> Ask the property assistant</Link></header>
    <form className="market-controls" onSubmit={submit}><label>Locality<input value={locality} maxLength={120} placeholder="Optional city" onChange={(event) => setLocality(event.target.value)} /></label><label>Region<input value={region} maxLength={120} placeholder="Optional state" onChange={(event) => setRegion(event.target.value)} /></label><label>From<input type="date" value={from} onChange={(event) => setFrom(event.target.value)} required /></label><label>To<input type="date" value={to} onChange={(event) => setTo(event.target.value)} required /></label><button className="button button--primary" disabled={loading} type="submit">{loading ? "Refreshing…" : "Update report"}</button></form>
    {error ? <section className="release-inline-error" role="alert"><Icon name="shield" /><span><strong>Market report unavailable</strong><small>{error.message}</small></span><button className="button button--outline" type="button" onClick={() => void load()}>Try again</button></section> : null}
    {loading && !report ? <section className="operations-state release-state" role="status"><span className="inline-spinner" /><h2>Calculating privacy-safe aggregates</h2></section> : null}
    {report ? <>
      <section className="market-report-band" aria-label="Market report summary"><div><span>Cohort</span><strong>{report.cohort_size.toLocaleString()}</strong><small>{report.sufficient_cohort ? "Published listings" : `Minimum ${report.minimum_cohort} required`}</small></div><div><span>Median list price</span><strong>{report.median_price_minor != null && report.currency ? formatMoney(report.median_price_minor, report.currency) : "Suppressed"}</strong><small>{report.sufficient_cohort ? "Median, not valuation" : "Sparse cohort protected"}</small></div><div><span>Median per m²</span><strong>{report.median_unit_price_minor_per_sqm != null && report.currency ? formatMoney(report.median_unit_price_minor_per_sqm, report.currency) : "Suppressed"}</strong><small>Published asking prices</small></div><div><span>Median listing age</span><strong>{report.median_listing_age_days != null ? `${Math.round(report.median_listing_age_days)} days` : "Suppressed"}</strong><small>As of report generation</small></div></section>
      {!report.sufficient_cohort ? <section className="release-inline-warning"><Icon name="shield" /><span><strong>Statistics withheld for a sparse cohort</strong><small>Casaura requires at least {report.minimum_cohort} matching published listings before returning medians.</small></span></section> : null}
      <div className="market-layout"><section className="release-panel map-panel"><header className="release-panel__heading"><div><h2>Public-safe map layer</h2><p>Approximate coordinates only; no exact tenant coordinates are returned.</p></div><label><span className="sr-only">Map layer</span><select value={layer} onChange={(event) => setLayer(event.target.value as MapLayer["layer"])}><option value="density">Listing density</option><option value="price_band">Median price band</option><option value="property_type">Property type</option></select></label></header>{plot.length ? <><svg className="market-map-plot" role="img" aria-labelledby="map-title map-desc" viewBox="0 0 600 320"><title id="map-title">{layer.replace("_", " ")} map layer</title><desc id="map-desc">Approximate public coordinate buckets. A text table follows.</desc><path d="M24 52 C120 18 186 74 270 48 S438 14 574 62 V292 H24 Z" /><g>{plot.map((bucket) => <circle key={`${bucket.latitude}-${bucket.longitude}`} cx={bucket.x} cy={bucket.y} r={bucket.radius} />)}</g></svg><div className="market-layer-table" role="table" aria-label="Map layer text equivalent"><div role="row"><strong>Approximate latitude</strong><strong>Approximate longitude</strong><strong>Listings</strong><strong>Layer value</strong></div>{plot.slice(0, 50).map((bucket) => <div role="row" key={`${bucket.latitude}-${bucket.longitude}`}><span>{bucket.latitude.toFixed(2)}</span><span>{bucket.longitude.toFixed(2)}</span><span>{bucket.count}</span><span>{String(bucket.value ?? "Unavailable")}</span></div>)}</div></> : <section className="release-empty"><Icon name="map-pin" /><h2>No public map buckets</h2><p>Published listings with public approximate coordinates will appear here.</p></section>}</section>
        <aside className="release-panel methodology-panel"><h2>How this report works</h2><dl><div><dt>Range</dt><dd>{report.range.from}–{report.range.to}</dd></div><div><dt>Scope</dt><dd>{[report.scope.locality, report.scope.region].filter(Boolean).join(", ") || "All published locations"}</dd></div><div><dt>Cohort</dt><dd>{report.cohort_size} listings</dd></div><div><dt>Privacy threshold</dt><dd>{report.minimum_cohort} listings</dd></div><div><dt>Coordinates</dt><dd>{mapData?.coordinate_policy.replace("_", " ") ?? "public approximate"}</dd></div></dl><p>Sponsored campaigns do not alter these organic aggregates. Values are descriptive listing statistics, not appraisals or forecasts.</p><small>Generated {new Date(report.generated_at).toLocaleString()}</small></aside></div>
    </> : null}
  </main>;
}
