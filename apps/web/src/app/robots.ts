import type { MetadataRoute } from "next";
import { publicConfig } from "@/lib/public-config";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/agency/", "/account/", "/search?"],
    },
    sitemap: `${publicConfig.siteUrl}/sitemap.xml`,
  };
}
