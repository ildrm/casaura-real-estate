import type { MetadataRoute } from "next";
import { publicApiQuery } from "@/lib/api-client";
import { publicConfig } from "@/lib/public-config";

type Discovery = {
  listings: Array<{ id: string; slug: string; updated_at: string | null }>;
  agencies: Array<{ slug: string; updated_at: string | null }>;
};

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const stable: MetadataRoute.Sitemap = [
    { url: publicConfig.siteUrl, changeFrequency: "daily", priority: 1 },
    { url: `${publicConfig.siteUrl}/register/agency`, changeFrequency: "monthly", priority: 0.6 },
    { url: `${publicConfig.siteUrl}/terms`, changeFrequency: "monthly", priority: 0.3 },
    { url: `${publicConfig.siteUrl}/privacy`, changeFrequency: "monthly", priority: 0.3 },
  ];

  try {
    const { data } = await publicApiQuery<{ data: Discovery }>("/api/v1/public/discovery");
    return [
      ...stable,
      ...data.listings.map((listing) => ({
        url: `${publicConfig.siteUrl}/property/${listing.slug}-${listing.id}`,
        lastModified: listing.updated_at ? new Date(listing.updated_at) : undefined,
        changeFrequency: "daily" as const,
        priority: 0.8,
      })),
      ...data.agencies.map((agency) => ({
        url: `${publicConfig.siteUrl}/professionals/${agency.slug}`,
        lastModified: agency.updated_at ? new Date(agency.updated_at) : undefined,
        changeFrequency: "weekly" as const,
        priority: 0.7,
      })),
    ];
  } catch {
    return stable;
  }
}
