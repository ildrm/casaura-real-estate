export type ListingPreview = {
  id: string;
  price: string;
  address: string;
  city: string;
  beds: number;
  baths: number;
  area: string;
  image: string;
  imageAlt: string;
  status: string;
  age: string;
};

export type AgencyPreview = {
  name: string;
  city: string;
  rating: string;
  initials: string;
  tone: "forest" | "terracotta" | "charcoal" | "sage";
};

const previewListings: ListingPreview[] = [
  {
    id: "oakridge",
    price: "$1,395,000",
    address: "241 Oakridge Drive",
    city: "Austin, TX 78704",
    beds: 3,
    baths: 2.5,
    area: "2,120 sq ft",
    image: "/images/properties/oakridge-kitchen.png",
    imageAlt: "Bright oak kitchen opening onto a sunlit living room",
    status: "Verified",
    age: "3d ago",
  },
  {
    id: "maple",
    price: "$875,000",
    address: "157 Maple Street",
    city: "Denver, CO 80206",
    beds: 4,
    baths: 3,
    area: "2,450 sq ft",
    image: "/images/properties/maple-townhouse.png",
    imageAlt: "Renovated red-brick townhouse on a leafy city street",
    status: "Verified",
    age: "1d ago",
  },
  {
    id: "ocean",
    price: "$699,000",
    address: "321 Ocean Avenue, Unit 5B",
    city: "Santa Monica, CA 90402",
    beds: 2,
    baths: 2,
    area: "1,340 sq ft",
    image: "/images/properties/ocean-apartment.png",
    imageAlt: "Calm modern apartment living room with an ocean view",
    status: "Price reduced",
    age: "2d ago",
  },
  {
    id: "pine",
    price: "$1,150,000",
    address: "88 Pine Lane",
    city: "Portland, OR 97205",
    beds: 4,
    baths: 3,
    area: "2,680 sq ft",
    image: "/images/properties/hero-home.png",
    imageAlt: "Contemporary stone and charcoal home at golden hour",
    status: "Open house",
    age: "5d ago",
  },
];

const previewAgencies: AgencyPreview[] = [
  { name: "Greenway Realty", city: "Austin, TX", rating: "4.9", initials: "GR", tone: "forest" },
  { name: "Pine & Peak", city: "Denver, CO", rating: "4.8", initials: "PP", tone: "terracotta" },
  { name: "Coastal Collective", city: "Santa Monica, CA", rating: "4.9", initials: "CC", tone: "charcoal" },
  { name: "Cityside Homes", city: "Nashville, TN", rating: "4.8", initials: "CH", tone: "sage" },
];

export async function getMarketplacePreview(): Promise<{
  listings: ListingPreview[];
  agencies: AgencyPreview[];
}> {
  const previewEnabled =
    process.env.NODE_ENV === "development" ||
    process.env.NEXT_PUBLIC_ENABLE_DEMO_DATA === "true";

  if (!previewEnabled) {
    return { listings: [], agencies: [] };
  }

  return { listings: previewListings, agencies: previewAgencies };
}

export async function getSearchPreview(query: string): Promise<ListingPreview[]> {
  const { listings } = await getMarketplacePreview();
  const normalized = query.trim().toLowerCase();

  if (!normalized) return listings;

  return listings.filter((listing) =>
    `${listing.address} ${listing.city}`.toLowerCase().includes(normalized),
  );
}
