import type { Metadata } from "next";
import { SiteHeader } from "@/components/layout/site-header";
import { CollectionInvitation } from "@/components/marketplace/collection-invitation";

export const metadata: Metadata = { title: "Collection invitation", robots: { index: false, follow: false } };

export default async function CollectionInvitationPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return <><SiteHeader /><CollectionInvitation token={token} /></>;
}
