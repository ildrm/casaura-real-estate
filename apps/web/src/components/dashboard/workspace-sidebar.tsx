"use client";

import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { useWorkspaceSession } from "@/components/dashboard/workspace-session";
import { Icon, type IconName } from "@/components/ui/icon";

const items: Array<{ id: string; label: string; href: string; icon: IconName }> = [
  { id: "overview", label: "Overview", href: "/agency/dashboard", icon: "home" },
  { id: "properties", label: "Properties", href: "/agency/properties", icon: "building" },
  { id: "leads", label: "Leads", href: "/agency/leads", icon: "team" },
  { id: "viewings", label: "Viewings", href: "/agency/leads#viewings", icon: "calendar" },
  { id: "messages", label: "Messages", href: "/agency/leads#conversation", icon: "message" },
  { id: "customers", label: "Customers", href: "/agency/leads", icon: "user" },
  { id: "team", label: "Team", href: "/agency/growth#team", icon: "team" },
  { id: "analytics", label: "Analytics", href: "/agency/growth#analytics", icon: "chart" },
  { id: "profile", label: "Agency profile", href: "/agency/profile", icon: "building" },
  { id: "growth", label: "Growth", href: "/agency/growth", icon: "sparkle" },
  { id: "integrations", label: "Integrations", href: "/agency/integrations", icon: "settings" },
  { id: "billing", label: "Billing & promotion", href: "/agency/billing", icon: "shield" },
];

export function WorkspaceSidebar({ active = "overview" }: { active?: string }) {
  const { membership, principal, selectAgency } = useWorkspaceSession();
  return (
    <aside className="workspace-sidebar">
      <div className="workspace-sidebar__brand"><BrandMark /></div>
      <label className="agency-switcher"><Icon name="building" /><span className="sr-only">Active agency workspace</span><select value={membership.agency.id} onChange={(event) => selectAgency(event.target.value)}>{principal.memberships.map((item) => <option value={item.agency.id} key={item.id}>{item.agency.name}</option>)}</select><Icon name="chevron-down" /></label>
      <nav aria-label="Agency workspace">
        {items.map((item) => (
          <Link className={item.id === active ? "is-active" : undefined} href={item.href} key={item.label}>
            <Icon name={item.icon} /><span>{item.label}</span>{item.label === "Messages" ? <i aria-label="Unread messages" /> : null}
          </Link>
        ))}
      </nav>
    </aside>
  );
}

export function WorkspaceBottomNav({ active = "overview" }: { active?: string }) {
  return (
    <nav className="workspace-bottom-nav" aria-label="Agency quick navigation">
      {items.slice(0, 4).map((item) => (
        <Link className={item.id === active ? "is-active" : undefined} href={item.href} key={item.label}>
          <Icon name={item.icon} /><span>{item.label}</span>
        </Link>
      ))}
      <Link href="/agency/growth"><Icon name="menu" /><span>More</span></Link>
    </nav>
  );
}
