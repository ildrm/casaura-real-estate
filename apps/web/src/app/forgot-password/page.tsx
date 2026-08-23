import type { Metadata } from "next";
import { AuthPageFrame } from "@/components/auth/auth-page-frame";
import { PasswordRecoveryForm } from "@/components/auth/password-recovery-form";

export const metadata: Metadata = { title: "Recover your account", robots: { index: false } };

export default function ForgotPasswordPage() {
  return <AuthPageFrame title="Recover your account." description="We’ll send a short-lived reset link if the address belongs to an eligible account."><PasswordRecoveryForm /></AuthPageFrame>;
}
