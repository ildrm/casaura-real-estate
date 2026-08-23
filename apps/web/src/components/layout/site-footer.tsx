import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";

const columns = [
  {
    title: "Buy",
    links: ["Homes for sale", "New homes", "Open houses", "Buyers guide"],
  },
  {
    title: "Rent",
    links: ["Homes for rent", "Apartments", "Renters guide", "Saved searches"],
  },
  {
    title: "Agencies",
    links: ["Find an agency", "List a property", "Agency tools", "Resources"],
  },
  {
    title: "Company",
    links: ["About us", "Careers", "Press", "Contact"],
  },
];

export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="shell footer-grid">
        <div className="footer-brand">
          <BrandMark />
          <p>Verified homes, trusted experts. A better way to find home.</p>
        </div>
        {columns.map((column) => (
          <div className="footer-column" key={column.title}>
            <h2>{column.title}</h2>
            {column.links.map((label) => (
              <Link key={label} href={label === "List a property" ? "/register/agency" : "/search"}>
                {label}
              </Link>
            ))}
          </div>
        ))}
        <div className="footer-subscribe">
          <h2>Subscribe to insights</h2>
          <p>Subscriptions open once a compliant email provider and double opt-in flow are configured.</p>
        </div>
      </div>
      <div className="shell footer-legal">
        <span>© {new Date().getFullYear()} Casaura, Inc.</span>
        <div>
          <Link href="/">Privacy</Link>
          <Link href="/">Terms</Link>
          <Link href="/">Accessibility</Link>
        </div>
        <span>English · United States</span>
      </div>
    </footer>
  );
}
