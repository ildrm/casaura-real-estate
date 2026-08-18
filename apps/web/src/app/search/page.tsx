import type { Metadata } from "next";
import Form from "next/form";
import Link from "next/link";
import { PropertyCard } from "@/components/home/property-card";
import { SiteHeader } from "@/components/layout/site-header";
import { Icon } from "@/components/ui/icon";
import { getSearchPreview } from "@/lib/marketplace-data";

export const metadata: Metadata = {
  title: "Search homes",
  description: "Search homes by place, price, and property type.",
  robots: { index: false, follow: true },
};

export default async function SearchPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string; intent?: string; type?: string }>;
}) {
  const params = await searchParams;
  const query = params.q ?? "";
  const intent = params.intent ?? "buy";
  const listings = await getSearchPreview(query);

  return (
    <>
      <SiteHeader />
      <main className="search-page">
        <section className="search-toolbar" aria-label="Property search controls">
          <Form action="/search" className="search-toolbar__form">
            <label className="search-field" htmlFor="search-page-query">
              <Icon name="search" />
              <span className="sr-only">Search location or address</span>
              <input id="search-page-query" name="q" defaultValue={query} placeholder="City, neighborhood or address" />
            </label>
            <label>
              <span className="sr-only">Listing intent</span>
              <select name="intent" defaultValue={intent}>
                <option value="buy">Buy</option>
                <option value="rent">Rent</option>
                <option value="land">Land</option>
                <option value="commercial">Commercial</option>
              </select>
            </label>
            <label>
              <span className="sr-only">Property type</span>
              <select name="type" defaultValue={params.type ?? "all"}>
                <option value="all">All property types</option>
                <option value="house">House</option>
                <option value="apartment">Apartment</option>
                <option value="townhouse">Townhouse</option>
              </select>
            </label>
            <button className="button button--primary" type="submit">Search</button>
          </Form>
        </section>
        <section className="search-results shell" aria-labelledby="results-title">
          <div className="search-results__heading">
            <div>
              <h1 id="results-title">{query ? `Homes near “${query}”` : "Homes for you"}</h1>
              <p>{listings.length} development preview {listings.length === 1 ? "result" : "results"}</p>
            </div>
            <span className="search-results__mode"><Icon name="home" /> List view</span>
          </div>
          {listings.length > 0 ? (
            <div className="search-results__grid">
              {listings.map((listing) => <PropertyCard listing={listing} key={listing.id} />)}
            </div>
          ) : (
            <div className="empty-state">
              <h2>No matching homes yet.</h2>
              <p>Try a broader location. Once search indexing is connected, saved zero-result demand can help agencies fill this gap without exposing personal history.</p>
              <Link className="button button--outline" href="/search">Clear search</Link>
            </div>
          )}
        </section>
      </main>
    </>
  );
}
