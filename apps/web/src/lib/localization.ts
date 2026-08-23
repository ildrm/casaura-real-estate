import { publicConfig } from "@/lib/public-config";

export function formatMoney(amountMinor: number, currency: string = publicConfig.currency, maximumFractionDigits = 0): string {
  return new Intl.NumberFormat(publicConfig.locale, {
    style: "currency",
    currency,
    maximumFractionDigits,
  }).format(amountMinor / 100);
}

export function formatDate(value: string | Date, options: Intl.DateTimeFormatOptions): string {
  return new Intl.DateTimeFormat(publicConfig.locale, options).format(new Date(value));
}

export function formatArea(area: { sqm: number; sq_ft: number }): string {
  const value = publicConfig.areaUnit === "sqm" ? area.sqm : area.sq_ft;
  return `${new Intl.NumberFormat(publicConfig.locale, { maximumFractionDigits: 0 }).format(value)} ${publicConfig.areaUnit === "sqm" ? "m²" : "sq ft"}`;
}
