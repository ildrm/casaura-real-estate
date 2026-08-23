import Form from "next/form";
import Link from "next/link";
import { Icon } from "@/components/ui/icon";

const intents = ["Buy", "Rent", "Land", "Commercial"];

export function HomeSearch() {
  return (
    <div className="home-search">
      <div className="home-search__tabs" role="tablist" aria-label="Listing type">
        {intents.map((intent, index) => (
          <Link
            aria-selected={index === 0}
            className={index === 0 ? "is-active" : undefined}
            href={`/search?intent=${intent.toLowerCase()}`}
            key={intent}
            role="tab"
          >
            {intent}
          </Link>
        ))}
      </div>
      <Form action="/search" className="home-search__form">
        <input type="hidden" name="intent" value="buy" />
        <label className="search-field" htmlFor="hero-search">
          <Icon name="search" />
          <span className="sr-only">Search by location or address</span>
          <input
            id="hero-search"
            name="q"
            placeholder="City, neighborhood or address"
            autoComplete="street-address"
          />
        </label>
        <div className="home-search__actions">
          <button className="button button--primary" type="submit">
            Search homes
          </button>
          <Link className="conversational-link" href="/assistant">
            <Icon name="message" />
            Try conversational search
          </Link>
        </div>
      </Form>
    </div>
  );
}
