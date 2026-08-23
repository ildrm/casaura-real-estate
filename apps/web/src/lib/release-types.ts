import type { PublicListingCard } from "@/lib/public-marketplace-types";

export type ProviderConnection = {
  id: string;
  provider: "reso";
  name: string;
  base_url: string;
  token_url: string;
  secret_reference: string;
  resources: string[];
  rights: { display?: boolean; photos?: boolean; attribution?: string };
  data_dictionary_version: string;
  enabled: boolean;
  version: number;
  last_sync_status: string | null;
  last_synced_at: string | null;
};

export type FieldMapping = {
  id: string;
  resource: string;
  version: number;
  fields: Record<string, string>;
  activated_at: string;
};

export type SyncJob = {
  id: string;
  mode: "full" | "incremental";
  status: string;
  records_fetched: number;
  records_imported: number;
  records_skipped: number;
  records_failed: number;
  started_at: string | null;
  completed_at: string | null;
};

export type ImportError = {
  id: string;
  field: string | null;
  code: string;
  retryable: boolean;
  resolved_at: string | null;
  created_at: string;
};

export type DuplicateCandidate = {
  id: string;
  left_property_id: string | null;
  right_property_id: string | null;
  data_source_record_id?: string | null;
  score: number;
  reasons: string[];
  status: "pending" | "rejected" | "linked" | "merged";
  version: number;
  decided_at: string | null;
};

export type ConsumerCollection = {
  id: string;
  name: string;
  role: "owner" | "editor" | "viewer";
  version: number;
  items: Array<{
    listing_id: string;
    position: number;
    unavailable: boolean;
    listing: PublicListingCard | null;
  }>;
  members: Array<{ user_id: string; role: "editor" | "viewer" }>;
  created_at: string;
  updated_at: string;
};

export type ComparisonItem = PublicListingCard & {
  description: string | null;
  amenities: string[];
  features: Record<string, unknown>;
  freshness: { listed_at: string | null; projected_at: string; projection_version: number };
};

export type AiCitation = {
  listing_id: string;
  url: string;
  fields: string[];
  projection_version: number;
};

export type AiMatch = {
  listing_id: string;
  title: string;
  price_amount_minor: number | null;
  currency: string | null;
  property_type: string | null;
  property_type_slug: string | null;
  bedrooms: number | null;
  bathrooms: number | null;
  interior_area_sqm: number | null;
  locality: string | null;
  region: string | null;
  amenities: string[];
  features: Record<string, unknown>;
  listed_at: string | null;
};

export type AiSearchResult = {
  id: string;
  message: string;
  parsed_filters: Record<string, string | number>;
  filters_applied: false;
  assumptions: string[];
  citations: AiCitation[];
  matches: AiMatch[];
  safety: { status: string };
  provider: { adapter: string; model: string };
};

export type BillingPlan = {
  id: string;
  slug: string;
  name: string;
  price: { amount_minor: number; currency: string };
  billing_interval: string;
};

export type PromotionPolicy = {
  id: string;
  family_id: string;
  version: number;
  name: string;
  placement: "search" | "detail" | "storefront";
  label: string;
  disclosure: string;
  eligible_plan_ids: string[];
  starts_at: string;
  ends_at: string;
  max_impressions: number;
  status: "active" | "paused" | "ended";
};

export type PromotionCampaign = {
  id: string;
  listing_id: string;
  promotion_policy_id: string;
  placement: string;
  starts_at: string;
  ends_at: string;
  impression_cap: number;
  impression_count: number;
  status: "active" | "paused" | "ended";
  version: number;
};

export type BillingWorkspaceData = {
  agency_id: string;
  payments_enabled: boolean;
  plans: BillingPlan[];
  subscription: {
    id: string;
    plan_id: string;
    status: string;
    billing_status: string;
    current_period_ends_at: string | null;
    cancel_at: string | null;
    entitlements: Array<{ key: string; value: unknown; quota: number | null }>;
  } | null;
  invoices: Array<{
    id: string;
    number: string | null;
    status: string;
    subtotal_minor: number;
    tax_minor: number;
    total_minor: number;
    currency: string;
    period_starts_at: string | null;
    period_ends_at: string | null;
    hosted_invoice_url: string | null;
    invoice_pdf_url: string | null;
  }>;
  promotion_policies: PromotionPolicy[];
};
