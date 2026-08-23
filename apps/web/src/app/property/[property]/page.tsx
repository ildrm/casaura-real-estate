import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { SiteHeader } from "@/components/layout/site-header";
import { PropertyDetail } from "@/components/marketplace/property-detail";
import { publicApiQuery } from "@/lib/api-client";
import { publicConfig } from "@/lib/public-config";
import type { PublicListingDetail } from "@/lib/public-marketplace-types";

type PropertyParams = { params: Promise<{ property: string }> };

export async function generateMetadata({ params }: PropertyParams): Promise<Metadata> {
  const id = listingId((await params).property);
  if (!id) return { title: "Property not found", robots: { index: false } };
  try {
    const response = await publicApiQuery<{ data: PublicListingDetail }>(`/api/v1/public/listings/${id}`);
    const listing = response.data;
    return {
      title: listing.title,
      description: listing.description?.slice(0, 160) ?? `Published ${listing.property_type.name} in ${listing.location.label}.`,
      alternates: { canonical: listing.canonical_url },
      robots: { index: true, follow: true },
    };
  } catch {
    return { title: "Property not found", robots: { index: false } };
  }
}

export default async function PropertyPage({ params }: PropertyParams) {
  const property = (await params).property;
  const id = listingId(property);
  if (!id) notFound();
  let listing: PublicListingDetail;
  try {
    listing = (await publicApiQuery<{ data: PublicListingDetail }>(`/api/v1/public/listings/${id}`)).data;
  } catch { notFound(); }
  if (`/property/${property}` !== listing.url) redirect(listing.url);
  const canonicalUrl = new URL(listing.canonical_url, publicConfig.siteUrl).toString();
  const structuredData = {
    "@context": "https://schema.org",
    "@type": "RealEstateListing",
    name: listing.title,
    description: listing.description,
    url: canonicalUrl,
    datePosted: listing.listed_at,
    offers: listing.price ? { "@type": "Offer", price: listing.price.amount_minor / 100, priceCurrency: listing.price.currency, availability: "https://schema.org/InStock" } : undefined,
    address: { "@type": "PostalAddress", addressLocality: listing.location.locality, addressRegion: listing.location.region, addressCountry: listing.location.country_code },
    numberOfBedrooms: listing.bedrooms,
    floorSize: listing.interior_area ? { "@type": "QuantitativeValue", value: listing.interior_area.sqm, unitCode: "MTK" } : undefined,
  };

  return <><SiteHeader /><PropertyDetail listing={listing} /><script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData).replaceAll("<", "\\u003c") }} /></>;
}

function listingId(value: string): string | null {
  return value.match(/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i)?.[1] ?? null;
}
