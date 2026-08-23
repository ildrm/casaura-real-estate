"use client";

import Link from "next/link";
import { type FormEvent, useCallback, useEffect, useState } from "react";
import { PublicPropertyCard } from "@/components/marketplace/public-property-card";
import { Icon } from "@/components/ui/icon";
import { apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { ConsumerCollection } from "@/lib/release-types";

export function CollectionsWorkspace() {
  const [collections, setCollections] = useState<ConsumerCollection[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [invitation, setInvitation] = useState<string | null>(null);

  const selected = collections.find((collection) => collection.id === selectedId) ?? collections[0] ?? null;

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const response = await apiQuery<{ data: ConsumerCollection[] }>("/api/v1/account/collections");
      setCollections(response.data);
      setSelectedId((current) => current && response.data.some((item) => item.id === current) ? current : response.data[0]?.id ?? null);
    } catch (caught) { setError(caught as ApiError); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const name = String(new FormData(form).get("name") ?? "").trim();
    setWorking(true); setError(null);
    try {
      const response = await apiMutation<{ data: ConsumerCollection }>("/api/v1/account/collections", { name });
      setCollections((current) => [response.data, ...current]); setSelectedId(response.data.id); form.reset();
      setNotice("Private collection created.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function rename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return;
    const name = String(new FormData(event.currentTarget).get("name") ?? "").trim();
    setWorking(true);
    try {
      const response = await apiMutation<{ data: ConsumerCollection }>(`/api/v1/account/collections/${selected.id}`, { name, version: selected.version }, { method: "PATCH" });
      replace(response.data); setNotice("Collection renamed.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function removeItem(listingId: string) {
    if (!selected) return;
    setWorking(true);
    try {
      const response = await apiMutation<{ data: ConsumerCollection }>(`/api/v1/account/collections/${selected.id}/items`, { listing_id: listingId }, { method: "DELETE" });
      replace(response.data); setNotice("Home removed from this collection.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function move(listingId: string, direction: -1 | 1) {
    if (!selected) return;
    const ids = selected.items.map((item) => item.listing_id);
    const index = ids.indexOf(listingId);
    const destination = index + direction;
    if (destination < 0 || destination >= ids.length) return;
    [ids[index], ids[destination]] = [ids[destination], ids[index]];
    setWorking(true);
    try {
      const response = await apiMutation<{ data: ConsumerCollection }>(`/api/v1/account/collections/${selected.id}/items`, { listing_ids: ids, version: selected.version }, { method: "PATCH" });
      replace(response.data); setNotice("Collection order updated.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return;
    const values = new FormData(event.currentTarget);
    setWorking(true); setInvitation(null);
    try {
      const response = await apiMutation<{ data: { invitation_token: string; expires_at: string } }>(`/api/v1/account/collections/${selected.id}/members`, { email: String(values.get("email") ?? ""), role: String(values.get("role") ?? "viewer") });
      setInvitation(`${window.location.origin}/collections/invitations/${response.data.invitation_token}`);
      setNotice("Invitation created. Share the single-use link through an approved channel.");
    } catch (caught) { setError(caught as ApiError); }
    finally { setWorking(false); }
  }

  function replace(collection: ConsumerCollection) {
    setCollections((current) => current.map((item) => item.id === collection.id ? collection : item));
  }

  return <main className="account-page shell collections-page">
    <header className="account-heading release-account-heading"><span><Icon name="heart" /></span><div><h1>Private collections</h1><p>Organize published homes, compare a shortlist, and collaborate with explicit access.</p></div><Link className="button button--outline" href="/assistant">Open property assistant</Link></header>
    {error ? <section className="account-sign-in" role="alert"><Icon name="shield" /><h2>{error.code === "UNAUTHENTICATED" ? "Sign in to use private collections" : "Collections are unavailable"}</h2><p>{error.message}</p>{error.code === "UNAUTHENTICATED" ? <Link className="button button--primary" href="/sign-in?next=/collections">Sign in</Link> : <button className="button button--outline" type="button" onClick={() => void load()}>Try again</button>}</section> : null}
    {!error && loading ? <section className="account-loading" role="status"><span /> Loading private collections…</section> : null}
    {!error && !loading ? <div className="collections-layout">
      <aside className="collections-rail"><form onSubmit={(event) => void create(event)}><label htmlFor="new-collection">New collection</label><div><input id="new-collection" name="name" minLength={2} maxLength={120} placeholder="Weekend shortlist" required /><button className="button button--primary" type="submit" disabled={working}><Icon name="plus" /><span className="sr-only">Create collection</span></button></div></form><nav aria-label="Your collections">{collections.map((collection) => <button className={collection.id === selected?.id ? "is-active" : undefined} type="button" onClick={() => { setSelectedId(collection.id); setInvitation(null); }} key={collection.id}><Icon name="heart" /><span><strong>{collection.name}</strong><small>{collection.items.length} homes · {collection.role}</small></span><Icon name="chevron-right" /></button>)}</nav>{!collections.length ? <p>No collections yet. Create the first one above.</p> : null}</aside>
      <section className="collection-detail">
        {selected ? <><header><form onSubmit={(event) => void rename(event)}><label className="sr-only" htmlFor="collection-name">Collection name</label><input id="collection-name" name="name" defaultValue={selected.name} minLength={2} maxLength={120} readOnly={selected.role !== "owner"} /><button type="submit" disabled={selected.role !== "owner" || working}>Save name</button></form><span>{selected.role === "owner" ? "Private owner" : `${selected.role} access`}</span></header><p className="async-notice release-notice" role="status" aria-live="polite">{notice}</p>
          {selected.items.length ? <><div className="collection-items">{selected.items.map((item, index) => <article key={item.listing_id}>{item.unavailable || !item.listing ? <div className="collection-unavailable"><Icon name="shield" /><h2>Listing unavailable</h2><p>This private position is preserved, but unpublished facts are hidden.</p></div> : <PublicPropertyCard listing={item.listing} hideFavorite />}<footer><span>Position {index + 1}</span>{selected.role !== "viewer" ? <div><button type="button" disabled={working || index === 0} onClick={() => void move(item.listing_id, -1)}>Move up</button><button type="button" disabled={working || index === selected.items.length - 1} onClick={() => void move(item.listing_id, 1)}>Move down</button><button type="button" disabled={working} onClick={() => void removeItem(item.listing_id)}>Remove</button></div> : null}</footer></article>)}</div>{selected.items.filter((item) => !item.unavailable).length >= 2 ? <Link className="button button--primary collection-compare" href={`/compare?ids=${selected.items.filter((item) => !item.unavailable).slice(0, 5).map((item) => item.listing_id).join(",")}`}>Compare up to five homes <Icon name="arrow-right" /></Link> : null}</> : <section className="release-empty"><Icon name="home" /><h2>This collection is empty</h2><p>Add homes from search or the property assistant. Duplicate adds are safely ignored.</p><Link className="button button--primary" href="/search">Explore homes</Link></section>}
          {selected.role === "owner" ? <form className="collection-invite" onSubmit={(event) => void invite(event)}><h2>Invite a collaborator</h2><p>Invitations expire after seven days. Editors can organize homes; viewers are read-only.</p><label>Email<input name="email" type="email" required /></label><label>Role<select name="role" defaultValue="viewer"><option value="viewer">Viewer</option><option value="editor">Editor</option></select></label><button className="button button--outline" type="submit" disabled={working}>Create invitation</button>{invitation ? <label>Single-use invitation link<input readOnly value={invitation} onFocus={(event) => event.currentTarget.select()} /></label> : null}</form> : null}
        </> : <section className="release-empty"><Icon name="heart" /><h2>Create your first private collection</h2><p>Collections are visible only to you and collaborators whose invitations you approve.</p></section>}
      </section>
    </div> : null}
  </main>;
}
