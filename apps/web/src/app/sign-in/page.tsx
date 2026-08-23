import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { SignInForm } from "@/components/auth/sign-in-form";
import { BrandMark } from "@/components/brand/logo";

export const metadata: Metadata = { title: "Sign in", robots: { index: false } };

export default async function SignInPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string }>;
}) {
  const { next } = await searchParams;

  return (
    <main className="auth-shell">
      <section className="auth-panel">
        <div className="auth-panel__inner">
          <BrandMark />
          <div className="auth-heading">
            <h1>Welcome back.</h1>
            <p>Sign in to continue your property search or manage your agency.</p>
          </div>
          <SignInForm nextPath={next} />
          <Link className="auth-back" href="/">← Back to Casaura</Link>
        </div>
      </section>
      <aside className="auth-image" aria-label="Contemporary home">
        <Image src="/images/properties/hero-home.png" alt="" fill priority sizes="50vw" />
        <blockquote>
          <p>One account for the homes you love and the agency you run.</p>
          <cite>Casaura workspace</cite>
        </blockquote>
      </aside>
    </main>
  );
}
