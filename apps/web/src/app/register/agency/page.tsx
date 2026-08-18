import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { AgencyRegistrationForm } from "@/components/auth/agency-registration-form";
import { BrandMark } from "@/components/brand/logo";
import { Icon } from "@/components/ui/icon";

export const metadata: Metadata = { title: "Create your agency workspace", robots: { index: true } };

export default function AgencyRegistrationPage() {
  return (
    <main className="auth-shell registration-shell">
      <section className="auth-panel">
        <div className="auth-panel__inner">
          <BrandMark />
          <div className="auth-heading">
            <h1>Your agency, ready to grow.</h1>
            <p>Create a branded storefront and a secure workspace for your team.</p>
          </div>
          <AgencyRegistrationForm />
          <Link className="auth-back" href="/">← Back to Casaura</Link>
        </div>
      </section>
      <aside className="auth-image registration-image">
        <Image src="/images/properties/oakridge-kitchen.png" alt="Bright modern home interior" fill priority sizes="50vw" />
        <div className="registration-proof">
          <h2>Included from day one</h2>
          <ul>
            <li><Icon name="check" /> SEO-ready agency storefront</li>
            <li><Icon name="check" /> Team roles and tenant-safe access</li>
            <li><Icon name="check" /> Configurable launch entitlements</li>
          </ul>
        </div>
      </aside>
    </main>
  );
}
