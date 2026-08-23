import { expect, test, type Page } from "@playwright/test";
import { E2E_API_URL, registerVerifiedAgencyOwner } from "./support/identity";

const API_URL = E2E_API_URL;

async function registerAgency(page: Page, suffix: string): Promise<{ agencyId: string; slug: string; name: string }> {
  const name = `Signal House International Neighborhood Property Advisory ${suffix}`;
  const { agencyId } = await registerVerifiedAgencyOwner(page, {
    agencyName: name,
    email: `signal.${suffix}@example.com`,
    ownerName: "Avery Morgan",
  });
  const agency = await page.evaluate(async ({ apiUrl, id }) => {
    const response = await fetch(`${apiUrl}/api/v1/agency`, { credentials: "include", headers: { Accept: "application/json", "Agency-ID": id } });
    if (!response.ok) throw new Error(await response.text());
    return (await response.json()) as { data: { slug: string } };
  }, { apiUrl: API_URL, id: agencyId });
  return { agencyId, slug: agency.data.slug, name };
}

async function expectNoHorizontalOverflow(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function expectNoHorizontalOverflowAt(page: Page, width: 375 | 390) {
  await page.setViewportSize({ width, height: 812 });
  await expectNoHorizontalOverflow(page);
}

async function expectMinimumHeight(locator: ReturnType<Page["locator"]>, minimum: number) {
  await expect.poll(async () => (await locator.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(minimum);
}

async function expectMinimumFontSize(locator: ReturnType<Page["locator"]>, minimum: number) {
  await expect.poll(() => locator.evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize))).toBeGreaterThanOrEqual(minimum);
}

test("phases 4–6 routes expose API-backed, keyboard-operable states", async ({ page }, testInfo) => {
  const suffix = `${testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase()}.${Date.now()}`;
  const agency = await registerAgency(page, suffix);

  await page.goto("/agency/leads");
  await expect(page.getByRole("heading", { name: "Leads & collaboration" })).toBeVisible();
  await expect(page.getByText("New property inquiries will enter this queue automatically.")).toBeVisible();
  const refresh = page.getByRole("button", { name: "Refresh" });
  await refresh.focus();
  await expect(refresh).toBeFocused();
  await expectNoHorizontalOverflow(page);

  await page.goto("/agency/growth");
  await expect(page.getByRole("heading", { name: "Agency growth" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Weekly opening hours" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Newsletters are not enabled" })).toBeVisible();
  const dateRange = page.getByRole("button", { name: "Apply range" });
  await dateRange.focus();
  await expect(dateRange).toBeFocused();
  await expectNoHorizontalOverflow(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await expectMinimumHeight(dateRange, 44);
  await expectMinimumFontSize(page.locator(".range-selector label").first(), 13);

  await page.goto(`/professionals/${agency.slug}`);
  await expect(page.getByRole("heading", { name: agency.name, level: 1 })).toBeVisible();
  await expect(page.getByText("There are no published properties in this storefront right now.")).toBeVisible();
  await expectNoHorizontalOverflowAt(page, 375);
  await expectNoHorizontalOverflowAt(page, 390);
  const newsletterConsent = page.getByLabel(/I consent to receive this agency’s newsletter/);
  await expectMinimumHeight(newsletterConsent.locator(".."), 44);
  await expectMinimumFontSize(newsletterConsent.locator(".."), 13);
  await expect.poll(async () => (await newsletterConsent.boundingBox())?.width ?? 0).toBeGreaterThanOrEqual(20);

  await page.goto("/admin");
  await expect(page.getByRole("heading", { name: "Platform access required" })).toBeVisible();
  await expect(page.getByText("Agency permissions do not grant platform access.")).toBeVisible();
  await expectNoHorizontalOverflow(page);
});
