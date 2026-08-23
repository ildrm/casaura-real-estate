"use client";

import Image from "next/image";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Icon } from "@/components/ui/icon";
import { publicAssetUrl } from "@/lib/api-client";
import { formatArea, formatMoney } from "@/lib/localization";
import type { PublicListingCard } from "@/lib/public-marketplace-types";

export function formatListingPrice(listing: PublicListingCard): string {
  if (!listing.price) return "Price on request";
  const value = formatMoney(listing.price.amount_minor, listing.price.currency);
  return listing.intent === "rent" ? `${value} / mo` : value;
}

export function PublicPropertyCard({ listing, selected = false, onSelect, hideFavorite = false }: { listing: PublicListingCard; selected?: boolean; onSelect?: (listing: PublicListingCard) => void; hideFavorite?: boolean }) {
  const router = useRouter();
  const image = publicAssetUrl(listing.primary_media?.thumbnail_url);
  return <article className={`public-property-card${selected ? " is-selected" : ""}`} onMouseEnter={() => onSelect?.(listing)}>
    <div className="public-property-card__media">
      {image ? <Image src={image} alt={listing.primary_media?.alt_text ?? `Photo of ${listing.title}`} fill unoptimized sizes="(max-width: 720px) 100vw, 32vw" /> : <span><Icon name="home" /> Photo pending</span>}
      {!hideFavorite ? <button className="icon-button public-property-card__favorite" type="button" aria-label={`Sign in to favorite ${listing.title}`} onClick={() => router.push(`/sign-in?next=${encodeURIComponent(listing.url)}`)}><Icon name="heart" /></button> : null}
      {isNew(listing.listed_at) ? <small className="listing-badge">New</small> : null}
    </div>
    <Link className="public-property-card__body" href={listing.url}>
      <div className="public-property-card__price"><strong>{formatListingPrice(listing)}</strong><span>For {listing.intent === "sale" ? "sale" : "rent"}</span></div>
      <h2>{listing.title}</h2>
      <div className="public-property-card__facts" aria-label="Property facts"><span>{listing.bedrooms ?? "—"} bd</span><span>{listing.bathrooms ?? "—"} ba</span><span>{listing.interior_area ? formatArea(listing.interior_area) : "Area not set"}</span></div>
      <p>{listing.location.policy === "approximate" ? "Approx. " : ""}{listing.location.label}</p>
      <footer><span><Icon name="shield" /> {listing.agency.name}</span>{listing.agency.verified ? <b>Verified</b> : null}</footer>
    </Link>
  </article>;
}

function isNew(listedAt: string | null): boolean {
  return Boolean(listedAt && Date.now() - new Date(listedAt).getTime() < 7 * 24 * 60 * 60 * 1000);
}
