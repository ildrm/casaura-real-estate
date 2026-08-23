import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { MfaSetupForm } from "@/components/auth/mfa-setup-form";

export const metadata: Metadata = { title: "Set up multi-factor authentication", robots: { index: false } };

export default function MfaSetupPage() {
  return <AuthPageFrame title="Protect your workspace." description="Agency owners and platform operators must use an authenticator as a second factor."><MfaSetupForm /></AuthPageFrame>;
}
