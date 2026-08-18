import { expect, test } from "@playwright/test";

test("consumer search preserves intent and query", async ({ page }) => {
  await page.goto("/");
  await page.getByLabel("Search by location or address").fill("Austin, TX");
  await page.getByRole("button", { name: "Search homes" }).click();

  await expect(page).toHaveURL(/\/search\?intent=buy&q=Austin/);
  await expect(page.getByRole("heading", { name: /Homes near/ })).toBeVisible();
});

test("agency owner can register and enter the tenant workspace", async ({ page }, testInfo) => {
  const project = testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase();
  const email = `maya.${project}.${Date.now()}@example.com`;

  await page.goto("/register/agency");
  await page.getByLabel("Agency name").fill("Greenway Realty QA");
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Your name").fill("Maya Patel");
  await page.getByLabel("Work email").fill(email);
  await page.locator('input[name="password"]').fill("SecurePass123!");
  await page.getByLabel("Confirm password").fill("SecurePass123!");
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "Create agency workspace" }).click();

  await expect(page).toHaveURL(/\/agency\/dashboard$/);
  await expect(page.getByRole("heading", { name: "Good morning, Maya" })).toBeVisible();
  await expect.poll(() => page.evaluate(() => localStorage.getItem("casaura.activeAgencyId"))).not.toBeNull();
});
