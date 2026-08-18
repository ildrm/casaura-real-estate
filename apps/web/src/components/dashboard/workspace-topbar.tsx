import { Icon } from "@/components/ui/icon";

export function WorkspaceTopbar() {
  return (
    <header className="workspace-topbar">
      <form className="workspace-search" action="/agency/properties">
        <Icon name="search" />
        <label className="sr-only" htmlFor="workspace-query">Search workspace</label>
        <input id="workspace-query" name="q" placeholder="Search properties, leads, or customers" />
      </form>
      <div className="workspace-profile"><span className="avatar">MP</span><span><strong>Maya Patel</strong><small>Agency owner</small></span><Icon name="chevron-down" /></div>
    </header>
  );
}
