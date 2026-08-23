import type { Metadata } from "next";
import { WorkspaceSessionProvider } from "@/components/dashboard/workspace-session";
import { ListingEditor } from "@/components/listings/listing-editor";

export const metadata: Metadata = { title: "Add property", robots: { index: false } };

export default function NewPropertyPage() {
  return <WorkspaceSessionProvider><ListingEditor /></WorkspaceSessionProvider>;
}
