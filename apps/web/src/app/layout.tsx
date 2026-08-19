import type { Metadata } from "next";
import type { ReactNode } from "react";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"),
  title: {
    default: "Casaura — Find a place that fits your life",
    template: "%s | Casaura",
  },
  description:
    "Search verified homes, compare the details that matter, and connect directly with trusted local agencies.",
  applicationName: "Casaura",
  openGraph: {
    title: "Casaura — Find a place that fits your life",
    description:
      "A clearer real-estate marketplace for consumers and local agencies.",
    type: "website",
  },
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
