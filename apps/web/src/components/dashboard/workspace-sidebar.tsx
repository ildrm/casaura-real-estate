import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { Icon, type IconName } from "@/components/ui/icon";

const items: Array<{ id: string; label: string; href: string; icon: IconName }> = [
  { id: "overview", label: "Overview", href: "/agency/dashboard", icon: "home" },
  { id: "properties", label: "Properties", href: "/agency/properties", icon: "building" },
  { id: "leads", label: "Leads", href: "/agency/dashboard#recent-leads", icon: "team" },
  { id: "viewings", label: "Viewings", href: "/agency/dashboard#viewings", icon: "calendar" },
  { id: "messages", label: "Messages", href: "/agency/dashboard#priorities", icon: "message" },
  { id: "customers", label: "Customers", href: "/agency/dashboard#recent-leads", icon: "user" },
  { id: "team", label: "Team", href: "/agency/dashboard#storefront-setup", icon: "team" },
  { id: "analytics", label: "Analytics", href: "/agency/dashboard#property-performance", icon: "chart" },
  { id: "profile", label: "Agency profile", href: "/agency/profile", icon: "building" },
  { id: "integrations", label: "Integrations", href: "/agency/dashboard#workspace-status", icon: "sparkle" },
  { id: "billing", label: "Billing", href: "/agency/dashboard#workspace-status", icon: "shield" },
  { id: "settings", label: "Settings", href: "/agency/dashboard#workspace-status", icon: "settings" },
];

export function WorkspaceSidebar({ active = "overview" }: { active?: string }) {
  return (
    <aside className="workspace-sidebar">
      <div className="workspace-sidebar__brand"><BrandMark /></div>
      <div className="agency-switcher" role="status" aria-label="Current agency: Greenway Realty">
        <Icon name="building" /><span>Greenway Realty</span><Icon name="chevron-down" />
      </div>
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
      <Link href="/agency/dashboard#workspace-status"><Icon name="menu" /><span>More</span></Link>
    </nav>
  );
}
