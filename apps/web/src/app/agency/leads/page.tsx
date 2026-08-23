import type { Metadata } from "next";
import { CollaborationWorkspace } from "@/components/collaboration/collaboration-workspace";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";

export const metadata: Metadata = { title: "Leads & collaboration", robots: { index: false, follow: false } };

export default function AgencyLeadsPage() {
  return <WorkspaceShell active="leads"><CollaborationWorkspace /></WorkspaceShell>;
}
