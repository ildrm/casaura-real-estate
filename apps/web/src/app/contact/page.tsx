import type { Metadata } from "next";
import Link from "next/link";
import { SiteHeader } from "@/components/layout/site-header";
import { publicConfig } from "@/lib/public-config";

export const metadata: Metadata = { title: "Contact and support" };

export default function ContactPage() {
  return <><SiteHeader /><main className="legal-page shell"><h1>Contact and support</h1><p>For account access, security, privacy, or marketplace support, email <a href={`mailto:${publicConfig.supportEmail}`}>{publicConfig.supportEmail}</a>. Do not send passwords, reset links, authenticator secrets, or recovery codes.</p><p>Service operator: {publicConfig.operatorName}, {publicConfig.operatorAddress}, {publicConfig.operatorJurisdiction}.</p><p><Link href="/">Return to Casaura</Link></p></main></>;
}
