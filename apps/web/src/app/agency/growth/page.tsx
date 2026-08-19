import type { Metadata } from "next";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";
import { GrowthWorkspace } from "@/components/growth/growth-workspace";

export const metadata: Metadata = { title: "Agency growth", robots: { index: false, follow: false } };

export default function AgencyGrowthPage() {
  return <WorkspaceShell active="integrations"><GrowthWorkspace /></WorkspaceShell>;
}
