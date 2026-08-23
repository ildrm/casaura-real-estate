import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { InvitationAcceptanceForm } from "@/components/auth/invitation-acceptance-form";

export const metadata: Metadata = { title: "Accept agency invitation", robots: { index: false } };

export default async function InvitationAcceptancePage({ searchParams }: { searchParams: Promise<{ token?: string }> }) {
  const { token = "" } = await searchParams;
  return <AuthPageFrame title="Join your agency workspace." description="Accept the invitation using the account and email address that received it."><InvitationAcceptanceForm token={token} /></AuthPageFrame>;
}
