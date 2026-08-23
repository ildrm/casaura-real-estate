"use client";

import { Icon } from "@/components/ui/icon";

export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return <main className="storefront-page"><section className="storefront-loading" role="alert"><Icon name="shield" /><h1>Storefront temporarily unavailable</h1><p>The agency’s published information could not be loaded. No private data was exposed.</p><button className="button button--outline" type="button" onClick={reset}>Try again</button></section></main>;
}
