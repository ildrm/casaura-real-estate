import Link from "next/link";
import { SiteHeader } from "@/components/layout/site-header";
import { Icon } from "@/components/ui/icon";

export default function NotFound() {
  return <><SiteHeader /><main className="storefront-page"><section className="storefront-loading"><Icon name="building" /><h1>Storefront not found</h1><p>This agency storefront is unavailable, inactive, or has not been published.</p><Link className="button button--primary" href="/search">Browse published homes</Link></section></main></>;
}
