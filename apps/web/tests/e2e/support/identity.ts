import { execFileSync } from "node:child_process";
import { createHmac } from "node:crypto";
import path from "node:path";
import { expect, type Page } from "@playwright/test";

export const E2E_API_URL = process.env.PLAYWRIGHT_API_URL ?? "http://127.0.0.1:8000";

type Registration = {
  agencyName: string;
  email: string;
  ownerName: string;
  password?: string;
};

function verificationLink(email: string): string {
  const apiRoot = path.resolve(process.cwd(), "../api");

  return execFileSync("php", ["tests/e2e/verification-link.php", email], {
    cwd: apiRoot,
    encoding: "utf8",
    env: process.env,
  }).trim();
}

function base32Decode(secret: string): Buffer {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let buffer = 0;
  let bits = 0;
  const bytes: number[] = [];

  for (const character of secret.replace(/[\s-]+/g, "").toUpperCase()) {
    const value = alphabet.indexOf(character);
    if (value < 0) throw new Error("The MFA setup returned an invalid Base32 secret.");
    buffer = (buffer << 5) | value;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((buffer >> bits) & 0xff);
    }
  }

  return Buffer.from(bytes);
}

function currentTotp(secret: string): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));
  const digest = createHmac("sha1", base32Decode(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const value = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

  return value.toString().padStart(6, "0");
}

export async function registerVerifiedAgencyOwner(page: Page, registration: Registration): Promise<{ agencyId: string }> {
  const password = registration.password ?? "SecurePass123!";

  await page.goto("/register/agency");
  await page.getByLabel("Agency name").fill(registration.agencyName);
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Your name").fill(registration.ownerName);
  await page.getByLabel("Work email").fill(registration.email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByLabel("Confirm password").fill(password);
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "Create agency workspace" }).click();

  await expect(page).toHaveURL(/\/verify-email$/);
  await expect(page.getByRole("heading", { name: "Verify your email." })).toBeVisible();
  await page.goto(verificationLink(registration.email));
  await expect(page).toHaveURL(/\/mfa\/setup$/, { timeout: 15_000 });

  await page.getByLabel("Current password").fill(password);
  await page.getByRole("button", { name: "Start MFA setup" }).click();
  const secret = (await page.locator(".mfa-secret").textContent())?.trim();
  expect(secret).toBeTruthy();
  await page.getByLabel("Six-digit code").fill(currentTotp(secret as string));
  await page.getByRole("button", { name: "Enable MFA" }).click();
  await expect(page.getByRole("heading", { name: "Save your recovery codes" })).toBeVisible();
  await page.getByRole("button", { name: "I saved these codes" }).click();

  await expect(page).toHaveURL(/\/agency\/dashboard$/);
  const agencyId = await page.evaluate(() => localStorage.getItem("casaura.activeAgencyId"));
  expect(agencyId).not.toBeNull();

  return { agencyId: agencyId as string };
}
