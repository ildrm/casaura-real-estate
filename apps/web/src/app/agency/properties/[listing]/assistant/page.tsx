import type { Metadata } from "next";
import { ListingWriter } from "@/components/ai/listing-writer";
import { WorkspaceShell } from "@/components/dashboard/workspace-shell";

export const metadata: Metadata = { title: "Grounded listing writer", robots: { index: false } };

export default async function ListingAssistantPage({ params }: { params: Promise<{ listing: string }> }) {
  const { listing } = await params;
  return <WorkspaceShell active="properties"><ListingWriter listingId={listing} /></WorkspaceShell>;
}
