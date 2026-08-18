import Image from "next/image";
import Link from "next/link";
import type { ListingPreview } from "@/lib/marketplace-data";
import { Icon } from "@/components/ui/icon";

export function PropertyCard({ listing, priority = false }: { listing: ListingPreview; priority?: boolean }) {
  return (
    <article className="property-card">
      <div className="property-card__media">
        <Image
          src={listing.image}
          alt={listing.imageAlt}
          fill
          priority={priority}
          sizes="(max-width: 720px) 82vw, (max-width: 1100px) 46vw, 25vw"
        />
        <Link
          className="icon-button property-card__save"
          href={`/sign-in?next=/property/${listing.id}`}
          aria-label={`Save ${listing.address}`}
        >
          <Icon name="heart" />
        </Link>
      </div>
      <Link className="property-card__body" href={`/search?q=${encodeURIComponent(listing.address)}`}>
        <h3>{listing.price}</h3>
        <div className="property-card__facts" aria-label="Property facts">
          <span>{listing.beds} bd</span>
          <span>{listing.baths} ba</span>
          <span>{listing.area}</span>
        </div>
        <p>{listing.address}</p>
        <p className="muted">{listing.city}</p>
        <div className="property-card__footer">
          <span className="verified"><Icon name="shield" /> {listing.status}</span>
          <span>{listing.age}</span>
        </div>
      </Link>
    </article>
  );
}
