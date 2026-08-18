"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiQuery, type ApiError } from "@/lib/api-client";
import type { ListingProjection } from "@/lib/listing-types";

type ListingPage = { data: ListingProjection[]; meta?: { next_cursor?: string | null } };

const statusLabels: Record<ListingProjection["status"], string> = {
  draft: "Draft",
  changes_requested: "Needs details",
  in_review: "In review",
  published: "Published",
  withdrawn: "Withdrawn",
};

function money(listing: ListingProjection): string {
  if (!listing.price) return "Not set";
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: listing.price.currency,
    maximumFractionDigits: 0,
  }).format(listing.price.amount_minor / 100);
}

function listingAddress(listing: ListingProjection): string {
  const address = listing.property.address;
  if (!address) return "Location not set";
  return [address.line_1, address.locality, address.region].filter(Boolean).join(", ");
}

export function ListingsWorkspace() {
  const [listings, setListings] = useState<ListingProjection[]>([]);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("all");
  const [propertyType, setPropertyType] = useState("all");
  const [sort, setSort] = useState("updated");
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load(cursor?: string) {
    await Promise.resolve();
    const agencyId = activeAgencyId();
    if (!agencyId) {
      setError("Select an agency or sign in again to view inventory.");
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ limit: "50" });
      if (cursor) params.set("cursor", cursor);
      const response = await apiQuery<ListingPage>(`/api/v1/listings?${params}`, agencyId);
      setListings((current) => cursor ? [...current, ...response.data] : response.data);
      setNextCursor(response.meta?.next_cursor ?? null);
    } catch (caught) {
      setError((caught as ApiError).message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  const counts = useMemo(() => ({
    active: listings.filter((listing) => listing.status === "published").length,
    drafts: listings.filter((listing) => listing.status === "draft").length,
    review: listings.filter((listing) => listing.status === "in_review").length,
    attention: listings.filter((listing) => listing.status === "changes_requested" || listing.quality.score < 80).length,
  }), [listings]);

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    const result = listings.filter((listing) => {
      const statusMatches = status === "all"
        || (status === "attention" ? listing.status === "changes_requested" || listing.quality.score < 80 : listing.status === status);
      const typeMatches = propertyType === "all" || listing.property.property_type.slug === propertyType;
      const queryMatches = !term || `${listing.reference} ${listing.title ?? ""} ${listingAddress(listing)}`.toLowerCase().includes(term);
      return statusMatches && typeMatches && queryMatches;
    });
    return result.toSorted((a, b) => {
      if (sort === "quality") return b.quality.score - a.quality.score;
      if (sort === "price") return (b.price?.amount_minor ?? 0) - (a.price?.amount_minor ?? 0);
      return new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime();
    });
  }, [listings, propertyType, query, sort, status]);

  const propertyTypes = useMemo(() => Array.from(new Map(
    listings.map((listing) => [listing.property.property_type.slug, listing.property.property_type.name]),
  )), [listings]);

  const readiness = useMemo(() => {
    const incomplete = listings.flatMap((listing) => listing.quality.checklist.filter((check) => !check.complete));
    return [
      { label: "Missing photos", count: incomplete.filter((check) => check.key === "media").length, icon: "building" as const },
      { label: "Missing description", count: incomplete.filter((check) => check.key === "description").length, icon: "message" as const },
      { label: "Missing property details", count: incomplete.filter((check) => ["basics", "features", "location"].includes(check.key)).length, icon: "sparkle" as const },
    ];
  }, [listings]);

  const tabs = [
    ["all", "All", listings.length],
    ["published", "Published", counts.active],
    ["draft", "Drafts", counts.drafts],
    ["in_review", "In review", counts.review],
    ["attention", "Needs attention", counts.attention],
  ] as const;

  return (
    <main className="workspace-canvas properties-canvas">
      <section className="workspace-title properties-title">
        <div><h1>Properties</h1><p>Create, review, and publish your agency’s inventory.</p></div>
        <div className="workspace-title__actions"><Link className="button button--primary" href="/agency/properties/new"><Icon name="plus" /> Add property</Link></div>
      </section>

      <section className="metric-strip property-metrics" aria-label="Inventory summary">
        <div><span className="metric-icon"><Icon name="home" /></span><strong>{counts.active}</strong><small>Active</small></div>
        <div><span className="metric-icon"><Icon name="building" /></span><strong>{counts.drafts}</strong><small>Drafts</small></div>
        <div><span className="metric-icon"><Icon name="calendar" /></span><strong>{counts.review}</strong><small>In review</small></div>
        <div><span className="metric-icon"><Icon name="shield" /></span><strong>{counts.attention}</strong><small>Need attention</small></div>
      </section>

      <div className="properties-layout">
        <section className="inventory-panel" aria-label="Property inventory">
          <div className="inventory-filters">
            <label className="inventory-search"><Icon name="search" /><span className="sr-only">Search address or reference</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search address or reference" /></label>
            <label><span className="sr-only">Status</span><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="all">Status</option><option value="published">Published</option><option value="draft">Draft</option><option value="in_review">In review</option><option value="attention">Needs attention</option></select></label>
            <label><span className="sr-only">Property type</span><select value={propertyType} onChange={(event) => setPropertyType(event.target.value)}><option value="all">Property type</option>{propertyTypes.map(([slug, name]) => <option value={slug} key={slug}>{name}</option>)}</select></label>
            <label><span className="sr-only">Sort properties</span><select value={sort} onChange={(event) => setSort(event.target.value)}><option value="updated">Sort: Updated</option><option value="quality">Sort: Quality</option><option value="price">Sort: Price</option></select></label>
          </div>
          <div className="inventory-tabs" role="tablist" aria-label="Listing status">
            {tabs.map(([value, label, count]) => <button type="button" role="tab" aria-selected={status === value} className={status === value ? "is-active" : undefined} onClick={() => setStatus(value)} key={value}>{label} <span>{count}</span></button>)}
          </div>

          {loading && listings.length === 0 ? <div className="inventory-state" role="status">Loading your inventory…</div> : null}
          {error ? <div className="inventory-state inventory-state--error" role="alert"><strong>Inventory unavailable</strong><span>{error}</span><button className="button button--outline" type="button" onClick={() => void load()}>Try again</button></div> : null}
          {!loading && !error && filtered.length === 0 ? <div className="inventory-state"><span className="metric-icon"><Icon name="building" /></span><strong>{listings.length ? "No properties match these filters" : "Your property inventory starts here"}</strong><span>{listings.length ? "Clear or change a filter to see more results." : "Create a secure draft, add its details, and submit it for review."}</span>{listings.length ? <button type="button" className="button button--outline" onClick={() => { setQuery(""); setStatus("all"); setPropertyType("all"); }}>Clear filters</button> : <Link className="button button--primary" href="/agency/properties/new">Add your first property</Link>}</div> : null}

          {filtered.length ? <div className="inventory-table" role="table" aria-label="Properties">
            <div className="inventory-row inventory-row--head" role="row"><span>Property</span><span>Reference</span><span>Price</span><span>Status</span><span>Quality</span><span>Updated</span><span>Actions</span></div>
            {filtered.map((listing) => <div className="inventory-row" role="row" key={listing.id}>
              <span className="inventory-property"><i aria-hidden="true"><Icon name="home" /></i><b><Link href={`/agency/properties/${listing.id}/edit`}>{listing.title || listingAddress(listing) || "Untitled property"}</Link><small>{listingAddress(listing)}</small></b></span>
              <span>{listing.reference}</span><span>{money(listing)}</span>
              <span><em className={`listing-status listing-status--${listing.status}`}>{statusLabels[listing.status]}</em></span>
              <span className="quality-cell"><b>{listing.quality.score}%</b><i><span style={{ width: `${listing.quality.score}%` }} /></i></span>
              <span>{new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric" }).format(new Date(listing.updated_at))}</span>
              <span><Link className="inventory-edit" href={`/agency/properties/${listing.id}/edit`} aria-label={`Edit ${listing.title || listing.reference}`}>Edit</Link></span>
            </div>)}
          </div> : null}
          {filtered.length ? <footer className="inventory-footer"><span>Showing {filtered.length} loaded {filtered.length === 1 ? "property" : "properties"}</span>{nextCursor ? <button className="button button--outline" type="button" disabled={loading} onClick={() => void load(nextCursor)}>{loading ? "Loading…" : "Load more"}</button> : <span>All loaded</span>}</footer> : null}
        </section>

        <aside className="readiness-panel" aria-labelledby="readiness-title">
          <h2 id="readiness-title">Publishing readiness</h2><p>Keep your properties complete to publish with confidence.</p>
          <ul>{readiness.map((item) => <li key={item.label}><span><Icon name={item.icon} /></span><b>{item.label}<small>{item.count} {item.count === 1 ? "property" : "properties"}</small></b></li>)}</ul>
          <Link href="/agency/properties?status=attention">View details <Icon name="chevron-right" /></Link>
        </aside>
      </div>
    </main>
  );
}
