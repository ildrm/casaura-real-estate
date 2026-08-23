import type { Metadata } from "next";
import Link from "next/link";
import { SiteHeader } from "@/components/layout/site-header";
import { publicConfig } from "@/lib/public-config";

export const metadata: Metadata = { title: "Terms of Service" };

export default function TermsPage() {
  return <><SiteHeader /><main className="legal-page shell"><h1>Terms of Service</h1><p className="legal-version">Version {publicConfig.legalVersion}</p><p>These terms govern access to Casaura’s property marketplace and agency workspace operated by {publicConfig.operatorName} in {publicConfig.operatorJurisdiction}. Account holders must provide accurate information, protect their credentials, use property and customer information lawfully, and avoid abusive, fraudulent, or unauthorized activity.</p><h2>Agency responsibilities</h2><p>Agencies remain responsible for listing accuracy, permissions to publish media and property details, customer communications, regulatory obligations, and the actions of invited team members.</p><h2>Availability and enforcement</h2><p>Features may depend on the active plan and launch configuration. Casaura may suspend access to protect users, investigate abuse, comply with law, or preserve service integrity.</p><h2>Contact</h2><p>Formal notices may be sent to {publicConfig.operatorAddress}. Questions about these terms can be sent through the <Link href="/contact">support contact</Link>.</p></main></>;
}
