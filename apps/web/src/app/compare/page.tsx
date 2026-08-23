import type { Metadata } from "next";
import { SiteHeader } from "@/components/layout/site-header";
import { ComparisonWorkspace } from "@/components/marketplace/comparison-workspace";

export const metadata: Metadata = { title: "Compare homes", robots: { index: false, follow: false } };

export default async function ComparePage({ searchParams }: { searchParams: Promise<{ ids?: string }> }) {
  const params = await searchParams;
  const ids = Array.from(new Set((params.ids ?? "").split(",").map((id) => id.trim()).filter(Boolean)));
  return <><SiteHeader /><ComparisonWorkspace initialIds={ids} /></>;
}
