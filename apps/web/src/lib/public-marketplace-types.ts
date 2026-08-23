export type PublicMedia = {
  id: string;
  alt_text: string | null;
  width: number;
  height: number;
  thumbnail_url: string | null;
  display_url: string | null;
};

export type PublicListingCard = {
  id: string;
  slug: string;
  url: string;
  title: string;
  intent: "sale" | "rent";
  price: { amount_minor: number; currency: string } | null;
  property_type: { slug: string; name: string };
  bedrooms: number | null;
  bathrooms: number | null;
  interior_area: { sqm: number; sq_ft: number } | null;
  location: {
    label: string;
    locality: string | null;
    region: string | null;
    country_code: string | null;
    policy: "exact" | "approximate" | "hidden";
    latitude: number | null;
    longitude: number | null;
  };
  agency: { id: string; name: string; slug: string; verified: boolean };
  primary_media: PublicMedia | null;
  media_count: number;
  listed_at: string | null;
};

export type PublicSearchResponse = {
  data: PublicListingCard[];
  meta: { count: number; next_cursor: string | null; applied_filters: Record<string, unknown> };
};

export type PublicListingDetail = PublicListingCard & {
  canonical_url: string;
  reference: string;
  description: string | null;
  features: Record<string, boolean | number | string>;
  amenities: string[];
  media: PublicMedia[];
  price_history: Array<{ amount_minor: number; currency: string; effective_at: string }>;
  agency: PublicListingCard["agency"] & { short_description: string | null; contact_handoff_available: boolean };
  engagement: { favorite: boolean; reaction: "like" | "dislike" | null };
  similar_listings: PublicListingCard[];
};

export type EngagementResponse = {
  data: {
    favorites: PublicListingCard[];
    likes: PublicListingCard[];
    dislikes: PublicListingCard[];
  };
};
