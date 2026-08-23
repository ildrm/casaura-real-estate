import type { Metadata } from "next";
import { SiteHeader } from "@/components/layout/site-header";
import { MarketplaceSearch } from "@/components/marketplace/marketplace-search";
import { publicApiQuery, type ApiError } from "@/lib/api-client";
import type { PublicSearchResponse } from "@/lib/public-marketplace-types";

export const metadata: Metadata = {
  title: "Search homes",
  description: "Search published homes by place, price, property facts, and map area.",
  robots: { index: false, follow: true },
};

type SearchParams = Record<string, string | string[] | undefined>;

export default async function SearchPage({ searchParams }: { searchParams: Promise<SearchParams> }) {
  const incoming = await searchParams;
  const params = Object.fromEntries(Object.entries(incoming).flatMap(([key, value]) => typeof value === "string" ? [[key, value]] : []));
  const api = new URLSearchParams();
  if (params.q) api.set("q", params.q);
  if (params.intent) api.set("intent", params.intent === "rent" ? "rent" : "sale");
  if (params.type && params.type !== "all") api.set("property_type", params.type);
  if (params.min_price && Number.isFinite(Number(params.min_price))) api.set("min_price", String(Math.round(Number(params.min_price) * 100)));
  if (params.max_price && Number.isFinite(Number(params.max_price))) api.set("max_price", String(Math.round(Number(params.max_price) * 100)));
  if (params.beds) api.set("min_bedrooms", params.beds);
  if (params.baths) api.set("min_bathrooms", params.baths);
  if (params.amenities) api.set("amenities", params.amenities);
  if (params.verified === "1") api.set("verified_agency", "1");
  if (params.bounds) api.set("bounds", params.bounds);
  if (params.radius) api.set("radius", params.radius);
  if (params.sort) api.set("sort", params.sort);
  if (params.cursor) api.set("cursor", params.cursor);
  let response: PublicSearchResponse | null = null;
  let error: string | undefined;
  try {
    response = await publicApiQuery<PublicSearchResponse>(`/api/v1/public/search?${api}`);
  } catch (caught) {
    error = (caught as ApiError).message;
  }

  return <><SiteHeader /><MarketplaceSearch response={response} params={params} error={error} /></>;
}
