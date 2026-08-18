import type { Metadata } from "next";
import { ListingEditor } from "@/components/listings/listing-editor";

export const metadata: Metadata = { title: "Edit property", robots: { index: false } };

export default async function EditPropertyPage({ params }: { params: Promise<{ listing: string }> }) {
  const { listing } = await params;
  return <ListingEditor initialListingId={listing} />;
}
