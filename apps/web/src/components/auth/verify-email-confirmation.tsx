"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiQuery, type ApiError } from "@/lib/api-client";
import { publicConfig } from "@/lib/public-config";

export function VerifyEmailConfirmation({ verificationUrl }: { verificationUrl: string }) {
  const router = useRouter();
  const [error, setError] = useState<ApiError | null>(verificationUrl ? null : {
    code: "EMAIL_VERIFICATION_INVALID",
    message: "This verification link is incomplete.",
  });

  useEffect(() => {
    let active = true;
    async function verify() {
      try {
        const target = new URL(verificationUrl);
        const configuredApi = new URL(publicConfig.apiUrl);
        if (target.origin !== configuredApi.origin || !target.pathname.startsWith("/api/v1/auth/email/verify/")) throw new Error("Unexpected verification destination.");
        await apiQuery(`${target.pathname}${target.search}`);
        if (active) { router.replace("/mfa/setup"); router.refresh(); }
      } catch (caught) {
        if (active) setError((caught as ApiError).message ? caught as ApiError : { code: "EMAIL_VERIFICATION_INVALID", message: "This verification link is invalid or has expired." });
      }
    }
    if (verificationUrl) void verify();
    return () => { active = false; };
  }, [router, verificationUrl]);

  if (error) return <div className="identity-status"><div className="form-alert" role="alert">{error.message}</div><Link className="button button--outline" href="/verify-email">Request a new link</Link></div>;
  return <div className="identity-status" role="status"><h2>Verifying your email…</h2><p>This should take only a moment.</p></div>;
}
