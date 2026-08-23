import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { VerifyEmailConfirmation } from "@/components/auth/verify-email-confirmation";

export const metadata: Metadata = { title: "Confirm your email", robots: { index: false } };

export default async function VerifyEmailConfirmPage({ searchParams }: { searchParams: Promise<{ verification_url?: string }> }) {
  const { verification_url: verificationUrl = "" } = await searchParams;
  return <AuthPageFrame title="Confirming your address." description="Casaura is validating the signed, short-lived verification request."><VerifyEmailConfirmation verificationUrl={verificationUrl} /></AuthPageFrame>;
}
