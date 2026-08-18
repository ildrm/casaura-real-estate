import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { Icon, type IconName } from "@/components/ui/icon";

const items: Array<{ label: string; href: string; icon: IconName }> = [
  { label: "Overview", href: "#overview", icon: "home" },
  { label: "Properties", href: "#property-performance", icon: "building" },
  { label: "Leads", href: "#recent-leads", icon: "team" },
  { label: "Viewings", href: "#viewings", icon: "calendar" },
  { label: "Messages", href: "#priorities", icon: "message" },
  { label: "Customers", href: "#recent-leads", icon: "user" },
  { label: "Team", href: "#storefront-setup", icon: "team" },
  { label: "Analytics", href: "#property-performance", icon: "chart" },
  { label: "Agency profile", href: "/agency/profile", icon: "building" },
  { label: "Integrations", href: "#workspace-status", icon: "sparkle" },
  { label: "Billing", href: "#workspace-status", icon: "shield" },
  { label: "Settings", href: "#workspace-status", icon: "settings" },
];

export function WorkspaceSidebar() {
  return (
    <aside className="workspace-sidebar">
      <div className="workspace-sidebar__brand"><BrandMark /></div>
      <div className="agency-switcher" role="status" aria-label="Current agency: Greenway Realty">
        <Icon name="building" /><span>Greenway Realty</span><Icon name="chevron-down" />
      </div>
      <nav aria-label="Agency workspace">
        {items.map((item, index) => (
          <Link className={index === 0 ? "is-active" : undefined} href={item.href} key={item.label}>
            <Icon name={item.icon} /><span>{item.label}</span>{item.label === "Messages" ? <i aria-label="Unread messages" /> : null}
          </Link>
        ))}
      </nav>
    </aside>
  );
}

export function WorkspaceBottomNav() {
  return (
    <nav className="workspace-bottom-nav" aria-label="Agency quick navigation">
      {items.slice(0, 4).map((item, index) => (
        <Link className={index === 0 ? "is-active" : undefined} href={item.href} key={item.label}>
          <Icon name={item.icon} /><span>{item.label}</span>
        </Link>
      ))}
      <Link href="#workspace-status"><Icon name="menu" /><span>More</span></Link>
    </nav>
  );
}
