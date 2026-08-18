import type { Metadata } from "next";
import { ListingEditor } from "@/components/listings/listing-editor";

export const metadata: Metadata = { title: "Add property", robots: { index: false } };

export default function NewPropertyPage() {
  return <ListingEditor />;
}
