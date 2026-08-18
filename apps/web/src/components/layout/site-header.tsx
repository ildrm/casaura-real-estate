import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { Icon } from "@/components/ui/icon";

const navigation = [
  { label: "Buy", href: "/search?intent=buy" },
  { label: "Rent", href: "/search?intent=rent" },
  { label: "New homes", href: "/search?intent=new" },
  { label: "Agencies", href: "/#agencies" },
  { label: "Insights", href: "/#market-insights" },
];

export function SiteHeader() {
  return (
    <header className="site-header">
      <div className="site-header__inner shell">
        <BrandMark />
        <nav className="site-nav" aria-label="Primary navigation">
          {navigation.map((item) => (
            <Link key={item.label} href={item.href}>
              {item.label}
            </Link>
          ))}
        </nav>
        <div className="site-header__actions">
          <Link className="button button--outline header-list-property" href="/register/agency">
            List a property
          </Link>
          <Link className="sign-in-link" href="/sign-in">
            <Icon name="user" />
            <span>Sign in</span>
          </Link>
          <details className="mobile-menu">
            <summary aria-label="Open navigation menu">
              <Icon name="menu" />
            </summary>
            <nav aria-label="Mobile navigation">
              {navigation.map((item) => (
                <Link key={item.label} href={item.href}>
                  {item.label}
                </Link>
              ))}
              <Link href="/register/agency">List a property</Link>
            </nav>
          </details>
        </div>
      </div>
    </header>
  );
}
