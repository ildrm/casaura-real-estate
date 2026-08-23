import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { VerifyEmailForm } from "@/components/auth/verify-email-form";

export const metadata: Metadata = { title: "Verify your email", robots: { index: false } };

export default function VerifyEmailPage() {
  return <AuthPageFrame title="Verify your email." description="Open the signed link we sent to your address. You’ll continue with account security after verification."><VerifyEmailForm /></AuthPageFrame>;
}
