"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { AccountCollaboration } from "@/components/marketplace/account-collaboration";
import { Icon } from "@/components/ui/icon";
import { apiQuery, type ApiError } from "@/lib/api-client";
import type { EngagementResponse, PublicListingCard } from "@/lib/public-marketplace-types";

const sections: Array<{ key: keyof EngagementResponse["data"]; label: string; empty: string }> = [
  { key: "favorites", label: "Favorites", empty: "Properties you favorite will appear here." },
  { key: "likes", label: "Liked", empty: "Use Like on a property to teach your private shortlist." },
  { key: "dislikes", label: "Disliked", empty: "Dislikes stay private and can be removed at any time." },
];

export function AccountDashboard() {
  const [data, setData] = useState<EngagementResponse["data"] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try { setData((await apiQuery<EngagementResponse>("/api/v1/account/engagements")).data); }
      catch (caught) { setError(caught as ApiError); }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  return <main className="account-page shell">
    <header className="account-heading"><span><Icon name="heart" /></span><div><p>Private consumer account</p><h1>Your property search</h1><p>Keep favorites, likes, and dislikes organized across devices.</p></div></header>
    {error?.code === "UNAUTHENTICATED" ? <section className="account-sign-in"><Icon name="user" /><h2>Sign in to see your saved homes</h2><p>Your account state is private and never inferred from another visitor’s activity.</p><Link className="button button--primary" href="/sign-in?next=/account">Sign in</Link></section> : null}
    {error && error.code !== "UNAUTHENTICATED" ? <section className="account-sign-in" role="alert"><Icon name="shield" /><h2>Account state is unavailable</h2><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => window.location.reload()}>Try again</button></section> : null}
    {!error && !data ? <section className="account-loading" role="status"><span /> Loading your private shortlist…</section> : null}
    {data ? <><AccountCollaboration /><div className="account-sections">{sections.map((section) => <AccountSection key={section.key} title={section.label} empty={section.empty} listings={data[section.key]} />)}</div></> : null}
  </main>;
}

function AccountSection({ title, empty, listings }: { title: string; empty: string; listings: PublicListingCard[] }) {
  return <section className="account-section"><header><h2>{title}</h2><span>{listings.length}</span></header>{listings.length ? <div>{listings.map((listing) => <PublicPropertyCard listing={listing} key={listing.id} hideFavorite />)}</div> : <p className="account-empty">{empty} <Link href="/search">Explore published properties.</Link></p>}</section>;
}
