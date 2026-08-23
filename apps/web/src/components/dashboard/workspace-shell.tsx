import type { ReactNode } from "react";
import { WorkspaceBottomNav, WorkspaceSidebar } from "@/components/dashboard/workspace-sidebar";
import { WorkspaceSessionProvider } from "@/components/dashboard/workspace-session";
import { WorkspaceTopbar } from "@/components/dashboard/workspace-topbar";

export function WorkspaceShell({ active, children }: { active: string; children: ReactNode }) {
  return <WorkspaceSessionProvider><div className="workspace"><WorkspaceSidebar active={active} /><div className="workspace__main"><WorkspaceTopbar />{children}</div><WorkspaceBottomNav active={active} /></div></WorkspaceSessionProvider>;
}
