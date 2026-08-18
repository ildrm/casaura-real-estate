import { expect, test, type Page } from "@playwright/test";

const API_URL = "http://localhost:8000";
const tinyPng = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=";

async function apiJson<T>(page: Page, path: string, method: "POST" | "PUT", body: Record<string, unknown>, agencyId?: string): Promise<T> {
  return page.evaluate(async ({ apiUrl, path, method, body, agencyId }) => {
    await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } });
    const xsrf = document.cookie.split("; ").find((entry) => entry.startsWith("XSRF-TOKEN="))?.split("=").slice(1).join("=");
    const response = await fetch(`${apiUrl}${path}`, {
      method,
      credentials: "include",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(agencyId ? { "Agency-ID": agencyId } : {}),
        ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
      },
      body: JSON.stringify(body),
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(JSON.stringify(payload));
    return payload;
  }, { apiUrl: API_URL, path, method, body, agencyId }) as Promise<T>;
}

async function uploadImage(page: Page, listingId: string, agencyId: string, position: number): Promise<void> {
  await page.evaluate(async ({ apiUrl, listingId, agencyId, position, tinyPng }) => {
    await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } });
    const xsrf = document.cookie.split("; ").find((entry) => entry.startsWith("XSRF-TOKEN="))?.split("=").slice(1).join("=");
    const bytes = Uint8Array.from(atob(tinyPng), (character) => character.charCodeAt(0));
    const form = new FormData();
    form.append("file", new Blob([bytes], { type: "image/png" }), `oakridge-${position}.png`);
    form.append("alt_text", `Oakridge property view ${position}`);
    const response = await fetch(`${apiUrl}/api/v1/listings/${listingId}/media`, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "Agency-ID": agencyId,
        "Idempotency-Key": `marketplace-${listingId}-${position}`,
        ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
      },
      body: form,
    });
    if (!response.ok) throw new Error(await response.text());
  }, { apiUrl: API_URL, listingId, agencyId, position, tinyPng });
}

test("published inventory flows from search and map to detail, favorite, and account", async ({ page }, testInfo) => {
  const suffix = `${testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase()}.${Date.now()}`;
  const title = `Oakridge market home ${suffix}`;

  await page.goto("/register/agency");
  await page.getByLabel("Agency name").fill(`Marketplace Realty ${suffix}`);
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Your name").fill("Morgan Lee");
  await page.getByLabel("Work email").fill(`marketplace.${suffix}@example.com`);
  await page.locator('input[name="password"]').fill("SecurePass123!");
  await page.getByLabel("Confirm password").fill("SecurePass123!");
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "Create agency workspace" }).click();
  await page.waitForURL(/\/agency\/dashboard$/);
  const agencyId = await page.evaluate(() => localStorage.getItem("casaura.activeAgencyId"));
  expect(agencyId).not.toBeNull();

  const created = await apiJson<{ data: { id: string } }>(page, "/api/v1/listings", "POST", {
    reference: `MARKET-${Date.now()}`,
    intent: "sale",
    property_type_slug: "house",
    title,
    description: "A carefully maintained family home with generous natural light, thoughtful updates, and convenient access to parks, schools, shops, and everyday amenities.",
    price: { amount_minor: 139500000, currency: "USD" },
    bedrooms: 3,
    bathrooms: 2.5,
    interior_area: { value: 2120, unit: "sq_ft" },
    address: { line_1: "241 Oakridge Drive", locality: "Oakridge", region: "OR", postal_code: "97463", country_code: "US", latitude: 43.7485, longitude: -122.4617 },
    features: { year_built: 2018, parking_spaces: 2 },
    amenity_slugs: ["garden", "garage"],
  }, agencyId as string);
  for (let position = 1; position <= 5; position += 1) await uploadImage(page, created.data.id, agencyId as string, position);
  await apiJson(page, `/api/v1/listings/${created.data.id}/submit`, "POST", {}, agencyId as string);
  await apiJson(page, `/api/v1/listings/${created.data.id}/publish`, "POST", {}, agencyId as string);

  await page.goto(`/search?q=${encodeURIComponent(title)}&intent=buy`);
  await expect(page.getByRole("heading", { name: title, exact: true })).toBeVisible();
  if (testInfo.project.name.includes("mobile")) {
    await page.getByRole("button", { name: "Map", exact: true }).click();
  }
  await expect(page.getByRole("button", { name: `Select ${title} on map` })).toBeVisible();
  if (testInfo.project.name.includes("mobile")) {
    await page.getByRole("button", { name: "List", exact: true }).click();
  }
  await page.getByRole("heading", { name: title, exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`/property/.+-${created.data.id}$`));
  await expect(page.getByRole("heading", { name: title, exact: true, level: 1 })).toBeVisible();
  await expect(page.getByText("Approximate location")).toBeVisible();

  const favorite = page.getByRole("button", { name: "Favorite" });
  await favorite.click();
  await expect(favorite).toHaveAttribute("aria-pressed", "true");
  await page.reload();
  await expect(page.getByRole("button", { name: "Favorite" })).toHaveAttribute("aria-pressed", "true");

  await page.goto("/account");
  await expect(page.getByRole("heading", { name: "Your property search" })).toBeVisible();
  await expect(page.getByRole("heading", { name: title, exact: true })).toBeVisible();
  await expect(page.locator("body")).not.toHaveCSS("overflow-x", "scroll");
});
