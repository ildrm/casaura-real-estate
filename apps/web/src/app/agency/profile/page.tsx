import type { Metadata } from "next";
import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { AgencyProfileForm } from "@/components/dashboard/agency-profile-form";

export const metadata: Metadata = { title: "Agency profile", robots: { index: false } };

export default function AgencyProfilePage() {
  return (
    <main className="profile-page">
      <header><BrandMark /><Link href="/agency/dashboard">Back to overview</Link></header>
      <section className="profile-page__intro"><h1>Agency profile</h1><p>Keep the public essentials clear and current. Legal and verification documents are managed separately.</p></section>
      <section className="profile-page__panel"><AgencyProfileForm /></section>
    </main>
  );
}
