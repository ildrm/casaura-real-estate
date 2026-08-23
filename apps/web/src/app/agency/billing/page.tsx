import type { Metadata } from "next";
import { BillingWorkspace } from "@/components/billing/billing-workspace";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";

export const metadata: Metadata = { title: "Billing & promotion", robots: { index: false } };

export default function BillingPage() {
  return <WorkspaceShell active="billing"><BillingWorkspace /></WorkspaceShell>;
}
