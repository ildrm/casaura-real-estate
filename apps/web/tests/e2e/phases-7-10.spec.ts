import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page, type TestInfo } from "@playwright/test";
import { registerVerifiedAgencyOwner } from "./support/identity";

type ReleaseFixture = { published_listing_ids: string[]; draft_listing_id: string };

function createReleaseFixture(agencyId: string): ReleaseFixture {
  const apiRoot = path.resolve(process.cwd(), "../api");
  return JSON.parse(execFileSync("php", ["tests/e2e/phase-release-fixture.php", agencyId], {
    cwd: apiRoot,
    encoding: "utf8",
    env: process.env,
  })) as ReleaseFixture;
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function visual(page: Page, testInfo: TestInfo, surface: "integrations" | "assistant" | "billing"): Promise<void> {
  const output = process.env.PLAYWRIGHT_VISUAL_OUTPUT;
  if (!output) return;
  const mobile = testInfo.project.name.includes("mobile");
  if (surface === "billing" && mobile) return;
  mkdirSync(output, { recursive: true });
  await page.screenshot({ path: path.join(output, `${surface}-${mobile ? "mobile" : "desktop"}.png`), fullPage: true });
}

test("phases 7–10 complete provider, marketplace, grounded AI, and billing browser flows", async ({ page }, testInfo) => {
  const project = testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase();
  const suffix = `${project}.${Date.now()}`;
  const { agencyId } = await registerVerifiedAgencyOwner(page, {
    agencyName: `Casaura Release Realty ${suffix}`,
    email: `release.${suffix}@example.com`,
    ownerName: "Release Owner",
  });
  const fixture = createReleaseFixture(agencyId);
  if (testInfo.project.name.includes("mobile")) await page.setViewportSize({ width: 390, height: 844 });

  await page.goto("/agency/integrations");
  await expect(page.getByRole("heading", { name: "Data integrations" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Austin licensed feed" })).toBeVisible();
  await expect(page.getByText("Mapping required")).toHaveCount(0);
  await expect(page.getByRole("button", { name: /Mapping/ })).toContainText("v1");
  await expect(page.getByText("PROVIDER_CURRENCY_UNSUPPORTED")).toHaveCount(0);
  await page.getByRole("button", { name: /Errors/ }).click();
  await expect(page.getByText("PROVIDER_CURRENCY_UNSUPPORTED")).toBeVisible();
  await page.getByRole("button", { name: /Mapping/ }).click();
  await expectNoHorizontalOverflow(page);
  await visual(page, testInfo, "integrations");
  await page.getByRole("button", { name: "Disable" }).click();
  await expect(page.getByText("Connection disabled.")).toBeVisible();
  await page.getByRole("button", { name: "Enable" }).click();
  await expect(page.getByText("Connection enabled.")).toBeVisible();

  await page.goto("/agency/billing");
  await expect(page.getByRole("heading", { name: "Billing & promotion" })).toBeVisible();
  await expect(page.getByRole("heading", { name: /Professional/ })).toBeVisible();
  await expect(page.getByText("INV-RELEASE-001")).toBeVisible();
  await expect(page.getByText("Paid placement; organic ranking is unchanged.").first()).toBeVisible();
  await expectNoHorizontalOverflow(page);
  await visual(page, testInfo, "billing");

  await page.goto("/collections");
  await page.getByLabel("New collection").fill("Release shortlist");
  await page.getByRole("button", { name: "Create collection" }).click();
  await expect(page.getByText("Private collection created.")).toBeVisible();

  await page.goto("/assistant");
  await page.getByLabel("Ask about published homes").fill("Find 3 bedroom houses in Austin under $900,000");
  await page.getByRole("button", { name: "Search with assistant" }).click();
  await expect(page.getByText("Filters proposed—not applied")).toBeVisible();
  await expect(page.getByRole("link", { name: "Confirm filters in search" })).toHaveAttribute("href", /beds=3/);
  for (const title of ["Release Garden Residence", "Canopy Courtyard House"]) {
    await expect(page.getByRole("heading", { name: title }).first()).toBeVisible();
    await page.getByLabel(`Add ${title} to collection`).first().selectOption({ label: "Release shortlist" });
    await page.getByLabel(`Add ${title} to comparison`).first().check();
  }
  await expectNoHorizontalOverflow(page);
  await visual(page, testInfo, "assistant");
  await page.getByRole("link", { name: /Compare homes \(2\)/ }).click();
  await expect(page.getByRole("heading", { name: "Compare homes" })).toBeVisible();
  await expect(page.getByRole("columnheader", { name: "Release Garden Residence" })).toBeVisible();
  await page.getByRole("button", { name: "Save comparison" }).click();
  await expect(page.getByText("Comparison saved to your private history.")).toBeVisible();
  await expect(page.getByText("Private comparison history (1)")).toBeVisible();

  await page.goto(`/agency/properties/${fixture.draft_listing_id}/assistant`);
  await expect(page.getByRole("heading", { name: "Grounded listing writer" })).toBeVisible();
  await page.getByRole("button", { name: "Generate suggestion" }).click();
  await expect(page.getByText("Suggestion generated from current listing facts. The draft has not changed.")).toBeVisible();
  await page.getByRole("button", { name: "Apply 2 selected fields" }).click();
  await expect(page.getByText("Applied title and description after a fresh listing-version check.")).toBeVisible();

  await page.goto(`/compare?ids=${fixture.published_listing_ids.join(",")}`);
  await expect(page.getByRole("heading", { name: "Compare homes" })).toBeVisible();
  await page.goto("/market");
  await expect(page.getByRole("heading", { name: "Market insights" })).toBeVisible();
  await expect(page.getByText(/Privacy-bounded aggregates/)).toBeVisible();
  await expectNoHorizontalOverflow(page);
});
