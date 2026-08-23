import type { PublicListingCard } from "@/lib/public-marketplace-types";

export type LeadStatus = "new" | "contacted" | "qualified" | "viewing" | "won" | "lost";

export type Lead = {
  id: string;
  listing_id: string;
  status: LeadStatus;
  priority: "low" | "normal" | "high";
  contact: { name: string; email: string; phone: string | null };
  assigned_member_id: string | null;
  first_responded_at: string | null;
  version: number;
  conversation_id: string | null;
  created_at: string;
};

export type Viewing = {
  id: string;
  lead_id: string;
  listing_id: string;
  starts_at: string;
  ends_at: string;
  timezone: string;
  status: "requested" | "confirmed" | "completed" | "cancelled" | "no_show";
  assigned_member_id?: string | null;
  notes?: string | null;
  version: number;
  warnings?: Array<{ code: string; message: string; overlap_count: number }>;
};

export type Reminder = {
  id: string;
  title: string;
  due_at: string;
  status: "pending" | "completed" | "cancelled";
  lead_id: string | null;
  viewing_request_id: string | null;
};

export type UserNotification = {
  id: string;
  type: string;
  title: string;
  body: string | null;
  data: Record<string, unknown> | null;
  read: boolean;
  created_at: string;
};

export type Message = { id: string; sender_user_id: string | null; body: string; created_at: string };

export type CollaborationAnalytics = {
  total_leads: number;
  responded_leads: number;
  response_rate: number;
  average_first_response_seconds: number | null;
};

export type AccountCollaboration = {
  viewings: Omit<Viewing, "notes" | "version">[];
  conversations: Array<{ id: string; lead_id: string; listing_id: string; subject: string; last_message_at: string }>;
};

export type Agency = {
  id: string;
  name: string;
  slug: string;
  email?: string;
  phone: string | null;
  website: string | null;
  short_description: string | null;
  description: string | null;
  timezone: string;
  verification_status: string;
  status: string;
};

export type OpeningHour = { id?: string; weekday: number; opens_at: string | null; closes_at: string | null; closed: boolean };
export type Closure = Omit<OpeningHour, "weekday"> & { date: string; reason: string | null };
export type OpeningHours = { timezone: string; hours: OpeningHour[]; closures: Closure[] };

export type TeamMember = {
  id: string;
  status: "invited" | "active" | "inactive";
  job_title: string | null;
  invitation_expires_at: string | null;
  is_public: boolean;
  public_position: number | null;
  user: { id: string; name: string; email: string };
  roles: Array<{ id: string; name: string; slug: string }>;
};

export type Campaign = {
  id: string;
  subject: string;
  body: string;
  status: "draft" | "sent";
  sent_at: string | null;
  delivery_count: number;
};

export type AgencyAnalytics = {
  range: { from: string; to: string };
  storefront_views: number;
  listing_views: number;
  favorites: number;
  leads: number;
  viewings: number;
  newsletter_deliveries: number;
};

export type FeatureResolution = { enabled: boolean; value?: unknown; source?: string };

export type PublicStorefront = {
  agency: {
    id: string;
    name: string;
    slug: string;
    phone: string | null;
    website: string | null;
    short_description: string | null;
    description: string | null;
    timezone: string;
    verified: boolean;
  };
  opening_hours: OpeningHour[];
  team: Array<{ id: string; name: string; job_title: string | null }>;
  listings: PublicListingCard[];
};

export type AdminHealth = {
  status: "ok" | "degraded";
  version: string;
  checked_at: string;
  components: Record<string, { status: string; backlog?: number }>;
  request_id: string | null;
};

export type ModerationCase = {
  id: string;
  status: "open" | "reviewing" | "resolved" | "dismissed";
  category: string;
  target_type: string;
  target_id: string;
  assigned_user_id: string | null;
  outcome: string | null;
  note: string | null;
  version: number;
  created_at: string;
  updated_at: string;
  report?: { details: string | null; created_at: string } | null;
};

export type AdminSetting = {
  id: string;
  namespace: string;
  key: string;
  value: unknown;
  secret: boolean;
  version: number;
};

export type FeatureFlag = {
  id: string;
  key: string;
  description: string | null;
  default_enabled: boolean;
  overrides: Array<{ id: string; scope_type: "global" | "agency"; scope_id: string | null; enabled: boolean; value: unknown; starts_at: string | null; ends_at: string | null }>;
};

export type Permission = { id: string; name: string; group: string; description: string | null };
export type AdminRole = { id: string; name: string; slug: string; scope: "agency" | "platform"; system: boolean; permissions: string[] };
export type AuditLog = {
  id: string;
  actor_user_id: string | null;
  agency_id: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  changed_fields: string[];
  request_id: string | null;
  created_at: string;
};
