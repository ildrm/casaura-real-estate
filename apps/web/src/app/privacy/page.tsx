import type { Metadata } from "next";
import Link from "next/link";
import { SiteHeader } from "@/components/layout/site-header";
import { publicConfig } from "@/lib/public-config";

export const metadata: Metadata = { title: "Privacy Notice" };

export default function PrivacyPage() {
  return <><SiteHeader /><main className="legal-page shell"><h1>Privacy Notice</h1><p className="legal-version">Version {publicConfig.legalVersion}</p><p>{publicConfig.operatorName}, at {publicConfig.operatorAddress}, is the service operator in {publicConfig.operatorJurisdiction}. Casaura processes account, agency, listing, inquiry, communication, and security data to operate the marketplace and agency workspace. Registration and inquiry consent evidence records the applicable version, source, time, and legal-text snapshot.</p><h2>Use and sharing</h2><p>Inquiry information is shared with the agency responsible for the selected property. Service providers may process data only to deliver hosting, email, storage, security, and support functions under the operator’s instructions.</p><h2>Retention and rights</h2><p>Raw public analytics identifiers are removed after 7 days and raw analytics events after 90 days. Deleted media is quarantined for 30 days, and encrypted account exports expire after 7 days. Security and audit evidence is restricted and retained only for the approved legal period. Signed-in users can request an export or deletion review from their account; access, correction, objection, or other requests can be made through support.</p><h2>Contact</h2><p>Privacy requests can be initiated through the <Link href="/contact">support contact</Link> or by emailing <a href={`mailto:${publicConfig.supportEmail}`}>{publicConfig.supportEmail}</a>.</p></main></>;
}
