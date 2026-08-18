export type DashboardData = {
  metrics: Array<{ label: string; value: number; icon: "home" | "building" | "team" | "calendar" }>;
  priorities: Array<{ title: string; description: string; icon: "team" | "calendar" | "building" }>;
  leads: Array<{ name: string; email: string; property: string; location: string; received: string; status: string; initials: string }>;
  viewings: Array<{ time: string; property: string; location: string; customer: string; image: string }>;
};

const preview: DashboardData = {
  metrics: [
    { label: "Active properties", value: 24, icon: "home" },
    { label: "Drafts", value: 6, icon: "building" },
    { label: "New leads", value: 18, icon: "team" },
    { label: "Upcoming viewings", value: 3, icon: "calendar" },
  ],
  priorities: [
    { title: "3 new leads need a response", description: "Respond to inquiries from the last 24 hours.", icon: "team" },
    { title: "2 viewings scheduled today", description: "Review and confirm your upcoming appointments.", icon: "calendar" },
    { title: "2 properties need details", description: "Add missing information to improve visibility.", icon: "building" },
  ],
  leads: [
    { name: "Sarah Johnson", email: "sarah.j@example.com", property: "241 Oakridge Dr", location: "Austin, TX", received: "Today, 9:14 AM", status: "New", initials: "SJ" },
    { name: "Mike Turner", email: "mike.t@example.com", property: "321 Ocean Ave", location: "Santa Monica, CA", received: "Today, 8:02 AM", status: "New", initials: "MT" },
    { name: "Olivia Chen", email: "olivia.c@example.com", property: "157 Maple St", location: "Denver, CO", received: "Yesterday, 6:45 PM", status: "Contacted", initials: "OC" },
  ],
  viewings: [
    { time: "10:00 AM", property: "241 Oakridge Dr", location: "Austin, TX", customer: "Sarah Johnson", image: "/images/properties/hero-home.png" },
    { time: "1:30 PM", property: "321 Ocean Ave", location: "Santa Monica, CA", customer: "Mike Turner", image: "/images/properties/ocean-apartment.png" },
    { time: "4:00 PM", property: "88 Pine Ln", location: "Portland, OR", customer: "Emma Davis", image: "/images/properties/maple-townhouse.png" },
  ],
};

const empty: DashboardData = {
  metrics: [
    { label: "Active properties", value: 0, icon: "home" },
    { label: "Drafts", value: 0, icon: "building" },
    { label: "New leads", value: 0, icon: "team" },
    { label: "Upcoming viewings", value: 0, icon: "calendar" },
  ],
  priorities: [],
  leads: [],
  viewings: [],
};

export async function getDashboardData(): Promise<DashboardData> {
  return process.env.NODE_ENV === "development" || process.env.NEXT_PUBLIC_ENABLE_DEMO_DATA === "true"
    ? preview
    : empty;
}
