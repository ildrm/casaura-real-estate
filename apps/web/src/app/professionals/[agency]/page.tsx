import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SiteFooter } from "@/components/layout/site-footer";
import { SiteHeader } from "@/components/layout/site-header";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { StorefrontNewsletter } from "@/components/storefront/storefront-newsletter";
import { Icon } from "@/components/ui/icon";
import { publicApiQuery, type ApiError } from "@/lib/api-client";
import type { PublicStorefront } from "@/lib/operations-types";

type StorefrontParams = { params: Promise<{ agency: string }> };

export const metadata: Metadata = { title: "Agency storefront", description: "Meet a local agency and browse its published properties." };

export default async function ProfessionalPage({ params }: StorefrontParams) {
  const { agency } = await params;
  let storefront: PublicStorefront;
  try {
    storefront = (await publicApiQuery<{ data: PublicStorefront }>(`/api/v1/public/agencies/${encodeURIComponent(agency)}`)).data;
  } catch (caught) {
    const error = caught as ApiError;
    if (error.code === "NOT_FOUND") notFound();
    throw new Error(error.message);
  }

  const { agency: profile } = storefront;
  return <><SiteHeader /><main className="storefront-page">
    <section className="storefront-hero"><div className="shell"><div className="storefront-identity"><span className="storefront-monogram">{initials(profile.name)}</span><div><p>{profile.verified ? <><Icon name="shield" /> Verified Casaura professional</> : "Local real-estate professional"}</p><h1>{profile.name}</h1><span>{profile.short_description ?? "Local expertise, published property details, and direct inquiry handling."}</span></div></div><div className="storefront-hero__rail"><span><i /> Storefront active</span><span>{storefront.listings.length} published {storefront.listings.length === 1 ? "property" : "properties"}</span><span>{profile.timezone}</span></div></div></section>
    <div className="shell storefront-layout">
      <div className="storefront-main">
        <section className="storefront-story" aria-labelledby="story-title"><p>About the agency</p><h2 id="story-title">A clearer route from neighborhood expertise to your next move.</h2><div>{(profile.description ?? profile.short_description ?? "This agency has not published a longer introduction yet.").split("\n").map((paragraph) => <p key={paragraph}>{paragraph}</p>)}</div></section>
        <section className="storefront-team" aria-labelledby="team-title"><header><p>People behind the work</p><h2 id="team-title">Meet the team</h2></header>{storefront.team.length ? <div>{storefront.team.map((member, index) => <article key={member.id}><span className={index % 2 ? "is-terracotta" : undefined}>{initials(member.name)}</span><h3>{member.name}</h3><p>{member.job_title ?? "Agency professional"}</p></article>)}</div> : <p className="storefront-empty">The agency has not published team members yet.</p>}</section>
        <section className="storefront-collection" aria-labelledby="collection-title"><header><div><p>Published collection</p><h2 id="collection-title">Homes represented by {profile.name}</h2></div><span>{storefront.listings.length} available</span></header>{storefront.listings.length ? <div>{storefront.listings.map((listing) => <PublicPropertyCard listing={listing} key={listing.id} />)}</div> : <p className="storefront-empty">There are no published properties in this storefront right now.</p>}</section>
      </div>
      <aside className="storefront-contact"><section><span className="team-monogram">{initials(profile.name)}</span><h2>Contact {profile.name}</h2>{profile.phone ? <a href={`tel:${profile.phone}`}><Icon name="user" /> {profile.phone}</a> : null}{profile.website ? <a href={profile.website} rel="noreferrer"><Icon name="building" /> Visit agency website</a> : null}<small>Property inquiries are submitted from an individual published listing.</small></section><section className="storefront-hours"><h2>Weekly hours</h2>{storefront.opening_hours.length ? <dl>{storefront.opening_hours.map((hour) => <div key={hour.weekday}><dt>{weekdays[hour.weekday] ?? `Day ${hour.weekday}`}</dt><dd>{hour.closed ? "Closed" : `${shortTime(hour.opens_at)}–${shortTime(hour.closes_at)}`}</dd></div>)}</dl> : <p>Hours have not been published.</p>}<small>Times shown in {profile.timezone}.</small></section></aside>
    </div>
    <div className="shell"><StorefrontNewsletter agencyId={profile.id} agencyName={profile.name} /></div>
  </main><SiteFooter /></>;
}

const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
function initials(name: string): string { return name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase(); }
function shortTime(value: string | null): string { if (!value) return "—"; const [hour, minute] = value.split(":").map(Number); const suffix = hour >= 12 ? "pm" : "am"; return `${hour % 12 || 12}${minute ? `:${String(minute).padStart(2, "0")}` : ""}${suffix}`; }
