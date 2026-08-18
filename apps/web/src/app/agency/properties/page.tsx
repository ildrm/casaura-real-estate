import type { Metadata } from "next";
import { ListingsWorkspace } from "@/components/listings/listings-workspace";
import { WorkspaceBottomNav, WorkspaceSidebar } from "@/components/dashboard/workspace-sidebar";
import { WorkspaceTopbar } from "@/components/dashboard/workspace-topbar";

export const metadata: Metadata = { title: "Properties", robots: { index: false } };

export default function AgencyPropertiesPage() {
  return (
    <div className="workspace">
      <WorkspaceSidebar active="properties" />
      <div className="workspace__main">
        <WorkspaceTopbar />
        <ListingsWorkspace />
      </div>
      <WorkspaceBottomNav active="properties" />
    </div>
  );
}
