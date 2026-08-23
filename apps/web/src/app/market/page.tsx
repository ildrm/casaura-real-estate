import type { Metadata } from "next";
import { SiteHeader } from "@/components/layout/site-header";
import { MarketIntelligence } from "@/components/marketplace/market-intelligence";

export const metadata: Metadata = { title: "Market insights", description: "Privacy-bounded market aggregates from current published Casaura inventory." };

export default function MarketPage() {
  return <><SiteHeader /><MarketIntelligence /></>;
}
