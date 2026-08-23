import type { Metadata } from "next";
import { PropertyAssistant } from "@/components/ai/property-assistant";
import { SiteHeader } from "@/components/layout/site-header";

export const metadata: Metadata = { title: "Property assistant", description: "Grounded search and comparison using current Casaura listing data." };

export default function AssistantPage() {
  return <><SiteHeader /><PropertyAssistant /></>;
}
