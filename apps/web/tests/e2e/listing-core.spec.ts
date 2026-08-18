import { expect, test } from "@playwright/test";

test("agency owner creates and resumes an autosaved listing draft", async ({ page }, testInfo) => {
  const project = testInfo.project.name.replace(/[^a-z0-9]/gi, "").toLowerCase();
  const email = `listing.${project}.${Date.now()}@example.com`;

  await page.goto("/register/agency");
  await page.getByLabel("Agency name").fill("Harbor & Field Realty");
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Your name").fill("Avery Morgan");
  await page.getByLabel("Work email").fill(email);
  await page.locator('input[name="password"]').fill("SecurePass123!");
  await page.getByLabel("Confirm password").fill("SecurePass123!");
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "Create agency workspace" }).click();
  await expect(page).toHaveURL(/\/agency\/dashboard$/);

  await page.getByRole("link", { name: "Properties", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Properties" })).toBeVisible();
  await page.getByRole("link", { name: "Add property" }).click();
  await expect(page.getByRole("heading", { name: "Let’s start with the essentials" })).toBeVisible();

  await page.getByLabel("Property type").selectOption("house");
  await page.getByLabel("Price").fill("1395000");
  await page.getByLabel("Reference ID").fill(`QA-${Date.now()}`);
  await page.getByLabel("Bedrooms").fill("3");
  await page.getByLabel("Bathrooms").fill("2.5");
  await page.getByLabel("Interior area").fill("2120");
  await page.getByLabel("Short title").fill("Modern family home in Oakridge");
  await page.getByLabel("Description").fill(
    "A carefully maintained family home with generous natural light, thoughtful updates, and convenient access to parks, schools, and everyday amenities.",
  );

  await expect(page.getByText(/Draft saved/)).toBeVisible({ timeout: 10_000 });
  await expect(page).toHaveURL(/\/agency\/properties\/[0-9a-f-]+\/edit$/);
  await page.reload();
  await expect(page.getByLabel("Short title")).toHaveValue("Modern family home in Oakridge");
  await expect(page.locator("body")).not.toHaveCSS("overflow-x", "scroll");
});
