"use client";

import { useEffect, useState } from "react";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { Icon } from "@/components/ui/icon";
import { publicApiQuery, type ApiError } from "@/lib/api-client";
import type { PublicListingCard } from "@/lib/public-marketplace-types";

type Recommendation = { listing: PublicListingCard; score: number; reasons: string[] };

export function OrganicRecommendations({ listingId }: { listingId: string }) {
  const [items, setItems] = useState<Recommendation[] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  useEffect(() => { const timer = window.setTimeout(async () => { try { setItems((await publicApiQuery<{ data: Recommendation[] }>(`/api/v1/public/listings/${listingId}/recommendations`)).data); } catch (caught) { setError(caught as ApiError); } }, 0); return () => window.clearTimeout(timer); }, [listingId]);
  if (error?.code === "FEATURE_DISABLED") return <section className="recommendation-disabled"><Icon name="shield" /><span><strong>Recommendations are unavailable</strong><small>{error.message}</small></span></section>;
  if (error) return null;
  if (!items) return <section className="recommendation-loading" role="status"><span className="inline-spinner" /> Loading explainable recommendations…</section>;
  if (!items.length) return null;
  return <section className="organic-recommendations"><header><div><h2>Why these homes are similar</h2><p>Ranked from current organic listing facts; sponsorship does not affect this order.</p></div></header><div>{items.slice(0, 4).map((item) => <article key={item.listing.id}><PublicPropertyCard listing={item.listing} hideFavorite /><p><Icon name="check" /> {item.reasons.map(reasonLabel).join(" · ")}</p></article>)}</div></section>;
}

function reasonLabel(reason: string): string {
  return ({ same_property_type: "Same property type", same_locality: "Same locality", same_bedroom_count: "Same bedroom count", similar_price: "Similar asking price" } as Record<string, string>)[reason] ?? reason.replaceAll("_", " ");
}
