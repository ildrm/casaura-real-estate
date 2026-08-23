import type { Metadata } from "next";
import { SiteHeader } from "@/components/layout/site-header";
import { CollectionsWorkspace } from "@/components/marketplace/collections-workspace";

export const metadata: Metadata = { title: "Private collections", robots: { index: false, follow: false } };

export default function CollectionsPage() {
  return <><SiteHeader /><CollectionsWorkspace /></>;
}
