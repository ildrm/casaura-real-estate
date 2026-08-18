import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { WorkspaceBottomNav, WorkspaceSidebar } from "@/components/dashboard/workspace-sidebar";
import { Icon } from "@/components/ui/icon";
import { getDashboardData } from "@/lib/dashboard-data";

export const metadata: Metadata = { title: "Agency overview", robots: { index: false } };

export default async function AgencyDashboardPage() {
  const data = await getDashboardData();

  return (
    <div className="workspace" id="overview">
      <WorkspaceSidebar />
      <div className="workspace__main">
        <header className="workspace-topbar">
          <form className="workspace-search" action="/search">
            <Icon name="search" />
            <label className="sr-only" htmlFor="workspace-query">Search workspace</label>
            <input id="workspace-query" name="q" placeholder="Search properties, leads, or customers" />
          </form>
          <div className="workspace-profile"><span className="avatar">MP</span><span><strong>Maya Patel</strong><small>Agency owner</small></span><Icon name="chevron-down" /></div>
        </header>

        <main className="workspace-canvas">
          <section className="workspace-title">
            <div><h1>Good morning, Maya</h1><p>Here’s what needs your attention today.</p></div>
            <div className="workspace-title__actions">
              <span className="button button--disabled" aria-disabled="true" title="The listing wizard is delivered in Phase 2"><Icon name="plus" /> Add property</span>
              <Link className="button button--outline" href="/#agencies">View storefront</Link>
            </div>
          </section>

          <section className="metric-strip" aria-label="Agency summary">
            {data.metrics.map((metric) => (
              <div key={metric.label}><span className="metric-icon"><Icon name={metric.icon} /></span><strong>{metric.value}</strong><small>{metric.label}</small></div>
            ))}
          </section>

          <div className="dashboard-layout">
            <div className="dashboard-primary">
              <section className="dashboard-panel priorities" id="priorities" aria-labelledby="priorities-title">
                <h2 id="priorities-title">Today’s priorities</h2>
                {data.priorities.length ? data.priorities.map((priority) => (
                  <Link href={priority.icon === "calendar" ? "#viewings" : "#recent-leads"} className="priority-row" key={priority.title}>
                    <span><Icon name={priority.icon} /></span><span><strong>{priority.title}</strong><small>{priority.description}</small></span><Icon name="chevron-right" />
                  </Link>
                )) : <p className="panel-empty">Nothing needs attention yet.</p>}
              </section>

              <div className="dashboard-detail-grid">
                <section className="dashboard-panel leads-panel" id="recent-leads" aria-labelledby="leads-title">
                  <div className="panel-heading"><h2 id="leads-title">Recent leads</h2><span>Latest inquiries</span></div>
                  {data.leads.length ? (
                    <div className="leads-table" role="table" aria-label="Recent leads">
                      <div className="lead-row lead-row--head" role="row"><span>Lead</span><span>Property</span><span>Received</span><span>Status</span></div>
                      {data.leads.map((lead) => (
                        <div className="lead-row" role="row" key={lead.email}>
                          <span className="lead-person"><i>{lead.initials}</i><b><strong>{lead.name}</strong><small>{lead.email}</small></b></span>
                          <span><strong>{lead.property}</strong><small>{lead.location}</small></span>
                          <span>{lead.received}</span><span><em>{lead.status}</em></span>
                        </div>
                      ))}
                    </div>
                  ) : <p className="panel-empty">New inquiries will appear here.</p>}
                </section>

                <section className="dashboard-panel performance-panel" id="property-performance" aria-labelledby="performance-title">
                  <div className="panel-heading"><h2 id="performance-title">Property performance</h2><span>Last 30 days</span></div>
                  <div className="performance-legend"><span>Views</span><span>Inquiries</span><span>Favorites</span></div>
                  <svg className="performance-chart" viewBox="0 0 520 220" role="img" aria-label="Listing views, inquiries, and favorites in the last thirty days">
                    <g><path d="M34 36H500M34 91H500M34 146H500M34 201H500" /></g>
                    <path d="M34 157 82 137 130 164 178 108 226 78 274 119 322 96 370 127 418 84 466 106 500 43" />
                    <path d="M34 184 82 176 130 188 178 166 226 145 274 158 322 148 370 165 418 143 466 154 500 126" />
                    <path d="M34 198 82 192 130 199 178 188 226 184 274 187 322 181 370 189 418 182 466 188 500 161" />
                  </svg>
                  <div className="performance-totals"><span><strong>14,842</strong><small>Views · ↑18%</small></span><span><strong>2,318</strong><small>Inquiries · ↑12%</small></span><span><strong>892</strong><small>Favorites · ↑9%</small></span></div>
                </section>
              </div>
            </div>

            <aside className="dashboard-secondary">
              <section className="dashboard-panel setup-panel" id="storefront-setup" aria-labelledby="setup-title">
                <h2 id="setup-title">Storefront setup — 80% complete</h2><progress value="80" max="100">80%</progress>
                <ul><li><Icon name="check" /> Add agency details</li><li><Icon name="check" /> Upload logo</li><li><Icon name="check" /> Add team members</li><li><span>4</span> Customize storefront</li><li><span>5</span> Publish your storefront</li></ul>
                <Link className="button button--terracotta" href="/agency/profile">Finish setup</Link>
              </section>

              <section className="dashboard-panel viewings-panel" id="viewings" aria-labelledby="viewings-title">
                <div className="panel-heading"><h2 id="viewings-title">Upcoming viewings</h2><span>Today</span></div>
                {data.viewings.length ? data.viewings.map((viewing) => (
                  <div className="viewing-row" key={`${viewing.time}-${viewing.property}`}><strong>{viewing.time}</strong><Image src={viewing.image} alt="" width={52} height={52} /><span><b>{viewing.property}</b><small>{viewing.location}</small><small>{viewing.customer}</small></span></div>
                )) : <p className="panel-empty">Scheduled viewings will appear here.</p>}
              </section>

              <section className="dashboard-panel workspace-status" id="workspace-status">
                <span className="metric-icon"><Icon name="shield" /></span><div><h2>Workspace protected</h2><p>Tenant isolation and role permissions are active.</p></div>
              </section>
            </aside>
          </div>
        </main>
      </div>
      <WorkspaceBottomNav />
    </div>
  );
}
