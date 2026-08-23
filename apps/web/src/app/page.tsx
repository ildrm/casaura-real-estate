import Image from "next/image";
import Link from "next/link";
import { HomeSearch } from "@/components/home/home-search";
import { PropertyCard } from "@/components/home/property-card";
import { SiteFooter } from "@/components/layout/site-footer";
import { SiteHeader } from "@/components/layout/site-header";
import { Icon } from "@/components/ui/icon";
import { getMarketplacePreview } from "@/lib/marketplace-data";
import { publicConfig } from "@/lib/public-config";

const locations = [
  { name: "Austin, TX", count: "1,842 homes", image: "/images/properties/oakridge-kitchen.png" },
  { name: "Denver, CO", count: "1,256 homes", image: "/images/properties/hero-home.png" },
  { name: "Nashville, TN", count: "987 homes", image: "/images/properties/maple-townhouse.png" },
  { name: "Santa Monica, CA", count: "743 homes", image: "/images/properties/ocean-apartment.png" },
];
const demoDataEnabled = process.env.NODE_ENV === "development" || publicConfig.demoData;

export default async function Home() {
  const { listings, agencies } = await getMarketplacePreview();

  return (
    <>
      <SiteHeader />
      <main>
        <section className="hero" aria-labelledby="hero-title">
          <div className="hero__content">
            <div className="hero__copy">
              <h1 id="hero-title">Find a place that fits your life.</h1>
              <p>
                Search verified homes, compare the details that matter, and connect
                directly with trusted local agencies.
              </p>
              <HomeSearch />
            </div>
          </div>
          <div className="hero__image">
            <Image
              src="/images/properties/hero-home.png"
              alt="Contemporary stone and charcoal home framed by mature trees"
              fill
              priority
              sizes="(max-width: 860px) 100vw, 52vw"
            />
          </div>
        </section>

        <section className="section shell" aria-labelledby="featured-title">
          <div className="section-heading">
            <h2 id="featured-title">Homes worth a closer look</h2>
            <Link href="/search">View all homes <Icon name="arrow-right" /></Link>
          </div>
          {listings.length > 0 ? (
            <div className="property-rail">
              {listings.map((listing, index) => (
                <PropertyCard listing={listing} priority={index < 2} key={listing.id} />
              ))}
            </div>
          ) : (
            <div className="empty-state">
              <h3>Fresh listings are on the way.</h3>
              <p>Connect a licensed feed or publish an agency listing to populate this collection.</p>
              <Link className="button button--outline" href="/register/agency">Join as an agency</Link>
            </div>
          )}
        </section>

        {demoDataEnabled ? <section className="section shell section--divided" aria-labelledby="places-title">
          <div className="section-heading">
            <h2 id="places-title">Explore by place</h2>
            <Link href="/search">Browse all places <Icon name="arrow-right" /></Link>
          </div>
          <div className="location-grid">
            {locations.map((location, index) => (
              <Link className={`location-card location-card--${index + 1}`} href={`/search?q=${encodeURIComponent(location.name)}`} key={location.name}>
                <Image src={location.image} alt="" fill sizes="(max-width: 720px) 100vw, 50vw" />
                <span><strong>{location.name}</strong><small>{location.count}</small></span>
              </Link>
            ))}
          </div>
        </section> : null}

        <section className="section shell section--divided" id="agencies" aria-labelledby="agencies-title">
          <div className="section-heading">
            <h2 id="agencies-title">Local experts, clearly verified</h2>
            <Link href="/search?type=agency">See all agencies <Icon name="arrow-right" /></Link>
          </div>
          {agencies.length > 0 ? (
            <div className="agency-rail">
              {agencies.map((agency) => (
                <Link href={`/search?q=${encodeURIComponent(agency.name)}`} className="agency-card" key={agency.name}>
                  <span className={`agency-card__logo agency-card__logo--${agency.tone}`} aria-hidden="true">{agency.initials}</span>
                  <span className="agency-card__info">
                    <strong>{agency.name}</strong>
                    <small>{agency.city}</small>
                    <small className="verified"><Icon name="shield" /> Verified · {agency.rating}</small>
                  </span>
                  <Icon name="chevron-right" />
                </Link>
              ))}
            </div>
          ) : (
            <p className="muted">Verified agencies will appear after approval.</p>
          )}
        </section>

        {demoDataEnabled ? <section className="section shell section--divided market" id="market-insights" aria-labelledby="market-title">
          <div className="section-heading">
            <h2 id="market-title">A clearer view of the market</h2>
            <Link href="/search?q=Austin%2C+TX"><Icon name="map-pin" /> Austin, TX</Link>
          </div>
          <div className="market__grid">
            <div className="market__stats">
              <div><span>Median price</span><strong>$525,000</strong><small className="trend-up">+3.2% vs last month</small></div>
              <div><span>New listings</span><strong>1,248</strong><small className="trend-up">+8.6% vs last month</small></div>
              <div><span>Homes sold</span><strong>892</strong><small className="trend-down">−4.1% vs last month</small></div>
              <div><span>Avg. days on market</span><strong>34</strong><small className="trend-up">−2 days vs last month</small></div>
            </div>
            <div className="market-chart" role="img" aria-label="Median listing price trend from January through June">
              <div className="market-chart__legend"><span>Median price</span><span>Homes sold</span></div>
              <svg viewBox="0 0 640 210" aria-hidden="true">
                <g className="chart-grid"><path d="M44 28H620M44 78H620M44 128H620M44 178H620" /></g>
                <path className="chart-line" d="M44 126 140 102 236 119 332 132 428 108 524 141 620 77" />
                <path className="chart-line chart-line--secondary" d="M44 153 140 130 236 170 332 136 428 174 524 116 620 145" />
              </svg>
              <div className="market-chart__months"><span>Jan</span><span>Feb</span><span>Mar</span><span>Apr</span><span>May</span><span>Jun</span></div>
            </div>
          </div>
          <p className="methodology">Development preview data. Production reports require a configured licensed data source and will display period and methodology.</p>
        </section> : null}

        <section className="agency-cta shell" aria-labelledby="agency-cta-title">
          <div className="agency-cta__copy">
            <h2 id="agency-cta-title">Your storefront.<br />Your leads. Your brand.</h2>
            <p>Publish properties, manage inquiries, and grow your agency with tools built for real-estate professionals.</p>
            <Link className="button button--terracotta" href="/register/agency">Join Casaura free</Link>
          </div>
          <div className="agency-cta__preview" aria-hidden="true">
            <div className="mini-sidebar"><span>Casaura</span><i /><i /><i /><i /></div>
            <div className="mini-dashboard"><strong>Overview</strong><div className="mini-stats"><i /><i /><i /></div><div className="mini-rows"><i /><i /><i /></div></div>
          </div>
        </section>
      </main>
      <SiteFooter />
    </>
  );
}
