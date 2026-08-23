type AreaUnit = "sq_ft" | "sqm";

function origin(name: string, configured: string | undefined, fallback: string): string {
  const raw = configured || fallback;
  let parsed: URL;
  try {
    parsed = new URL(raw);
  } catch {
    throw new Error(`${name} must be an absolute URL.`);
  }

  const production = process.env.NODE_ENV === "production";
  const local = ["localhost", "127.0.0.1", "::1"].includes(parsed.hostname);
  if (production && (parsed.protocol !== "https:" || local || parsed.username || parsed.password)) {
    throw new Error(`${name} must be a credential-free HTTPS production origin.`);
  }

  return parsed.origin;
}

function requiredChoice<T extends string>(name: string, configured: string | undefined, fallback: T, allowed?: readonly T[]): T {
  const value = (configured || fallback) as T;
  if (process.env.NODE_ENV === "production" && !configured) {
    throw new Error(`${name} is required for a production build.`);
  }
  if (allowed && !allowed.includes(value)) {
    throw new Error(`${name} has an unsupported value.`);
  }
  return value;
}

function requiredText(name: string, configured: string | undefined, fallback: string): string {
  const value = (configured || fallback).trim();
  if (process.env.NODE_ENV === "production" && (!configured || /replace|\.test/i.test(value))) {
    throw new Error(`${name} must contain an approved production value.`);
  }
  return value;
}

function supportEmail(configured: string | undefined): string {
  const value = requiredText("NEXT_PUBLIC_SUPPORT_EMAIL", configured, "support@casaura.test").toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) throw new Error("NEXT_PUBLIC_SUPPORT_EMAIL must be a valid email address.");
  return value;
}

export const publicConfig = Object.freeze({
  apiUrl: origin("NEXT_PUBLIC_API_URL", process.env.NEXT_PUBLIC_API_URL, "http://localhost:8000"),
  siteUrl: origin("NEXT_PUBLIC_SITE_URL", process.env.NEXT_PUBLIC_SITE_URL, "http://localhost:3000"),
  locale: requiredChoice("NEXT_PUBLIC_APP_LOCALE", process.env.NEXT_PUBLIC_APP_LOCALE, "en-US"),
  currency: requiredChoice("NEXT_PUBLIC_DEFAULT_CURRENCY", process.env.NEXT_PUBLIC_DEFAULT_CURRENCY, "USD"),
  areaUnit: requiredChoice<AreaUnit>("NEXT_PUBLIC_AREA_UNIT", process.env.NEXT_PUBLIC_AREA_UNIT, "sq_ft", ["sq_ft", "sqm"]),
  countryCode: requiredChoice("NEXT_PUBLIC_COUNTRY_CODE", process.env.NEXT_PUBLIC_COUNTRY_CODE, "US").toUpperCase(),
  legalVersion: requiredText("NEXT_PUBLIC_LEGAL_DOCUMENT_VERSION", process.env.NEXT_PUBLIC_LEGAL_DOCUMENT_VERSION, "2026-08-22"),
  operatorName: requiredText("NEXT_PUBLIC_OPERATOR_NAME", process.env.NEXT_PUBLIC_OPERATOR_NAME, "Casaura development operator"),
  operatorJurisdiction: requiredText("NEXT_PUBLIC_OPERATOR_JURISDICTION", process.env.NEXT_PUBLIC_OPERATOR_JURISDICTION, "Development environment"),
  operatorAddress: requiredText("NEXT_PUBLIC_OPERATOR_ADDRESS", process.env.NEXT_PUBLIC_OPERATOR_ADDRESS, "Not for production use"),
  supportEmail: supportEmail(process.env.NEXT_PUBLIC_SUPPORT_EMAIL),
  demoData: process.env.NEXT_PUBLIC_ENABLE_DEMO_DATA === "true",
});

if (process.env.NODE_ENV === "production" && publicConfig.demoData) {
  throw new Error("NEXT_PUBLIC_ENABLE_DEMO_DATA must be false in production.");
}
