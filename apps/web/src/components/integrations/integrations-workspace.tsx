"use client";

import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import { formatDate } from "@/lib/localization";
import type { DuplicateCandidate, FieldMapping, ImportError, ProviderConnection, SyncJob } from "@/lib/release-types";

const defaultFields: Record<string, string> = {
  external_id: "ListingKey",
  reference: "ListingId",
  status: "StandardStatus",
  transaction_type: "TransactionType",
  property_type: "PropertyType",
  property_subtype: "PropertySubType",
  price: "ListPrice",
  currency: "Currency",
  bedrooms: "BedroomsTotal",
  bathrooms: "BathroomsTotalDecimal",
  area: "LivingArea",
  area_unit: "LivingAreaUnits",
  line_1: "UnparsedAddress",
  locality: "City",
  region: "StateOrProvince",
  postal_code: "PostalCode",
  country_code: "Country",
  description: "PublicRemarks",
  modified_at: "ModificationTimestamp",
};

type ConnectionDraft = {
  name: string;
  base_url: string;
  token_url: string;
  client_id: string;
  secret_reference: string;
  attribution: string;
  photos: boolean;
};

const emptyDraft: ConnectionDraft = {
  name: "RESO Web API",
  base_url: "",
  token_url: "",
  client_id: "",
  secret_reference: "",
  attribution: "",
  photos: false,
};

type ProviderMetadata = {
  data_dictionary_version: string;
  resources: Array<{ name: string; fields: Array<{ name: string; type: string }> }>;
};

export function IntegrationsWorkspace() {
  const agencyIdRef = useRef<string | null>(null);
  const [connections, setConnections] = useState<ProviderConnection[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [mappings, setMappings] = useState<FieldMapping[]>([]);
  const [syncs, setSyncs] = useState<SyncJob[]>([]);
  const [errors, setErrors] = useState<ImportError[]>([]);
  const [duplicates, setDuplicates] = useState<DuplicateCandidate[]>([]);
  const [mappingDraft, setMappingDraft] = useState(defaultFields);
  const [connectionDraft, setConnectionDraft] = useState(emptyDraft);
  const [metadata, setMetadata] = useState<ProviderMetadata | null>(null);
  const [showConnectionForm, setShowConnectionForm] = useState(false);
  const [tab, setTab] = useState<"mapping" | "activity" | "duplicates" | "errors">("mapping");
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const selected = connections.find((connection) => connection.id === selectedId) ?? null;

  const loadConnectionDetail = useCallback(async (connectionId: string, agencyId: string) => {
    const [mappingResponse, syncResponse] = await Promise.all([
      apiQuery<{ data: FieldMapping[] }>(`/api/v1/integrations/connections/${connectionId}/mappings`, agencyId),
      apiQuery<{ data: SyncJob[] }>(`/api/v1/integrations/connections/${connectionId}/syncs`, agencyId),
    ]);
    setMappings(mappingResponse.data);
    setSyncs(syncResponse.data);
    const activeMapping = mappingResponse.data[0];
    setMappingDraft(activeMapping?.fields ?? defaultFields);
  }, []);

  const load = useCallback(async () => {
    const agencyId = activeAgencyId();
    agencyIdRef.current = agencyId;
    if (!agencyId) {
      setError({ code: "AGENCY_REQUIRED", message: "Select an agency or sign in again to manage integrations." });
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const [connectionResponse, errorResponse, duplicateResponse] = await Promise.all([
        apiQuery<{ data: ProviderConnection[] }>("/api/v1/integrations/connections", agencyId),
        apiQuery<{ data: ImportError[] }>("/api/v1/integrations/import-errors", agencyId),
        apiQuery<{ data: DuplicateCandidate[] }>("/api/v1/integrations/duplicate-candidates", agencyId),
      ]);
      setConnections(connectionResponse.data);
      setErrors(errorResponse.data);
      setDuplicates(duplicateResponse.data);
      const connectionId = selectedId && connectionResponse.data.some((item) => item.id === selectedId)
        ? selectedId
        : connectionResponse.data[0]?.id ?? null;
      setSelectedId(connectionId);
      if (connectionId) await loadConnectionDetail(connectionId, agencyId);
      else { setMappings([]); setSyncs([]); setMappingDraft(defaultFields); }
    } catch (caught) {
      setError(caught as ApiError);
    } finally {
      setLoading(false);
    }
  }, [loadConnectionDetail, selectedId]);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    if (!selectedId || !agencyIdRef.current || !["queued", "running"].includes(syncs[0]?.status ?? "")) return;
    const timer = window.setInterval(() => {
      if (agencyIdRef.current) void loadConnectionDetail(selectedId, agencyIdRef.current);
    }, 2000);
    return () => window.clearInterval(timer);
  }, [loadConnectionDetail, selectedId, syncs]);

  const latestSync = syncs[0] ?? null;
  const imported = latestSync?.records_imported ?? 0;
  const pendingDuplicates = duplicates.filter((candidate) => candidate.status === "pending");
  const unresolvedErrors = errors.filter((item) => !item.resolved_at);
  const mappingRows = Object.entries(mappingDraft);

  async function selectConnection(connectionId: string) {
    if (!agencyIdRef.current) return;
    setSelectedId(connectionId);
    setMetadata(null);
    setWorking(true);
    try { await loadConnectionDetail(connectionId, agencyIdRef.current); }
    catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function createConnection(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!agencyIdRef.current) return;
    setWorking(true); setNotice(null); setError(null);
    try {
      const response = await apiMutation<{ data: ProviderConnection }>("/api/v1/integrations/connections", {
        name: connectionDraft.name,
        provider: "reso",
        base_url: connectionDraft.base_url,
        token_url: connectionDraft.token_url,
        client_id: connectionDraft.client_id,
        secret_reference: connectionDraft.secret_reference,
        resources: ["Property"],
        rights: { display: true, photos: connectionDraft.photos, attribution: connectionDraft.attribution },
        data_dictionary_version: "2.0",
      }, { agencyId: agencyIdRef.current });
      setConnections((current) => [response.data, ...current]);
      setSelectedId(response.data.id);
      setMappings([]); setSyncs([]); setMappingDraft(defaultFields);
      setShowConnectionForm(false); setConnectionDraft(emptyDraft);
      setNotice("The RESO connection was saved. Add a mapping before the first sync.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function discoverMetadata() {
    if (!agencyIdRef.current || !selected) return;
    setWorking(true); setError(null);
    try {
      const response = await apiQuery<{ data: ProviderMetadata }>(`/api/v1/integrations/connections/${selected.id}/metadata`, agencyIdRef.current);
      setMetadata(response.data);
      setNotice(`Loaded ${response.data.resources.length} RESO resources from data dictionary ${response.data.data_dictionary_version}.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function toggleConnection() {
    if (!agencyIdRef.current || !selected) return;
    setWorking(true); setError(null);
    try {
      const response = await apiMutation<{ data: ProviderConnection }>(`/api/v1/integrations/connections/${selected.id}`, {
        enabled: !selected.enabled,
        version: selected.version,
      }, { method: "PATCH", agencyId: agencyIdRef.current });
      setConnections((current) => current.map((item) => item.id === response.data.id ? response.data : item));
      setNotice(`Connection ${response.data.enabled ? "enabled" : "disabled"}.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function saveMapping() {
    if (!agencyIdRef.current || !selected) return;
    setWorking(true); setNotice(null);
    try {
      const response = await apiMutation<{ data: FieldMapping }>(`/api/v1/integrations/connections/${selected.id}/mappings`, {
        resource: "Property", fields: mappingDraft,
      }, { agencyId: agencyIdRef.current });
      setMappings((current) => [response.data, ...current]);
      setNotice(`Mapping v${response.data.version} is active for the next sync.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function runSync() {
    if (!agencyIdRef.current || !selected) return;
    setWorking(true); setNotice(null);
    try {
      const response = await apiMutation<{ data: SyncJob }>(`/api/v1/integrations/connections/${selected.id}/syncs`, {
        mode: selected.last_synced_at ? "incremental" : "full",
      }, { agencyId: agencyIdRef.current, idempotencyKey: crypto.randomUUID() });
      setSyncs((current) => [response.data, ...current.filter((item) => item.id !== response.data.id)]);
      setConnections((current) => current.map((item) => item.id === selected.id ? {
        ...item, last_sync_status: response.data.status, last_synced_at: response.data.completed_at,
      } : item));
      setNotice(`Sync ${response.data.status}. Imported ${response.data.records_imported}; skipped ${response.data.records_skipped}; failed ${response.data.records_failed}.`);
      await load();
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function decide(candidate: DuplicateCandidate, decision: "rejected" | "linked" | "merged" | "reverse") {
    if (!agencyIdRef.current) return;
    setWorking(true);
    try {
      const response = await apiMutation<{ data: DuplicateCandidate }>(`/api/v1/integrations/duplicate-candidates/${candidate.id}`, {
        decision, version: candidate.version,
      }, { method: "PATCH", agencyId: agencyIdRef.current });
      setDuplicates((current) => current.map((item) => item.id === candidate.id ? response.data : item));
      setNotice(`Duplicate candidate marked ${response.data.status}.`);
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  const metrics = useMemo(() => [
    { label: "Connections", value: connections.length.toString(), icon: "settings" as const },
    { label: "Last successful sync", value: selected?.last_synced_at ? formatDate(selected.last_synced_at, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }) : "Not run", icon: "check" as const },
    { label: "Imported this run", value: imported.toLocaleString(), icon: "building" as const },
    { label: "Needs review", value: (pendingDuplicates.length + unresolvedErrors.length).toLocaleString(), icon: "shield" as const },
  ], [connections.length, imported, pendingDuplicates.length, selected?.last_synced_at, unresolvedErrors.length]);

  return <main className="workspace-canvas release-canvas integrations-canvas">
    <header className="workspace-title release-title"><div><h1>Data integrations</h1><p>Connect verified listing sources and keep every imported record traceable.</p></div><button className="button button--primary" type="button" onClick={() => setShowConnectionForm((value) => !value)}><Icon name="plus" /> Add RESO connection</button></header>

    {showConnectionForm ? <form className="release-form connection-form" onSubmit={(event) => void createConnection(event)}>
      <header><div><h2>Connect a licensed RESO source</h2><p>Credentials stay in the deployment secret manager. Enter only its reference name here.</p></div><button type="button" onClick={() => setShowConnectionForm(false)}>Cancel</button></header>
      <label>Connection name<input required maxLength={160} value={connectionDraft.name} onChange={(event) => setConnectionDraft((current) => ({ ...current, name: event.target.value }))} /></label>
      <label>RESO API base URL<input required type="url" inputMode="url" placeholder="https://api.example-mls.com/odata/" value={connectionDraft.base_url} onChange={(event) => setConnectionDraft((current) => ({ ...current, base_url: event.target.value }))} /></label>
      <label>OAuth token URL<input required type="url" inputMode="url" placeholder="https://auth.example-mls.com/oauth/token" value={connectionDraft.token_url} onChange={(event) => setConnectionDraft((current) => ({ ...current, token_url: event.target.value }))} /></label>
      <label>Client ID<input required autoComplete="off" value={connectionDraft.client_id} onChange={(event) => setConnectionDraft((current) => ({ ...current, client_id: event.target.value }))} /></label>
      <label>Secret reference<input required pattern="[A-Za-z0-9_.-]+" autoComplete="off" placeholder="reso.production.client-secret" value={connectionDraft.secret_reference} onChange={(event) => setConnectionDraft((current) => ({ ...current, secret_reference: event.target.value }))} /></label>
      <label>Required attribution<input required maxLength={255} placeholder="Listing data © Example MLS" value={connectionDraft.attribution} onChange={(event) => setConnectionDraft((current) => ({ ...current, attribution: event.target.value }))} /></label>
      <label className="marketplace-check"><input type="checkbox" checked={connectionDraft.photos} onChange={(event) => setConnectionDraft((current) => ({ ...current, photos: event.target.checked }))} /> The provider agreement permits photo display</label>
      <footer><button className="button button--primary" disabled={working} type="submit">{working ? "Saving…" : "Save connection"}</button></footer>
    </form> : null}

    {error ? <section className="operations-state release-state" role="alert"><Icon name="shield" /><h2>{error.code === "FEATURE_DISABLED" ? "Integrations are not enabled" : "Integration workspace unavailable"}</h2><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => void load()}>Try again</button></section> : null}
    {!error && loading && connections.length === 0 ? <section className="operations-state release-state" role="status"><span className="inline-spinner" /><h2>Loading integration state</h2><p>Checking connections, mappings, import errors, and duplicate candidates.</p></section> : null}
    {!error && !loading ? <>
      <section className="release-metric-strip" aria-label="Integration status">{metrics.map((metric) => <div key={metric.label}><span><Icon name={metric.icon} /></span><p>{metric.label}</p><strong>{metric.value}</strong></div>)}</section>
      <p className="async-notice release-notice" role="status" aria-live="polite">{notice}</p>
      {!connections.length ? <section className="release-empty"><Icon name="settings" /><h2>No listing source connected</h2><p>Add a licensed RESO Web API connection. Sync controls remain unavailable until a versioned mapping exists.</p><button className="button button--primary" type="button" onClick={() => setShowConnectionForm(true)}>Add connection</button></section> : <div className="integration-layout">
        <section className="release-panel connection-panel" aria-labelledby="connections-title"><header className="release-panel__heading"><div><h2 id="connections-title">Connections</h2><p>Tenant-owned sources only</p></div><span>{connections.length} configured</span></header>
          <div className="connection-list">{connections.map((connection) => <button className={connection.id === selectedId ? "is-selected" : undefined} type="button" onClick={() => void selectConnection(connection.id)} key={connection.id}><span className="release-icon"><Icon name="settings" /></span><span><strong>{connection.name}</strong><small>{new URL(connection.base_url).host}</small></span><em className={`release-status release-status--${connection.enabled ? "active" : "ended"}`}>{connection.enabled ? connection.last_sync_status ?? "Connected" : "Disabled"}</em></button>)}</div>
        </section>

        {selected ? <section className="release-panel integration-detail" aria-labelledby="integration-detail-title"><header className="release-panel__heading"><div><h2 id="integration-detail-title">{selected.name}</h2><p>{selected.rights.attribution || "Attribution not supplied"}</p></div><div className="release-actions"><button className="button button--outline" type="button" disabled={working} onClick={() => void toggleConnection()}>{selected.enabled ? "Disable" : "Enable"}</button><button className={`button ${selected.enabled && mappings.length ? "button--primary" : "button--disabled"}`} type="button" disabled={working || !selected.enabled || !mappings.length} onClick={() => void runSync()}>{working ? "Working…" : "Run sync"}</button></div></header>
          {!mappings.length ? <div className="release-inline-warning"><Icon name="shield" /><span><strong>Mapping required</strong><small>Review the default RESO-to-Casaura field map and save version 1 before syncing.</small></span></div> : null}
          <nav className="release-tabs" aria-label="Integration detail"><button className={tab === "mapping" ? "is-active" : undefined} onClick={() => setTab("mapping")} type="button">Mapping <span>v{mappings[0]?.version ?? 0}</span></button><button className={tab === "activity" ? "is-active" : undefined} onClick={() => setTab("activity")} type="button">Activity <span>{syncs.length}</span></button><button className={tab === "duplicates" ? "is-active" : undefined} onClick={() => setTab("duplicates")} type="button">Duplicates <span>{pendingDuplicates.length}</span></button><button className={tab === "errors" ? "is-active" : undefined} onClick={() => setTab("errors")} type="button">Errors <span>{unresolvedErrors.length}</span></button></nav>
          {tab === "mapping" ? <div className="mapping-editor"><div className="mapping-discovery"><span>{metadata ? `${metadata.resources.find((resource) => resource.name === "Property")?.fields.length ?? 0} Property fields discovered` : "Validate source fields against live RESO metadata before activation."}</span><button className="button button--outline" type="button" disabled={working} onClick={() => void discoverMetadata()}>{working ? "Loading…" : "Discover metadata"}</button></div><datalist id="reso-property-fields">{metadata?.resources.find((resource) => resource.name === "Property")?.fields.map((field) => <option value={field.name} label={field.type} key={field.name} />)}</datalist><div className="mapping-row mapping-row--head"><span>Source field (RESO)</span><span>Target field (Casaura)</span></div>{mappingRows.map(([target, source]) => <div className="mapping-row" key={target}><input list="reso-property-fields" aria-label={`RESO source for ${target}`} value={source} onChange={(event) => setMappingDraft((current) => ({ ...current, [target]: event.target.value }))} /><span aria-hidden="true">→</span><code>{target}</code></div>)}<footer><span>{Object.keys(mappingDraft).length} mapped fields · Property resource</span><button className="button button--primary" disabled={working} type="button" onClick={() => void saveMapping()}>{working ? "Saving…" : "Save mapping"}</button></footer></div> : null}
          {tab === "activity" ? <ReleaseTable headers={["Run", "Mode", "Status", "Fetched", "Imported", "Failed"]} empty="No sync has run for this connection.">{syncs.map((sync) => <div className="release-table__row" role="row" key={sync.id}><code>{sync.id.slice(0, 8)}</code><span>{sync.mode}</span><span><em className={`release-status release-status--${sync.status === "completed" ? "active" : sync.status === "failed" ? "error" : "pending"}`}>{sync.status}</em></span><span>{sync.records_fetched}</span><span>{sync.records_imported}</span><span>{sync.records_failed}</span></div>)}</ReleaseTable> : null}
          {tab === "duplicates" ? <ReleaseTable headers={["Candidate", "Confidence", "Reasons", "Status", "Actions"]} empty="No duplicate candidates need review.">{duplicates.map((candidate) => <div className="release-table__row duplicate-row" role="row" key={candidate.id}><code>{candidate.id.slice(0, 8)}</code><span>{Math.round(candidate.score * 100)}%</span><span>{candidate.reasons.join(", ") || "Similarity review"}</span><span><em className={`release-status release-status--${candidate.status === "pending" ? "pending" : "active"}`}>{candidate.status}</em></span><span className="table-actions">{candidate.status === "pending" ? <><button type="button" onClick={() => void decide(candidate, "linked")}>Link</button><button type="button" onClick={() => void decide(candidate, "merged")}>Merge</button><button type="button" onClick={() => void decide(candidate, "rejected")}>Not duplicate</button></> : ["linked", "merged"].includes(candidate.status) ? <button type="button" onClick={() => void decide(candidate, "reverse")}>Reverse</button> : null}</span></div>)}</ReleaseTable> : null}
          {tab === "errors" ? <ReleaseTable headers={["Error", "Field", "Code", "Retryable", "Received"]} empty="No import errors are waiting for review.">{errors.map((item) => <div className="release-table__row" role="row" key={item.id}><code>{item.id.slice(0, 8)}</code><span>{item.field ?? "Record"}</span><span>{item.code}</span><span>{item.retryable ? "Yes" : "No"}</span><span>{formatDate(item.created_at, { dateStyle: "medium", timeStyle: "short" })}</span></div>)}</ReleaseTable> : null}
        </section> : null}
      </div>}
    </> : null}
  </main>;
}

function ReleaseTable({ headers, empty, children }: { headers: string[]; empty: string; children: React.ReactNode }) {
  const rows = Array.isArray(children) ? children.length : children ? 1 : 0;
  return <div className="release-table-scroll" tabIndex={0} aria-label={`${headers[0]} table; horizontally scrollable`}><div className="release-table" style={{ "--release-columns": headers.length } as React.CSSProperties} role="table"><div className="release-table__head" role="row">{headers.map((header) => <span key={header}>{header}</span>)}</div>{rows ? children : <p className="release-table__empty">{empty}</p>}</div></div>;
}
