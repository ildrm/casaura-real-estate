"use client";

import { useEffect, useState } from "react";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { publicApiQuery, type ApiError } from "@/lib/api-client";
import type { PublicListingCard } from "@/lib/public-marketplace-types";

type SponsoredPlacement = { sponsored: true; label: string; disclosure: string; placement: string; listing: PublicListingCard };

export function SponsoredListings({ placement = "search" }: { placement?: "search" | "detail" | "storefront" }) {
  const [items, setItems] = useState<SponsoredPlacement[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  useEffect(() => { const timer = window.setTimeout(async () => { try { setItems((await publicApiQuery<{ data: SponsoredPlacement[] }>(`/api/v1/public/sponsored-listings?placement=${placement}`)).data); } catch (caught) { setError(caught as ApiError); } }, 0); return () => window.clearTimeout(timer); }, [placement]);
  if (error || !items.length) return null;
  return <section className="sponsored-results" aria-labelledby={`sponsored-${placement}`}><header><h2 id={`sponsored-${placement}`}>Paid placements</h2><p>Sponsored homes are selected separately and do not change organic result order.</p></header><div>{items.map((item) => <article key={item.listing.id}><div className="sponsored-disclosure"><strong>{item.label}</strong><span>{item.disclosure}</span></div><PublicPropertyCard listing={item.listing} /></article>)}</div></section>;
}
