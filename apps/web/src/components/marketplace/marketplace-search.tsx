"use client";

import Link from "next/link";
import { useMemo, useState, type CSSProperties } from "react";
import { useRouter } from "next/navigation";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { SponsoredListings } from "@/components/marketplace/sponsored-listings";
import { Icon } from "@/components/ui/icon";
import type { PublicListingCard, PublicSearchResponse } from "@/lib/public-marketplace-types";

export function MarketplaceSearch({ response, params, error }: { response: PublicSearchResponse | null; params: Record<string, string>; error?: string }) {
  const router = useRouter();
  const [mode, setMode] = useState<"list" | "map">("list");
  const [selected, setSelected] = useState<PublicListingCard | null>(response?.data[0] ?? null);
  const coordinates = useMemo(() => response?.data.filter((listing) => listing.location.latitude !== null && listing.location.longitude !== null) ?? [], [response]);
  const extent = useMemo(() => mapExtent(coordinates), [coordinates]);
  const query = params.q ?? "";

  function searchCurrentArea() {
    if (!extent) return;
    const next = new URLSearchParams(params);
    next.set("bounds", `${extent.minLongitude},${extent.minLatitude},${extent.maxLongitude},${extent.maxLatitude}`);
    router.push(`/search?${next}`);
  }

  return <main className="marketplace-search">
    <section className="marketplace-search__toolbar" aria-label="Property search controls">
      <form action="/search" className="marketplace-filter-form">
        <label className="marketplace-query"><Icon name="search" /><span className="sr-only">Search location, address, or agency</span><input name="q" defaultValue={query} placeholder="City, neighborhood, address, or agency" /></label>
        <label><span className="sr-only">Listing intent</span><select name="intent" defaultValue={params.intent ?? "buy"}><option value="buy">Buy</option><option value="rent">Rent</option></select></label>
        <label><span className="sr-only">Minimum price</span><input name="min_price" inputMode="numeric" defaultValue={params.min_price} placeholder="Min price" /></label>
        <label><span className="sr-only">Maximum price</span><input name="max_price" inputMode="numeric" defaultValue={params.max_price} placeholder="Max price" /></label>
        <label><span className="sr-only">Minimum bedrooms</span><select name="beds" defaultValue={params.beds ?? ""}><option value="">Beds</option><option value="1">1+</option><option value="2">2+</option><option value="3">3+</option><option value="4">4+</option></select></label>
        <label><span className="sr-only">Property type</span><select name="type" defaultValue={params.type ?? "all"}><option value="all">Property type</option><option value="house">House</option><option value="apartment">Apartment</option><option value="townhouse">Townhouse</option><option value="land">Land</option></select></label>
        <details className="marketplace-more"><summary><Icon name="settings" /> More filters</summary><div><label>Bathrooms<select name="baths" defaultValue={params.baths ?? ""}><option value="">Any</option><option value="1">1+</option><option value="2">2+</option><option value="3">3+</option></select></label><label>Amenities<input name="amenities" defaultValue={params.amenities} placeholder="garden, garage" /></label><label className="marketplace-check"><input type="checkbox" name="verified" value="1" defaultChecked={params.verified === "1"} /> Verified agencies only</label></div></details>
        <button className="button button--primary" type="submit">Search</button>
      </form>
    </section>

    <div className="marketplace-mode" aria-label="Search display"><button type="button" aria-pressed={mode === "list"} onClick={() => setMode("list")}>List</button><button type="button" aria-pressed={mode === "map"} onClick={() => setMode("map")}>Map</button></div>
    <div className={`marketplace-split marketplace-split--${mode}`}>
      <section className="marketplace-results" aria-labelledby="public-results-title">
        <header><div><h1 id="public-results-title">{query ? `Homes near “${query}”` : "Homes for you"}</h1><p>{response?.meta.count ?? 0} published {(response?.meta.count ?? 0) === 1 ? "result" : "results"}</p></div><label>Sort<select name="sort" defaultValue={params.sort ?? "newest"} onChange={(event) => { const next = new URLSearchParams(params); next.set("sort", event.target.value); router.push(`/search?${next}`); }}><option value="newest">Newest</option><option value="price_asc">Price: low to high</option><option value="price_desc">Price: high to low</option></select></label></header>
        <SponsoredListings placement="search" />
        {error ? <div className="marketplace-state" role="alert"><Icon name="shield" /><h2>Search is temporarily unavailable</h2><p>{error}</p><button className="button button--outline" type="button" onClick={() => router.refresh()}>Try again</button></div> : null}
        {!error && response?.data.length === 0 ? <div className="marketplace-state"><Icon name="search" /><h2>No matching homes yet</h2><p>Try a wider location or fewer filters. We never pad results with unrelated inventory.</p><Link className="button button--outline" href="/search">Clear filters</Link></div> : null}
        {!error && response?.data.length ? <div className="marketplace-card-grid">{response.data.map((listing) => <PublicPropertyCard key={listing.id} listing={listing} selected={selected?.id === listing.id} onSelect={setSelected} />)}</div> : null}
        {response?.meta.next_cursor ? <Link className="button button--outline marketplace-load" href={{ pathname: "/search", query: { ...params, cursor: response.meta.next_cursor } }}>Load more results</Link> : null}
      </section>

      <section className="marketplace-map" aria-label="Approximate property map">
        <div className="marketplace-map__canvas"><span className="map-road map-road--one" /><span className="map-road map-road--two" /><span className="map-river" /><span className="map-park">Park</span>
          <button className="button marketplace-search-area" type="button" disabled={!extent} onClick={searchCurrentArea}><Icon name="search" /> Search this area</button>
          {coordinates.map((listing, index) => <button key={listing.id} type="button" className={`map-marker${selected?.id === listing.id ? " is-selected" : ""}`} style={markerPosition(listing, extent, index)} aria-label={`Select ${listing.title} on map`} onClick={() => setSelected(listing)}>{markerPrice(listing)}</button>)}
          {selected ? <div className="map-selection"><strong>{selected.title}</strong><span>{selected.location.policy === "approximate" ? "Approximate area" : selected.location.label}</span><Link href={selected.url}>View property <Icon name="arrow-right" /></Link></div> : null}
          <p className="map-methodology"><Icon name="shield" /> Markers use each listing’s approved public location. Approximate listings never reveal the private point.</p>
        </div>
      </section>
    </div>
  </main>;
}

type Extent = { minLatitude: number; maxLatitude: number; minLongitude: number; maxLongitude: number };

function mapExtent(listings: PublicListingCard[]): Extent | null {
  if (!listings.length) return null;
  const latitudes = listings.map((listing) => listing.location.latitude as number);
  const longitudes = listings.map((listing) => listing.location.longitude as number);
  const latitudePadding = Math.max((Math.max(...latitudes) - Math.min(...latitudes)) * 0.12, 0.02);
  const longitudePadding = Math.max((Math.max(...longitudes) - Math.min(...longitudes)) * 0.12, 0.02);
  return { minLatitude: Math.min(...latitudes) - latitudePadding, maxLatitude: Math.max(...latitudes) + latitudePadding, minLongitude: Math.min(...longitudes) - longitudePadding, maxLongitude: Math.max(...longitudes) + longitudePadding };
}

function markerPosition(listing: PublicListingCard, extent: Extent | null, index: number): CSSProperties {
  if (!extent || listing.location.latitude === null || listing.location.longitude === null) return { left: `${25 + index * 12}%`, top: `${30 + index * 9}%` };
  const x = ((listing.location.longitude - extent.minLongitude) / Math.max(extent.maxLongitude - extent.minLongitude, 0.0001)) * 78 + 11;
  const y = (1 - (listing.location.latitude - extent.minLatitude) / Math.max(extent.maxLatitude - extent.minLatitude, 0.0001)) * 72 + 14;
  return { left: `${x}%`, top: `${y}%` };
}

function markerPrice(listing: PublicListingCard): string {
  if (!listing.price) return "View";
  const amount = listing.price.amount_minor / 100;
  return amount >= 1_000_000 ? `$${(amount / 1_000_000).toFixed(2)}M` : `$${Math.round(amount / 1000)}K`;
}
