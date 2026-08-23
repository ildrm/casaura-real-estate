"use client";

import Link from "next/link";
import { useState } from "react";
import { Icon } from "@/components/ui/icon";
import { apiMutation, type ApiError } from "@/lib/api-client";

export function CollectionInvitation({ token }: { token: string }) {
  const [state, setState] = useState<"ready" | "working" | "accepted" | "error">("ready");
  const [message, setMessage] = useState("This single-use invitation is private and expires after seven days.");
  async function accept() {
    setState("working");
    try {
      await apiMutation(`/api/v1/account/collection-invitations/${encodeURIComponent(token)}/accept`, {});
      setState("accepted"); setMessage("Collection access accepted.");
    } catch (caught) { setState("error"); setMessage((caught as ApiError).message); }
  }
  return <main className="auth-page"><section className="account-sign-in collection-invitation"><Icon name={state === "accepted" ? "check" : "heart"} /><h1>{state === "accepted" ? "Invitation accepted" : "Private collection invitation"}</h1><p role="status">{message}</p>{state === "accepted" ? <Link className="button button--primary" href="/collections">Open collections</Link> : <button className="button button--primary" type="button" disabled={state === "working"} onClick={() => void accept()}>{state === "working" ? "Accepting…" : state === "error" ? "Try again" : "Accept invitation"}</button>}</section></main>;
}
