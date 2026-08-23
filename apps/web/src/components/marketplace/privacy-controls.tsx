"use client";

import { useEffect, useState } from "react";
import { apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import { publicConfig } from "@/lib/public-config";

type PrivacyRequest = {
  id: string;
  type: "export" | "deletion";
  status: string;
  requested_at: string;
  expires_at: string | null;
  download_available: boolean;
};

export function PrivacyControls() {
  const [requests, setRequests] = useState<PrivacyRequest[]>([]);
  const [busy, setBusy] = useState<PrivacyRequest["type"] | null>(null);
  const [notice, setNotice] = useState<{ kind: "error" | "success"; message: string } | null>(null);

  useEffect(() => {
    void apiQuery<{ data: PrivacyRequest[] }>("/api/v1/account/privacy/requests")
      .then((response) => setRequests(response.data))
      .catch(() => undefined);
  }, []);

  async function submit(type: PrivacyRequest["type"]) {
    if (type === "deletion" && !window.confirm("Request an account deletion review? Agency ownership must be transferred before deletion can be completed.")) return;
    setBusy(type);
    setNotice(null);
    try {
      const response = await apiMutation<{ data: PrivacyRequest }>("/api/v1/account/privacy/requests", { type });
      setRequests((current) => [response.data, ...current.filter((item) => item.id !== response.data.id)]);
      setNotice({ kind: "success", message: type === "export" ? "Your encrypted export is ready below." : "Your deletion request is awaiting identity and legal review." });
    } catch (caught) {
      setNotice({ kind: "error", message: (caught as ApiError).message });
    } finally {
      setBusy(null);
    }
  }

  return <section className="account-section privacy-controls" aria-labelledby="privacy-controls-title">
    <header><h2 id="privacy-controls-title">Privacy and account data</h2><span>Private</span></header>
    <p>Request a portable account export or start a reviewed deletion. Export files are encrypted and expire after seven days.</p>
    <div className="privacy-controls__actions">
      <button className="button button--outline" type="button" disabled={busy !== null} onClick={() => void submit("export")}>{busy === "export" ? "Preparing…" : "Request data export"}</button>
      <button className="button button--outline" type="button" disabled={busy !== null} onClick={() => void submit("deletion")}>{busy === "deletion" ? "Submitting…" : "Request deletion review"}</button>
    </div>
    {notice ? <p className={notice.kind === "error" ? "is-error" : undefined} role={notice.kind === "error" ? "alert" : "status"}>{notice.message}</p> : null}
    {requests.length ? <ul className="privacy-controls__requests">{requests.map((item) => <li key={item.id}><span><strong>{item.type === "export" ? "Data export" : "Deletion review"}</strong><small>{item.status.replaceAll("_", " ")}</small></span>{item.download_available ? <a className="button button--outline" href={`${publicConfig.apiUrl}/api/v1/account/privacy/requests/${item.id}/download`}>Download</a> : null}</li>)}</ul> : null}
  </section>;
}
