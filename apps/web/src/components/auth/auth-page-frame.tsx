import type { ReactNode } from "react";
import Image from "next/image";
import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";

export function AuthPageFrame({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return <main className="auth-shell">
    <section className="auth-panel"><div className="auth-panel__inner"><BrandMark /><div className="auth-heading"><h1>{title}</h1><p>{description}</p></div>{children}<Link className="auth-back" href="/">← Back to Casaura</Link></div></section>
    <aside className="auth-image" aria-label="Contemporary home"><Image src="/images/properties/hero-home.png" alt="" fill priority sizes="50vw" /><blockquote><p>Your account security travels with every Casaura workspace.</p><cite>Casaura identity</cite></blockquote></aside>
  </main>;
}
