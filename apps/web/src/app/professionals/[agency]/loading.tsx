import { SiteHeader } from "@/components/layout/site-header";

export default function Loading() {
  return <><SiteHeader /><main className="storefront-page"><section className="storefront-loading" role="status"><span className="inline-spinner" /><h1>Opening the agency storefront</h1><p>Loading the published team, hours, and property collection.</p></section></main></>;
}
