import type { Metadata } from "next";
import { ListingsWorkspace } from "@/components/listings/listings-workspace";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";

export const metadata: Metadata = { title: "Properties", robots: { index: false } };

export default function AgencyPropertiesPage() {
  return (
    <WorkspaceShell active="properties"><ListingsWorkspace /></WorkspaceShell>
  );
}
