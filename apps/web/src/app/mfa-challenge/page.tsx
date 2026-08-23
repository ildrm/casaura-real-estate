import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { MfaChallengeForm } from "@/components/auth/mfa-challenge-form";

export const metadata: Metadata = { title: "Multi-factor authentication", robots: { index: false } };

export default async function MfaChallengePage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  const { next = "/agency/dashboard" } = await searchParams;
  return <AuthPageFrame title="One more security check." description="Enter your authenticator code or a one-time recovery code."><MfaChallengeForm nextPath={next} /></AuthPageFrame>;
}
