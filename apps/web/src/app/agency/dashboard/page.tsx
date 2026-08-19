import type { Metadata } from "next";
import Link from "next/link";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";
import { Icon } from "@/components/ui/icon";

export const metadata: Metadata = { title: "Agency overview", robots: { index: false } };

const workspaces = [
  { title: "Properties", description: "Create listings and review the current inventory projection.", href: "/agency/properties", icon: "home" as const, action: "Open inventory" },
  { title: "Leads & collaboration", description: "Work from the live inquiry, conversation, viewing, and reminder queues.", href: "/agency/leads", icon: "team" as const, action: "Open collaboration" },
  { title: "Growth", description: "Review date-bounded analytics, storefront hours, team access, and campaigns.", href: "/agency/growth", icon: "calendar" as const, action: "Open growth" },
  { title: "Agency profile", description: "Edit the public agency details loaded from the active tenant.", href: "/agency/profile", icon: "building" as const, action: "Edit profile" },
];

export default function AgencyDashboardPage() {
  return (
    <WorkspaceShell active="overview">
      <main className="workspace-canvas">
        <header className="workspace-title">
          <div><h1>Agency overview</h1><p>Choose a live workspace to review current agency information.</p></div>
          <div className="workspace-title__actions">
            <Link className="button button--primary" href="/agency/properties/new"><Icon name="plus" /> Add property</Link>
            <Link className="button button--outline" href="/agency/growth">Storefront & growth</Link>
          </div>
        </header>

        <section className="overview-route-grid" aria-label="Agency workspaces">
          {workspaces.map((workspace) => (
            <Link className="dashboard-panel overview-route-card" href={workspace.href} key={workspace.href}>
              <span className="metric-icon"><Icon name={workspace.icon} /></span>
              <span><h2>{workspace.title}</h2><p>{workspace.description}</p><strong>{workspace.action}</strong></span>
              <Icon name="chevron-right" />
            </Link>
          ))}
        </section>

        <section className="dashboard-panel workspace-status overview-status" id="workspace-status">
          <span className="metric-icon"><Icon name="shield" /></span>
          <div><h2>Workspace protected</h2><p>Tenant isolation and role permissions are active. Counts and performance are shown only inside their API-backed workspaces.</p></div>
        </section>
      </main>
    </WorkspaceShell>
  );
}
