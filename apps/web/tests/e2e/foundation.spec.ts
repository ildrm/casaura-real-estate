import { expect, test } from "@playwright/test";
import { registerVerifiedAgencyOwner } from "./support/identity";

test("consumer search preserves intent and query", async ({ page }) => {
  await page.goto("/");
  await page.getByLabel("Search by location or address").fill("Austin, TX");
  await page.getByRole("button", { name: "Search homes" }).click();

  await expect(page).toHaveURL(/\/search\?intent=buy&q=Austin/);
  await expect(page.getByRole("heading", { name: /Homes near/ })).toBeVisible();
});

test("agency owner can register and enter the tenant workspace", async ({ page }, testInfo) => {
  const project = testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase();
  const agencyName = `Tenant Truth Realty ${project} ${Date.now()}`;
  const email = `maya.${project}.${Date.now()}@example.com`;

  await registerVerifiedAgencyOwner(page, {
    agencyName,
    email,
    ownerName: "Maya Patel",
  });

  await expect(page.getByRole("heading", { name: "Agency overview" })).toBeVisible();
  await expect(page.getByText("Maya Patel", { exact: true })).toHaveCount(1);
  await expect(page.getByRole("heading", { name: "Workspace protected" })).toBeVisible();
  await expect(page.getByText(/80% complete/)).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => localStorage.getItem("casaura.activeAgencyId"))).not.toBeNull();

  await page.goto("/agency/profile");
  await expect(page.getByLabel("Public agency name")).toHaveValue(agencyName);
  await expect(page.getByLabel("Short description")).toHaveValue("");
  await expect(page.getByLabel("Website")).toHaveValue("");
  await page.getByLabel("Short description").fill("A profile loaded from and saved to the active tenant.");
  await page.getByRole("button", { name: "Save agency profile" }).click();
  await expect(page.getByText("Agency profile saved.")).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("Public agency name")).toHaveValue(agencyName);
  await expect(page.getByLabel("Short description")).toHaveValue("A profile loaded from and saved to the active tenant.");
});
