"use client";

import Link from "next/link";
import { useEffect, useMemo, useState, type FormEvent } from "react";
import { BrandMark } from "@/components/brand/logo";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { AdminHealth, AdminRole, AdminSetting, AuditLog, FeatureFlag, ModerationCase, Permission } from "@/lib/operations-types";

type AdminData = {
  health: AdminHealth | null;
  cases: ModerationCase[];
  settings: AdminSetting[];
  flags: FeatureFlag[];
  roles: AdminRole[];
  permissions: Permission[];
  audits: AuditLog[];
};

type AdminSection = "moderation" | "settings" | "flags" | "roles" | "audits";
const sections: Array<{ id: AdminSection; label: string }> = [
  { id: "moderation", label: "Moderation" }, { id: "settings", label: "Settings" }, { id: "flags", label: "Feature flags" }, { id: "roles", label: "Roles & permissions" }, { id: "audits", label: "Audit history" },
];

export function AdminConsole() {
  const [data, setData] = useState<AdminData | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [active, setActive] = useState<AdminSection>("moderation");
  const [access, setAccess] = useState<Record<AdminSection | "health", boolean>>({ moderation: false, settings: false, flags: false, roles: false, audits: false, health: false });

  async function load() {
    try {
      await apiQuery("/api/v1/me");
      const [health, cases, settings, flags, roles, audits] = await Promise.all([
        safeAdminQuery<AdminHealth>("/api/v1/admin/health"), safeAdminQuery<ModerationCase[]>("/api/v1/admin/moderation-cases"),
        safeAdminQuery<AdminSetting[]>("/api/v1/admin/settings"), safeAdminQuery<FeatureFlag[]>("/api/v1/admin/feature-flags"),
        safeAdminQuery<{ roles: AdminRole[]; permissions: Permission[] }>("/api/v1/admin/roles"), safeAdminQuery<AuditLog[]>("/api/v1/admin/audit-logs"),
      ]);
      const nextAccess = { health: Boolean(health.value), moderation: Boolean(cases.value), settings: Boolean(settings.value), flags: Boolean(flags.value), roles: Boolean(roles.value), audits: Boolean(audits.value) };
      const firstAllowed = sections.find((section) => nextAccess[section.id])?.id;
      if (!firstAllowed) { setError(health.error ?? cases.error ?? settings.error ?? flags.error ?? roles.error ?? audits.error ?? { code: "PLATFORM_PERMISSION_DENIED", message: "You do not have permission to access platform operations." }); return; }
      setAccess(nextAccess);
      setActive((current) => nextAccess[current] ? current : firstAllowed);
      setData({ health: health.value, cases: cases.value ?? [], settings: settings.value ?? [], flags: flags.value ?? [], roles: roles.value?.roles ?? [], permissions: roles.value?.permissions ?? [], audits: audits.value ?? [] }); setError(null);
    } catch (caught) { setError(caught as ApiError); }
  }

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, []);

  async function moderate(item: ModerationCase, status: ModerationCase["status"]) {
    const outcome = status === "resolved" ? "Reviewed and resolved by platform operations" : undefined;
    try {
      const response = await apiMutation<{ data: ModerationCase }>(`/api/v1/admin/moderation-cases/${item.id}`, { version: item.version, status, outcome }, { method: "PATCH" });
      setData((current) => current ? { ...current, cases: current.cases.map((entry) => entry.id === response.data.id ? response.data : entry) } : current); setNotice(`Moderation case moved to ${status}.`);
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function saveSetting(setting: AdminSetting, rawValue: string) {
    let value: unknown = rawValue;
    try { value = JSON.parse(rawValue); } catch { /* Plain strings are valid non-secret values. */ }
    try {
      const response = await apiMutation<{ data: AdminSetting }>(`/api/v1/admin/settings/${encodeURIComponent(setting.namespace)}/${encodeURIComponent(setting.key)}`, { value, version: setting.version }, { method: "PATCH" });
      setData((current) => current ? { ...current, settings: current.settings.map((item) => item.id === response.data.id ? response.data : item) } : current); setNotice("Setting saved and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function addOverride(event: FormEvent<HTMLFormElement>, flag: FeatureFlag) {
    event.preventDefault(); const form = event.currentTarget; const values = new FormData(form);
    try {
      const scope = String(values.get("scope_type") ?? "agency") as "global" | "agency";
      const startsAt = String(values.get("starts_at") ?? ""); const endsAt = String(values.get("ends_at") ?? "");
      const body: Record<string, unknown> = { scope_type: scope, enabled: values.get("enabled") === "true", ...(scope === "agency" ? { scope_id: String(values.get("scope_id") ?? "") } : {}), ...(startsAt ? { starts_at: new Date(startsAt).toISOString() } : {}), ...(endsAt ? { ends_at: new Date(endsAt).toISOString() } : {}) };
      const response = await apiMutation<{ data: FeatureFlag["overrides"][number] }>(`/api/v1/admin/feature-flags/${flag.id}/overrides`, body, { method: "PUT" });
      setData((current) => current ? { ...current, flags: current.flags.map((item) => item.id === flag.id ? { ...item, overrides: [...item.overrides.filter((override) => override.id !== response.data.id), response.data] } : item) } : current); form.reset(); setNotice("Feature override saved and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function deleteOverride(flag: FeatureFlag, overrideId: string) {
    try {
      await apiMutation<void>(`/api/v1/admin/feature-flags/${flag.id}/overrides/${overrideId}`, {}, { method: "DELETE" });
      setData((current) => current ? { ...current, flags: current.flags.map((item) => item.id === flag.id ? { ...item, overrides: item.overrides.filter((override) => override.id !== overrideId) } : item) } : current); setNotice("Feature override removed and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function createRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const name = String(values.get("name") ?? "");
    try {
      const response = await apiMutation<{ data: AdminRole }>("/api/v1/admin/roles", { name, slug: name.toLowerCase().trim().replace(/[^a-z0-9]+/g, "_"), scope: String(values.get("scope") ?? "agency"), permissions: values.getAll("permissions").map(String) });
      setData((current) => current ? { ...current, roles: [...current.roles, response.data] } : current); form.reset(); setNotice("Custom role created and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function updateRole(role: AdminRole, name: string, permissions: string[]) {
    try {
      const response = await apiMutation<{ data: AdminRole }>(`/api/v1/admin/roles/${role.id}`, { name, permissions }, { method: "PATCH" });
      setData((current) => current ? { ...current, roles: current.roles.map((item) => item.id === response.data.id ? response.data : item) } : current); setNotice("Custom role and permissions synchronized and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  async function deleteRole(role: AdminRole) {
    try {
      await apiMutation<void>(`/api/v1/admin/roles/${role.id}`, {}, { method: "DELETE" });
      setData((current) => current ? { ...current, roles: current.roles.filter((item) => item.id !== role.id) } : current); setNotice("Custom role deleted and audited.");
    } catch (caught) { setNotice((caught as ApiError).message); }
  }

  if (error) {
    const denied = error.code === "FORBIDDEN" || error.code === "UNAUTHORIZED" || error.code.endsWith("PERMISSION_DENIED");
    return <div className="admin-shell"><AdminHeader /><main className="admin-state" role="alert"><Icon name={denied ? "shield" : "settings"} /><p>Platform operations</p><h1>{denied ? "Platform access required" : "Operations console unavailable"}</h1><span>{denied ? "Your account is signed in, but it does not hold the platform permissions required for this console. Agency permissions do not grant platform access." : error.message}</span><div><Link className="button button--primary" href="/agency/dashboard">Return to agency workspace</Link>{!denied ? <button className="button button--outline" type="button" onClick={() => void load()}>Try again</button> : null}</div></main></div>;
  }

  return <div className="admin-shell"><AdminHeader />{!data ? <main className="admin-state" role="status"><span className="inline-spinner" /><p>Platform operations</p><h1>Checking access and system state</h1><span>Loading health, moderation, configuration, roles, and audit projections.</span></main> : <main className="admin-canvas">
    <header className="admin-heading"><div><p>Platform operations · redacted by design</p><h1>Casaura control room</h1></div><button className="button button--outline" type="button" onClick={() => void load()}><Icon name="sparkle" /> Refresh projections</button></header>
    {data.health ? <HealthStrip health={data.health} /> : <p className="admin-partial-note"><Icon name="shield" /> Component health requires audit access.</p>}
    <nav className="admin-tabs" aria-label="Operations sections">{sections.filter((section) => access[section.id]).map((section) => <button type="button" key={section.id} className={active === section.id ? "is-active" : undefined} aria-pressed={active === section.id} onClick={() => setActive(section.id)}>{section.label}</button>)}</nav>
    <p className="async-notice" role="status" aria-live="polite">{notice}</p>
    {active === "moderation" ? <ModerationSection cases={data.cases} onUpdate={moderate} /> : null}
    {active === "settings" ? <SettingsSection settings={data.settings} onSave={saveSetting} /> : null}
    {active === "flags" ? <FlagsSection flags={data.flags} onSubmit={addOverride} onDelete={deleteOverride} /> : null}
    {active === "roles" ? <RolesSection roles={data.roles} permissions={data.permissions} onSubmit={createRole} onUpdate={updateRole} onDelete={deleteRole} /> : null}
    {active === "audits" ? <AuditsSection audits={data.audits} /> : null}
  </main>}</div>;
}

function AdminHeader() { return <header className="admin-topbar"><BrandMark /><span>Platform operations</span><Link href="/agency/dashboard">Agency workspace</Link></header>; }

function HealthStrip({ health }: { health: AdminHealth }) {
  return <section className="admin-health" aria-label="Component health"><div><i className={`signal-dot signal-dot--${health.status}`} /><span><strong>System {health.status}</strong><small>Checked {formatDate(health.checked_at)}</small></span></div>{Object.entries(health.components).map(([name, component]) => <div key={name}><i className={`signal-dot signal-dot--${component.status}`} /><span><strong>{titleCase(name)}</strong><small>{component.status}{component.backlog === undefined ? "" : ` · ${component.backlog} queued`}</small></span></div>)}</section>;
}

function ModerationSection({ cases, onUpdate }: { cases: ModerationCase[]; onUpdate: (item: ModerationCase, status: ModerationCase["status"]) => void }) {
  return <section className="admin-panel" aria-labelledby="moderation-title"><header><div><p>Content safety queue</p><h2 id="moderation-title">Moderation cases</h2></div><span>{cases.filter((item) => ["open", "reviewing"].includes(item.status)).length} active</span></header>{cases.length ? <div className="admin-records">{cases.map((item) => <article key={item.id}><i className={`signal-dot signal-dot--${item.status}`} /><div><strong>{titleCase(item.category)}</strong><span>{item.target_type} · {item.target_id.slice(0, 12)}</span>{item.report?.details ? <span>{item.report.details}</span> : null}<small>Reported {formatDate(item.report?.created_at ?? item.created_at)} · v{item.version}</small></div><em className={`status-chip status-chip--${item.status}`}>{item.status}</em><div>{item.status === "open" ? <><button type="button" onClick={() => onUpdate(item, "reviewing")}>Start review</button><button type="button" onClick={() => onUpdate(item, "dismissed")}>Dismiss</button></> : item.status === "reviewing" ? <><button type="button" onClick={() => onUpdate(item, "resolved")}>Resolve</button><button type="button" onClick={() => onUpdate(item, "dismissed")}>Dismiss</button></> : <span>{item.outcome ?? "Closed"}</span>}</div></article>)}</div> : <p className="admin-empty">There are no moderation cases in this projection.</p>}</section>;
}

function SettingsSection({ settings, onSave }: { settings: AdminSetting[]; onSave: (setting: AdminSetting, value: string) => void }) {
  return <section className="admin-panel" aria-labelledby="settings-title"><header><div><p>Non-secret configuration only</p><h2 id="settings-title">Platform settings</h2></div><span>{settings.filter((item) => !item.secret).length} editable</span></header><div className="settings-list">{settings.map((setting) => <SettingRow setting={setting} onSave={onSave} key={setting.id} />)}</div></section>;
}

function SettingRow({ setting, onSave }: { setting: AdminSetting; onSave: (setting: AdminSetting, value: string) => void }) {
  const [value, setValue] = useState(() => setting.secret ? "" : typeof setting.value === "string" ? setting.value : JSON.stringify(setting.value));
  return <form onSubmit={(event) => { event.preventDefault(); onSave(setting, value); }}><label htmlFor={`setting-${setting.id}`}><strong>{setting.namespace}.{setting.key}</strong><small>{setting.secret ? "Managed by deployment secret manager · value redacted" : `Version ${setting.version}`}</small></label><input id={`setting-${setting.id}`} value={value} disabled={setting.secret} onChange={(event) => setValue(event.target.value)} aria-label={`Value for ${setting.namespace}.${setting.key}`} /><button type="submit" disabled={setting.secret}>Save</button></form>;
}

function FlagsSection({ flags, onSubmit, onDelete }: { flags: FeatureFlag[]; onSubmit: (event: FormEvent<HTMLFormElement>, flag: FeatureFlag) => void; onDelete: (flag: FeatureFlag, overrideId: string) => void }) {
  return <section className="admin-panel" aria-labelledby="flags-title"><header><div><p>Explicit scoped rollout</p><h2 id="flags-title">Feature flags</h2></div><span>{flags.length} flags</span></header><div className="flag-list">{flags.map((flag) => <article key={flag.id}><div><i className={`signal-dot${flag.default_enabled ? "" : " is-muted"}`} /><span><strong>{flag.key}</strong><small>{flag.description ?? "No description"} · default {flag.default_enabled ? "on" : "off"}</small></span></div>{flag.overrides.length ? <ul>{flag.overrides.map((override) => <li key={override.id}><span>{override.scope_type}: {override.scope_id ?? "all agencies"} → <b>{override.enabled ? "on" : "off"}{override.starts_at || override.ends_at ? ` · ${override.starts_at ? formatDate(override.starts_at) : "now"}–${override.ends_at ? formatDate(override.ends_at) : "open"}` : ""}</b></span><button type="button" onClick={() => onDelete(flag, override.id)}>Delete</button></li>)}</ul> : <p>No scoped overrides.</p>}<FlagOverrideForm flag={flag} onSubmit={onSubmit} /></article>)}</div></section>;
}

function FlagOverrideForm({ flag, onSubmit }: { flag: FeatureFlag; onSubmit: (event: FormEvent<HTMLFormElement>, flag: FeatureFlag) => void }) {
  const [scope, setScope] = useState<"agency" | "global">("agency");
  return <form className="flag-override-form" onSubmit={(event) => void onSubmit(event, flag)}><label>Scope<select name="scope_type" value={scope} onChange={(event) => setScope(event.target.value as "agency" | "global")}><option value="agency">Agency</option><option value="global">Global</option></select></label>{scope === "agency" ? <label>Agency ID<input name="scope_id" type="text" pattern="[0-9a-fA-F-]{36}" required placeholder="00000000-0000-0000-0000-000000000000" /></label> : null}<label>State<select name="enabled" defaultValue="true"><option value="true">Enabled</option><option value="false">Disabled</option></select></label><label>Starts <small>Optional</small><input name="starts_at" type="datetime-local" /></label><label>Ends <small>Optional</small><input name="ends_at" type="datetime-local" /></label><button type="submit">Save override</button></form>;
}

function RolesSection({ roles, permissions, onSubmit, onUpdate, onDelete }: { roles: AdminRole[]; permissions: Permission[]; onSubmit: (event: FormEvent<HTMLFormElement>) => void; onUpdate: (role: AdminRole, name: string, permissions: string[]) => void; onDelete: (role: AdminRole) => void }) {
  const grouped = useMemo(() => permissions.reduce((groups, permission) => {
    const current = groups.get(permission.group) ?? [];
    groups.set(permission.group, [...current, permission]);
    return groups;
  }, new Map<string, Permission[]>()), [permissions]);
  return <section className="admin-panel" aria-labelledby="roles-title"><header><div><p>Least-privilege role templates</p><h2 id="roles-title">Roles & permissions</h2></div><span>{roles.length} roles</span></header><div className="roles-layout"><div className="role-list">{roles.map((role) => <RoleRow role={role} permissions={permissions} onUpdate={onUpdate} onDelete={onDelete} key={role.id} />)}</div><form className="role-form" onSubmit={(event) => void onSubmit(event)}><h3>Create custom role</h3><label>Name<input name="name" minLength={2} maxLength={120} required /></label><label>Scope<select name="scope" defaultValue="agency"><option value="agency">Agency</option><option value="platform">Platform</option></select></label>{[...grouped.entries()].map(([group, items]) => <fieldset key={group}><legend>{titleCase(group)}</legend>{items.map((permission) => <label key={permission.id}><input type="checkbox" name="permissions" value={permission.name} /> {permission.name}</label>)}</fieldset>)}<button className="button button--primary" type="submit">Create role</button></form></div></section>;
}

const agencyPermissionGroups = new Set(["agency", "property", "listing", "lead", "analytics", "billing", "integration", "audit"]);

function RoleRow({ role, permissions, onUpdate, onDelete }: { role: AdminRole; permissions: Permission[]; onUpdate: (role: AdminRole, name: string, permissions: string[]) => void; onDelete: (role: AdminRole) => void }) {
  const [name, setName] = useState(role.name);
  const [selected, setSelected] = useState(role.permissions);
  const available = role.scope === "agency" ? permissions.filter((permission) => agencyPermissionGroups.has(permission.group)) : permissions;
  return <article><div><strong>{role.name}</strong><span>{role.scope}{role.system ? " · protected system role" : " · custom"}</span></div><p>{role.permissions.length ? role.permissions.join(" · ") : "No permissions"}</p>{!role.system ? <form className="role-actions" onSubmit={(event) => { event.preventDefault(); onUpdate(role, name, selected); }}><label className="sr-only" htmlFor={`role-${role.id}`}>Role name</label><input id={`role-${role.id}`} value={name} minLength={2} maxLength={120} onChange={(event) => setName(event.target.value)} /><button type="submit">Synchronize</button><button type="button" onClick={() => onDelete(role)}>Delete</button><fieldset className="role-permission-editor"><legend>Custom permissions</legend><div>{available.map((permission) => <label key={permission.id}><input type="checkbox" checked={selected.includes(permission.name)} onChange={(event) => setSelected((current) => event.target.checked ? [...new Set([...current, permission.name])] : current.filter((name) => name !== permission.name))} /> <span>{permission.name}</span></label>)}</div></fieldset></form> : null}</article>;
}

function AuditsSection({ audits }: { audits: AuditLog[] }) {
  return <section className="admin-panel" aria-labelledby="audits-title"><header><div><p>Redacted evidence trail</p><h2 id="audits-title">Audit history</h2></div><span>{audits.length} records</span></header>{audits.length ? <ol className="audit-list">{audits.map((audit) => <li key={audit.id}><i className="signal-dot" /><div><strong>{audit.action}</strong><span>{audit.entity_type ?? "system"}{audit.entity_id ? ` · ${audit.entity_id.slice(0, 12)}` : ""}</span><small>Changed fields: {audit.changed_fields.length ? audit.changed_fields.join(", ") : "none recorded"}</small></div><time dateTime={audit.created_at}>{formatDate(audit.created_at)}</time><code>{audit.request_id ?? "request unavailable"}</code></li>)}</ol> : <p className="admin-empty">No audit events match the current projection.</p>}</section>;
}

function titleCase(value: string): string { return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function formatDate(value: string): string { return new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)); }
async function safeAdminQuery<T>(path: string): Promise<{ value: T | null; error: ApiError | null }> {
  try { return { value: (await apiQuery<{ data: T }>(path)).data, error: null }; }
  catch (caught) { return { value: null, error: caught as ApiError }; }
}
