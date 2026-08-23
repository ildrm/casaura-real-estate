# Spec: P1 verified identity, recovery, MFA, session revocation, and consent

**Author:** Codex  
**Date:** 2026-08-22  
**Status:** Approved  
**Reviewer:** User, via approval to execute the production release plan  
**Related plan:** `PRODUCTION_RELEASE_PLAN.md`, P1.1

## Context

Casaura can register, log in, and log out users, but it currently treats unverified email addresses as operational identities, has no password-recovery endpoints, has no second factor for privileged roles, and does not invalidate established credentials after sensitive security changes. The agency registration UI also displays a consent checkbox but the API neither validates nor persists evidence of consent.

The GA policy for this slice is agency-first onboarding. Customer self-registration remains disabled by seed until the consumer verification, consent, abuse, export, and deletion lifecycle is released. Agency owners and trusted platform operators require time-based one-time-password MFA. Delivery uses Laravel's configured mail transport so the provider can be selected at deployment without changing domain behavior.

## Functional Requirements

- FR-1: Agency and customer registration MUST require accepted consent using the configured legal-document version, and MUST store the subject, purpose, version, source, legal-text snapshot/hash, and consent timestamp atomically with registration.
- FR-2: New registrations MUST begin with an unverified email and MUST send a verification notification without placing verification secrets in logs or API responses.
- FR-3: An authenticated but unverified user MUST be allowed to inspect their principal, log out, and request verification delivery, but MUST be denied other authenticated product routes with HTTP 403 and `EMAIL_VERIFICATION_REQUIRED`.
- FR-4: A valid signed email-verification request MUST mark the matching user verified and audit the transition. Invalid, expired, mismatched, or tampered requests MUST fail closed.
- FR-5: Password-recovery requests MUST return the same accepted response for known and unknown addresses, and known active users MUST receive Laravel's short-lived reset notification.
- FR-6: Password reset MUST require a valid single-use token, strong confirmed password, and matching normalized email. Success MUST rotate the password and security version, revoke personal access tokens and database sessions, delete the reset token, and append an audit event.
- FR-7: Every authenticated request MUST compare the current persisted security version with the established session or persisted token version. A mismatch MUST return HTTP 401 with `SESSION_REVOKED`.
- FR-8: MFA MUST be required for an active user who holds an active trusted system role with slug `agency_owner`, `platform_administrator`, `super_administrator`, `moderator`, or `support_administrator` in an active agency.
- FR-9: A verified authenticated user MUST confirm their current password to start MFA setup, receive a newly generated Base32 TOTP secret/provisioning URI, and confirm a valid RFC 6238 code before MFA becomes active.
- FR-10: MFA confirmation MUST generate one-time recovery codes, expose their plaintext only in that confirmation response, and persist only non-reversible hashes.
- FR-11: Password login for a user with required confirmed MFA MUST establish only a five-minute pending challenge, rotate the session, and return HTTP 202 with `MFA_REQUIRED`; it MUST NOT establish an authenticated principal until a valid TOTP or unused recovery code succeeds.
- FR-12: A TOTP code MUST allow one clock step of skew and MUST NOT be accepted twice for the same or earlier time step. A recovery code MUST be consumed atomically and MUST NOT be reusable.
- FR-13: A privileged user without confirmed MFA MUST receive HTTP 403 `MFA_SETUP_REQUIRED` on protected product routes. A privileged authenticated session or personal token without a current MFA assertion MUST receive HTTP 403 `MFA_REQUIRED`.
- FR-14: Enabling/disabling MFA, consuming a recovery code, completing password recovery, email verification, login challenge success, and security-version revocation MUST emit audit events without storing passwords, TOTP secrets, reset tokens, challenge codes, or recovery codes.
- FR-15: Seed data MUST keep `customer_registration` disabled for GA. Customer registration code remains gated and MUST inherit the same consent and verification behavior if explicitly enabled later.

## Non-Functional Requirements

- NFR-S1: Authentication and recovery MUST default to deny on missing, malformed, expired, replayed, or mismatched state.
- NFR-S2: Secret comparisons MUST use framework password hashing or constant-time comparison; TOTP secrets MUST be encrypted at rest and recovery/reset tokens MUST be hashed at rest.
- NFR-S3: Recovery and verification responses MUST not disclose whether an email address exists, role membership, MFA enrollment, or tenant membership.
- NFR-S4: Security-sensitive mutations MUST execute in transactions with a row lock when replay or concurrent use could otherwise succeed twice.
- NFR-R1: Email delivery MAY be queued by deployment configuration, but persistence and audit state MUST remain deterministic if delivery fails.
- NFR-C1: Existing successful principal response fields remain compatible; additive identity-security fields MAY be introduced.
- NFR-P1: MFA checks MUST use indexed membership/role predicates and MUST not load all users or roles.
- NFR-T1: Tests MUST use the real password broker, signed URL verification, TOTP implementation, session/token revocation path, and notification fakes; no test-only authentication bypass may prove the security behavior.

## Acceptance Criteria

### AC-1: Registration captures consent and starts verification (FR-1, FR-2)
Given agency registration is enabled and a request accepts the currently configured legal version
When `POST /api/v1/auth/register-agency` succeeds
Then user, agency, membership, subscription, and consent evidence are committed
And `email_verified_at` is null
And a verification notification is sent
And no token or legal secret is present in the response

### AC-2: Missing or stale consent fails atomically (FR-1)
Given consent is absent, false, or names an obsolete legal version
When registration is attempted
Then the response is 422 `VALIDATION_FAILED`
And no user, agency, membership, subscription, or consent row is created

### AC-3: Unverified identity is contained (FR-3)
Given a registered unverified owner with a valid session
When the owner requests `GET /api/v1/me`
Then the existing principal response is returned
When the owner calls `POST /api/v1/listings`
Then the response is 403 `EMAIL_VERIFICATION_REQUIRED`
And no listing state is created

### AC-4: Signed verification is authenticated and auditable (FR-4)
Given an unverified authenticated user and a valid unexpired signed verification URL for that user
When the URL is requested
Then the user becomes verified and one `user.email_verified` audit event exists
Given a signature for another user, an expired signature, or a tampered hash
When verification is attempted
Then verification does not occur

### AC-5: Recovery request is non-enumerating (FR-5, NFR-S3)
Given one known active email and one unknown email
When each calls `POST /api/v1/auth/forgot-password`
Then both responses are 202 with the same response body
And only the known user receives a reset notification

### AC-6: Reset is single-use and revokes credentials (FR-6, FR-7)
Given a user with a valid reset token, active sessions, and personal access tokens
When `POST /api/v1/auth/reset-password` succeeds
Then the new password works, security version increments, sessions/tokens are removed, and the reset token cannot be reused
And `user.password_reset` is audited without secret material

### AC-7: Privileged route requires MFA enrollment (FR-8, FR-13)
Given a verified active agency owner without confirmed MFA
When the owner invokes a protected tenant mutation
Then the response is 403 `MFA_SETUP_REQUIRED`
Given an ordinary verified consumer without privileged membership
When the consumer invokes an allowed account route
Then MFA enrollment is not required

### AC-8: MFA setup follows password and TOTP proof (FR-9, FR-10, FR-14)
Given a verified authenticated user
When setup is requested with an incorrect password
Then no secret is changed
When setup is requested with the correct password and then confirmed with a current TOTP code
Then MFA is confirmed and recovery codes are returned exactly once
And only encrypted secret material and recovery-code hashes exist at rest
And the security transition is audited without any secret or code

### AC-9: Login requires and completes the second factor (FR-11, FR-13)
Given a privileged user with confirmed MFA
When the correct password is submitted to `POST /api/v1/auth/login`
Then the response is 202 `MFA_REQUIRED` and no authenticated principal is established
When a correct challenge is submitted to `POST /api/v1/auth/mfa/challenge` within five minutes
Then authentication succeeds, the session rotates, and subsequent protected requests carry a current MFA assertion

### AC-10: MFA credentials resist replay (FR-12, NFR-S4)
Given one current TOTP and one recovery code
When either is accepted
Then the same TOTP time step or recovery code is rejected on a second challenge
And concurrent recovery-code consumption can produce at most one success

### AC-11: Security version invalidates established access (FR-7)
Given an established authenticated session or persisted personal token at security version N
When the user's security version becomes N+1
Then the next authenticated request returns 401 `SESSION_REVOKED`
And the stale credential is removed or logged out

### AC-12: Customer onboarding is contained (FR-15)
Given a fresh seeded database
When `POST /api/v1/auth/register` is attempted
Then the response is 403 `FEATURE_DISABLED`
And no customer is persisted

## Edge Cases and Error Scenarios

- EC-1: Mail transport throws after registration commits -> keep the account unverified, report delivery failure safely, and allow rate-limited resend.
- EC-2: Verification URL user ID differs from the authenticated principal -> return 403 without updating either account.
- EC-3: Forgot-password email differs only by case -> normalize before broker lookup while keeping the generic response.
- EC-4: A suspended user requests recovery -> return the generic accepted response but send nothing.
- EC-5: Reset token is malformed, expired, consumed, or paired with another email -> return the same invalid-reset validation class without changing credentials.
- EC-6: MFA setup is restarted before confirmation -> replace the unconfirmed secret and invalidate the previous one.
- EC-7: A six-digit code has leading zeroes -> preserve it as a string.
- EC-8: Server time is one 30-second step ahead or behind -> accept once; beyond that window -> reject.
- EC-9: A recovery code differs by case or punctuation -> normalize only the documented separators/case before hashing.
- EC-10: A user loses their last privileged membership -> MFA may remain enrolled, but role policy no longer requires it.
- EC-11: A privileged user attempts to disable MFA while still privileged -> deny with `MFA_REQUIRED_ROLE`.
- EC-12: An old session lacks a security-version assertion -> fail closed and require login again.

## API Contracts

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/register-agency`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/forgot-password`
- `POST /api/v1/auth/reset-password`
- `POST /api/v1/auth/mfa/challenge`
- `POST /api/v1/auth/email/verification-notification`
- `GET /api/v1/auth/email/verify/{id}/{hash}`
- `POST /api/v1/auth/mfa/setup`
- `POST /api/v1/auth/mfa/confirm`
- `DELETE /api/v1/auth/mfa`

```typescript
interface PendingIdentityResponse {
  error: {
    code: "MFA_REQUIRED" | "MFA_SETUP_REQUIRED" | "EMAIL_VERIFICATION_REQUIRED";
    message: string;
    fields: Record<string, never>;
    request_id: string | null;
  };
}

interface RegistrationConsent {
  consent: true;
  consent_version: string;
}

interface MfaChallengeRequest {
  code: string;
}
```

The exact generic recovery response is HTTP 202 with `data.message = "If an eligible account exists, recovery instructions will be sent."`.

## Data Models

| Entity | Added/used fields | Rule |
| --- | --- | --- |
| `users` | email verification, `security_version`, encrypted MFA secret, confirmation/last-step timestamps, recovery-code hashes | Current persisted security and MFA state is authoritative. |
| `consent_records` | user, optional agency, purpose, version, source, legal text/hash, consent/revocation timestamps | Evidence is immutable except additive revocation. |
| `password_reset_tokens` | normalized email, hashed token, creation time | Framework broker expiry/throttle and deletion semantics apply. |
| `sessions` | user, security version | Stale sessions are revoked. |
| `personal_access_tokens` | token hash, abilities, security version | Persisted tokens inherit revocation semantics. |
| `agency_members`, `roles`, `member_roles` | active membership and trusted system-role identity | Determines whether MFA is required. |
| `audit_logs` | actor/entity/action/redacted before/after | Stores evidence, never authentication secrets. |

## Out of Scope

- OS-1: Selecting a transactional mail vendor; deployment supplies a production mail transport.
- OS-2: SMS, passkeys/WebAuthn, social login, SAML, SCIM, and third-party identity providers.
- OS-3: Consumer account export/deletion and abuse lifecycle; customer self-registration remains off.
- OS-4: Legal advice or choosing the operating jurisdiction/legal entity; production startup will require configured legal destinations and version.
- OS-5: Team invitation tokens and ownership invariants, which are specified separately under P1.3.
- OS-6: A staffed lost-factor support workflow; the operational runbook will define recovery-code and verified support escalation procedures.
