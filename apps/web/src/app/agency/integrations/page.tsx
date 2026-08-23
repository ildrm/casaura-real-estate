import type { Metadata } from "next";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";
import { IntegrationsWorkspace } from "@/components/integrations/integrations-workspace";

export const metadata: Metadata = { title: "Data integrations", robots: { index: false } };

export default function IntegrationsPage() {
  return <WorkspaceShell active="integrations"><IntegrationsWorkspace /></WorkspaceShell>;
}
