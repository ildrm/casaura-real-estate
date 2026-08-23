"use client";

import { useWorkspaceSession } from "@/components/dashboard/workspace-session";
import { Icon } from "@/components/ui/icon";

export function WorkspaceTopbar() {
  const { principal, membership } = useWorkspaceSession();
  return (
    <header className="workspace-topbar">
      <form className="workspace-search" action="/agency/properties">
        <Icon name="search" />
        <label className="sr-only" htmlFor="workspace-query">Search workspace</label>
        <input id="workspace-query" name="q" placeholder="Search properties, leads, or customers" />
      </form>
      <div className="workspace-profile"><span className="avatar">{initials(principal.name)}</span><span><strong>{principal.name}</strong><small>{membership.agency.name}</small></span><Icon name="chevron-down" /></div>
    </header>
  );
}

function initials(name: string): string { return name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase(); }
