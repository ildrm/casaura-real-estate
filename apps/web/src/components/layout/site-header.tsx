import Link from "next/link";
import { BrandMark } from "@/components/brand/logo";
import { Icon } from "@/components/ui/icon";

const navigation = [
  { label: "Buy", href: "/search?intent=buy" },
  { label: "Rent", href: "/search?intent=rent" },
  { label: "Explore", href: "/search" },
  { label: "Agencies", href: "/#agencies" },
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
          <Link className="header-saved" href="/account"><Icon name="heart" /><span>Saved</span></Link>
          <Link className="button button--outline header-list-property" href="/register/agency">
            List a property
          </Link>
          <Link className="sign-in-link" href="/account">
            <Icon name="user" />
            <span>My account</span>
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
              <Link href="/account">Saved properties</Link>
              <Link href="/register/agency">List a property</Link>
            </nav>
          </details>
        </div>
      </div>
    </header>
  );
}
