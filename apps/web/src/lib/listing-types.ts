export type QualityCheck = { key: string; complete: boolean; message: string };

export type ListingProjection = {
  id: string;
  property_id: string;
  agency_id: string;
  reference: string;
  intent: "sale" | "rent";
  status: "draft" | "changes_requested" | "in_review" | "published" | "withdrawn";
  version: number;
  title: string | null;
  description: string | null;
  price: { amount_minor: number; currency: string } | null;
  property: {
    property_type: { slug: string; name: string };
    bedrooms: number | null;
    bathrooms: number | null;
    interior_area: { sqm: number; sq_ft: number } | null;
    address: {
      line_1?: string | null;
      line_2?: string | null;
      locality?: string | null;
      region?: string | null;
      postal_code?: string | null;
      country_code?: string | null;
    } | null;
    features: Record<string, boolean | number | string | null>;
    amenities: string[];
  };
  quality: { score: number; ready_for_review: boolean; checklist: QualityCheck[] };
  primary_media: {
    id: string;
    original_name: string;
    mime_type: string;
    byte_size: number;
    width: number;
    height: number;
    position: number;
    alt_text: string | null;
  } | null;
  media_count: number;
  submitted_at: string | null;
  published_at: string | null;
  updated_at: string;
};

export type PropertyCatalog = {
  property_types: Array<{ slug: string; name: string; category: string }>;
  amenities: Array<{ slug: string; name: string; group: string }>;
  feature_definitions: Array<{
    slug: string;
    name: string;
    value_type: "boolean" | "integer" | "decimal" | "string" | "enum";
    unit: string | null;
    validation: Record<string, unknown> | null;
  }>;
};

export type MediaProjection = {
  id: string;
  original_name: string;
  mime_type: string;
  byte_size: number;
  width: number;
  height: number;
  position: number;
  alt_text: string | null;
  derivatives?: Array<{ kind: string; width: number; height: number; byte_size: number }>;
};
