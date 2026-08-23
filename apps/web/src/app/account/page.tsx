import type { Metadata } from "next";
import { AccountDashboard } from "@/components/marketplace/account-dashboard";
import { SiteHeader } from "@/components/layout/site-header";

export const metadata: Metadata = { title: "Your property search", robots: { index: false, follow: false } };

export default function AccountPage() {
  return <><SiteHeader /><AccountDashboard /></>;
}
