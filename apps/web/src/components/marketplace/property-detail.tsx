"use client";

import Image from "next/image";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { PublicPropertyCard, formatListingPrice } from "@/components/marketplace/public-property-card";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, publicAssetUrl, type ApiError } from "@/lib/api-client";
import type { EngagementResponse, PublicListingDetail } from "@/lib/public-marketplace-types";

export function PropertyDetail({ listing }: { listing: PublicListingDetail }) {
  const router = useRouter();
  const [favorite, setFavorite] = useState(listing.engagement.favorite);
  const [reaction, setReaction] = useState<"like" | "dislike" | null>(listing.engagement.reaction);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const monthly = useMemo(() => monthlyEstimate(listing.price?.amount_minor ?? null), [listing.price?.amount_minor]);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try {
        const response = await apiQuery<EngagementResponse>("/api/v1/account/engagements");
        setFavorite(response.data.favorites.some((item) => item.id === listing.id));
        setReaction(response.data.likes.some((item) => item.id === listing.id) ? "like" : response.data.dislikes.some((item) => item.id === listing.id) ? "dislike" : null);
      } catch { /* A public visitor simply keeps the unselected state. */ }
    }, 0);
    return () => window.clearTimeout(timer);
  }, [listing.id]);

  async function toggleFavorite() {
    setBusy(true); setNotice(null);
    try {
      const response = await apiMutation<{ data: { favorite: boolean } }>(`/api/v1/account/favorites/${listing.id}`, {}, { method: favorite ? "DELETE" : "PUT" });
      setFavorite(response.data.favorite);
    } catch (caught) { handleEngagementError(caught as ApiError); } finally { setBusy(false); }
  }

  async function setListingReaction(next: "like" | "dislike") {
    setBusy(true); setNotice(null);
    try {
      if (reaction === next) {
        await apiMutation(`/api/v1/account/reactions/${listing.id}`, {}, { method: "DELETE" });
        setReaction(null);
      } else {
        const response = await apiMutation<{ data: { reaction: "like" | "dislike" } }>(`/api/v1/account/reactions/${listing.id}`, { reaction: next }, { method: "PUT" });
        setReaction(response.data.reaction);
      }
    } catch (caught) { handleEngagementError(caught as ApiError); } finally { setBusy(false); }
  }

  function handleEngagementError(error: ApiError) {
    if (error.code === "UNAUTHENTICATED") {
      router.push(`/sign-in?next=${encodeURIComponent(listing.url)}`);
      return;
    }
    setNotice(error.message);
  }

  const primary = publicAssetUrl(listing.media[0]?.display_url);
  return <main className="property-detail shell">
    <nav className="property-breadcrumb" aria-label="Breadcrumb"><Link href="/">Home</Link><span>/</span><Link href={`/search?intent=${listing.intent === "sale" ? "buy" : "rent"}`}>{listing.intent === "sale" ? "Buy" : "Rent"}</Link><span>/</span><span>{listing.location.locality ?? "Property"}</span></nav>

    <section className="property-gallery" aria-label={`Media gallery with ${listing.media_count} photos`}>
      <div className="property-gallery__primary">{primary ? <Image src={primary} alt={listing.media[0]?.alt_text ?? listing.title} fill unoptimized priority sizes="(max-width: 720px) 100vw, 65vw" /> : <span><Icon name="home" /> Photo pending</span>}</div>
      <div className="property-gallery__rail">{listing.media.slice(1, 5).map((media) => { const image = publicAssetUrl(media.thumbnail_url); return <div key={media.id}>{image ? <Image src={image} alt={media.alt_text ?? `${listing.title} view`} fill unoptimized sizes="25vw" /> : null}</div>; })}</div>
      <span className="property-gallery__count"><Icon name="building" /> {listing.media_count} photos</span>
    </section>

    <div className="property-detail__layout">
      <div className="property-detail__main">
        <header className="property-detail__heading"><p>{listing.location.policy === "approximate" ? "Approx. " : ""}{listing.location.label}</p><h1>{listing.title}</h1><div className="property-price-row"><strong>{formatListingPrice(listing)}</strong><span>For {listing.intent === "sale" ? "sale" : "rent"}</span></div></header>
        <dl className="property-fact-strip"><div><dt>Beds</dt><dd>{listing.bedrooms ?? "—"}</dd></div><div><dt>Baths</dt><dd>{listing.bathrooms ?? "—"}</dd></div><div><dt>Interior</dt><dd>{listing.interior_area ? `${listing.interior_area.sq_ft.toLocaleString()} sq ft` : "—"}</dd></div><div><dt>Reference</dt><dd>{listing.reference}</dd></div><div><dt>Status</dt><dd className="verified"><Icon name="shield" /> Published</dd></div></dl>
        <div className="property-actions" aria-label="Property actions"><button type="button" aria-label="Favorite" aria-pressed={favorite} disabled={busy} onClick={() => void toggleFavorite()}><Icon name="heart" /> {favorite ? "Favorited" : "Favorite"}</button><button type="button" aria-pressed={reaction === "like"} disabled={busy} onClick={() => void setListingReaction("like")}>Like</button><button type="button" aria-pressed={reaction === "dislike"} disabled={busy} onClick={() => void setListingReaction("dislike")}>Dislike</button><button type="button" onClick={() => void navigator.clipboard?.writeText(window.location.href)}>Share</button></div>
        {notice ? <p className="property-notice" role="alert">{notice}</p> : null}

        <section className="property-copy"><h2>About this property</h2><p>{listing.description ?? "The agency has not added a public description."}</p></section>
        <section className="property-detail-grid"><div className="property-panel"><h2>Key features</h2><ul>{Object.entries(listing.features).map(([key, value]) => <li key={key}><Icon name="check" /><span>{humanize(key)}<b>{String(value)}</b></span></li>)}{listing.amenities.map((amenity) => <li key={amenity}><Icon name="check" /><span>{humanize(amenity)}</span></li>)}</ul></div><div className="property-panel"><h2>Price history</h2><ol>{listing.price_history.map((price) => <li key={`${price.effective_at}-${price.amount_minor}`}><time dateTime={price.effective_at}>{new Intl.DateTimeFormat("en-US", { month: "short", year: "numeric" }).format(new Date(price.effective_at))}</time><b>{new Intl.NumberFormat("en-US", { style: "currency", currency: price.currency, maximumFractionDigits: 0 }).format(price.amount_minor / 100)}</b></li>)}</ol><small>Source: this listing’s immutable agency price history.</small></div></section>
        <section className="property-location property-panel"><h2>Approximate location</h2><div><span className="approximate-radius"><Icon name="map-pin" /></span><i /><i /><i /></div><p>{listing.location.policy === "approximate" ? "The marker is intentionally displaced to protect the exact address." : listing.location.policy === "hidden" ? "The agency has withheld a public marker." : "The agency approved this location for public display."}</p></section>
      </div>

      <aside className="property-detail__aside"><section className="agency-contact-card"><span className="agency-monogram">{listing.agency.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("")}</span><div><h2>{listing.agency.name}</h2>{listing.agency.verified ? <p className="verified"><Icon name="shield" /> Verified agency</p> : <p>Agency profile</p>}</div><p>{listing.agency.short_description ?? "Listing information is supplied by the publishing agency."}</p><button className="button button--primary" type="button" disabled>Ask about this property</button><button className="button button--outline" type="button" disabled>Request a viewing</button><small><Icon name="shield" /> Secure enquiry and viewing workflows arrive in Milestone 4; this page does not simulate a submission.</small></section>{monthly ? <section className="monthly-estimate"><h2>Estimated monthly principal & interest</h2><strong>{monthly}</strong><p>Illustrative estimate using 20% down, 30 years, and 6.5%. Excludes tax, insurance, fees, and eligibility. Not a lending offer or guarantee.</p></section> : null}</aside>
    </div>

    {listing.similar_listings.length ? <section className="similar-properties"><header><h2>Similar properties</h2><Link href={`/search?type=${listing.property_type.slug}`}>View all</Link></header><div>{listing.similar_listings.map((similar) => <PublicPropertyCard listing={similar} key={similar.id} hideFavorite />)}</div></section> : null}
  </main>;
}

function humanize(value: string): string { return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }

function monthlyEstimate(amountMinor: number | null): string | null {
  if (!amountMinor) return null;
  const principal = (amountMinor / 100) * 0.8;
  const monthlyRate = 0.065 / 12;
  const payments = 360;
  const payment = principal * (monthlyRate * (1 + monthlyRate) ** payments) / ((1 + monthlyRate) ** payments - 1);
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 }).format(payment);
}
