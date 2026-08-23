import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Casaura",
    short_name: "Casaura",
    description: "Property discovery and agency collaboration.",
    start_url: "/",
    display: "standalone",
    background_color: "#ffffff",
    theme_color: "#073d2e",
    icons: [
      {
        src: "/favicon.ico",
        sizes: "any",
        type: "image/x-icon",
      },
    ],
  };
}
