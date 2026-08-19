import type { ReactNode } from "react";
import { WorkspaceBottomNav, WorkspaceSidebar } from "@/components/dashboard/workspace-sidebar";
import { WorkspaceTopbar } from "@/components/dashboard/workspace-topbar";

export function WorkspaceShell({ active, children }: { active: string; children: ReactNode }) {
  return <div className="workspace"><WorkspaceSidebar active={active} /><div className="workspace__main"><WorkspaceTopbar />{children}</div><WorkspaceBottomNav active={active} /></div>;
}
