"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { BrandMark } from "@/components/brand/logo";
import { useWorkspaceSession } from "@/components/dashboard/workspace-session";
import { Icon } from "@/components/ui/icon";
import { activeAgencyId, apiMutation, apiQuery, type ApiError } from "@/lib/api-client";
import type { ListingProjection, MediaProjection, PropertyCatalog, QualityCheck } from "@/lib/listing-types";
import { publicConfig } from "@/lib/public-config";

type EditorState = {
  intent: "sale" | "rent";
  propertyType: string;
  price: string;
  currency: string;
  reference: string;
  bedrooms: string;
  bathrooms: string;
  interiorArea: string;
  areaUnit: "sq_ft" | "sqm";
  title: string;
  description: string;
  line1: string;
  line2: string;
  locality: string;
  region: string;
  postalCode: string;
  countryCode: string;
  yearBuilt: string;
  parkingSpaces: string;
  energyRating: string;
  furnished: boolean;
  amenities: string[];
};

type SaveStatus = "idle" | "saving" | "saved" | "error" | "conflict";

const steps = ["Basics", "Location", "Details", "Features", "Media", "Review"] as const;
const initialState: EditorState = {
  intent: "sale", propertyType: "house", price: "", currency: publicConfig.currency, reference: "",
  bedrooms: "", bathrooms: "", interiorArea: "", areaUnit: publicConfig.areaUnit, title: "", description: "",
  line1: "", line2: "", locality: "", region: "", postalCode: "", countryCode: publicConfig.countryCode,
  yearBuilt: "", parkingSpaces: "", energyRating: "", furnished: false, amenities: [],
};

function fromListing(listing: ListingProjection): EditorState {
  const features = listing.property.features;
  const address = listing.property.address;
  return {
    intent: listing.intent,
    propertyType: listing.property.property_type.slug,
    price: listing.price ? String(listing.price.amount_minor / 100) : "",
    currency: listing.price?.currency ?? publicConfig.currency,
    reference: listing.reference,
    bedrooms: listing.property.bedrooms === null ? "" : String(listing.property.bedrooms),
    bathrooms: listing.property.bathrooms === null ? "" : String(listing.property.bathrooms),
    interiorArea: listing.property.interior_area === null ? "" : String(listing.property.interior_area[publicConfig.areaUnit]),
    areaUnit: publicConfig.areaUnit,
    title: listing.title ?? "",
    description: listing.description ?? "",
    line1: address?.line_1 ?? "",
    line2: address?.line_2 ?? "",
    locality: address?.locality ?? "",
    region: address?.region ?? "",
    postalCode: address?.postal_code ?? "",
    countryCode: address?.country_code ?? publicConfig.countryCode,
    yearBuilt: features.year_built === undefined ? "" : String(features.year_built),
    parkingSpaces: features.parking_spaces === undefined ? "" : String(features.parking_spaces),
    energyRating: features.energy_rating === undefined ? "" : String(features.energy_rating),
    furnished: features.furnished === true,
    amenities: listing.property.amenities,
  };
}

function numberOrNull(value: string): number | null {
  if (value.trim() === "") return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

export function ListingEditor({ initialListingId }: { initialListingId?: string }) {
  const router = useRouter();
  const { membership } = useWorkspaceSession();
  const [listingId, setListingId] = useState(initialListingId ?? null);
  const [version, setVersion] = useState<number | null>(null);
  const [listingStatus, setListingStatus] = useState<ListingProjection["status"]>("draft");
  const [quality, setQuality] = useState<ListingProjection["quality"]>({ score: 0, ready_for_review: false, checklist: [] });
  const [catalog, setCatalog] = useState<PropertyCatalog | null>(null);
  const [media, setMedia] = useState<MediaProjection[]>([]);
  const [state, setState] = useState<EditorState>(initialState);
  const [step, setStep] = useState(0);
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(Boolean(initialListingId));
  const [dirty, setDirty] = useState(false);
  const [uploading, setUploading] = useState(false);
  const saveInFlight = useRef<Promise<ListingProjection | null> | null>(null);
  const canUpdate = membership.permissions.includes("listing.update");
  const canPublish = membership.permissions.includes("listing.publish");
  const canDelete = membership.permissions.includes("listing.delete");
  const canManageMedia = membership.permissions.includes("media.manage");
  const editable = canUpdate && ["draft", "changes_requested"].includes(listingStatus);

  const applyListing = useCallback((listing: ListingProjection) => {
    setListingId(listing.id);
    setVersion(listing.version);
    setListingStatus(listing.status);
    setQuality(listing.quality);
  }, []);

  useEffect(() => {
    async function hydrate() {
      await Promise.resolve();
      const agencyId = activeAgencyId();
      if (!agencyId) {
        setMessage("Select an agency or sign in again to edit inventory.");
        setSaveStatus("error");
        setLoading(false);
        return;
      }

      try {
        const catalogResponse = await apiQuery<{ data: PropertyCatalog }>("/api/v1/property-catalog", agencyId);
        setCatalog(catalogResponse.data);
        if (initialListingId) {
          const [listingResponse, mediaResponse] = await Promise.all([
            apiQuery<{ data: ListingProjection }>(`/api/v1/listings/${initialListingId}`, agencyId),
            apiQuery<{ data: MediaProjection[] }>(`/api/v1/listings/${initialListingId}/media`, agencyId),
          ]);
          setState(fromListing(listingResponse.data));
          applyListing(listingResponse.data);
          setMedia(mediaResponse.data);
          setSaveStatus("saved");
        }
      } catch (caught) {
        setMessage((caught as ApiError).message);
        setSaveStatus("error");
      } finally {
        setLoading(false);
      }
    }
    void hydrate();
  }, [applyListing, initialListingId]);

  function update<K extends keyof EditorState>(key: K, value: EditorState[K]) {
    setState((current) => ({ ...current, [key]: value }));
    setDirty(true);
    setSaveStatus((current) => current === "saving" ? current : "idle");
    setMessage(null);
  }

  const requestBody = useCallback((currentVersion?: number) => {
    const features: Record<string, boolean | number | string> = {};
    const yearBuilt = numberOrNull(state.yearBuilt);
    const parkingSpaces = numberOrNull(state.parkingSpaces);
    if (yearBuilt !== null) features.year_built = yearBuilt;
    if (parkingSpaces !== null) features.parking_spaces = parkingSpaces;
    if (state.energyRating) features.energy_rating = state.energyRating;
    if (state.furnished) features.furnished = true;

    const body: Record<string, unknown> = {
      reference: state.reference.trim(),
      intent: state.intent,
      property_type_slug: state.propertyType,
      title: state.title.trim() || null,
      description: state.description.trim() || null,
      bedrooms: numberOrNull(state.bedrooms),
      bathrooms: numberOrNull(state.bathrooms),
      features,
      amenity_slugs: state.amenities,
    };
    if (currentVersion !== undefined) body.version = currentVersion;
    const price = numberOrNull(state.price);
    if (price !== null) body.price = { amount_minor: Math.round(price * 100), currency: state.currency };
    const area = numberOrNull(state.interiorArea);
    if (area !== null) body.interior_area = { value: area, unit: state.areaUnit };
    if ([state.line1, state.line2, state.locality, state.region, state.postalCode].some(Boolean)) {
      body.address = {
        line_1: state.line1.trim() || null,
        line_2: state.line2.trim() || null,
        locality: state.locality.trim() || null,
        region: state.region.trim() || null,
        postal_code: state.postalCode.trim() || null,
        country_code: state.countryCode,
      };
    }
    return body;
  }, [state]);

  const save = useCallback(async (force = false): Promise<ListingProjection | null> => {
    if (!editable) return null;
    if (saveInFlight.current) return saveInFlight.current;
    if (!force && !dirty) return null;
    if (!state.reference.trim() || !state.propertyType) {
      if (force) {
        setMessage("Add a reference ID and property type before saving this draft.");
        setSaveStatus("error");
      }
      return null;
    }
    const agencyId = activeAgencyId();
    if (!agencyId) return null;

    setSaveStatus("saving");
    setMessage(null);
    setDirty(false);
    const operation = (async () => {
      try {
        const response = listingId
          ? await apiMutation<{ data: ListingProjection }>(`/api/v1/listings/${listingId}`, requestBody(version ?? 1), { method: "PATCH", agencyId })
          : await apiMutation<{ data: ListingProjection }>("/api/v1/listings", requestBody(), { agencyId });
        applyListing(response.data);
        if (!listingId) router.replace(`/agency/properties/${response.data.id}/edit`);
        setSaveStatus("saved");
        return response.data;
      } catch (caught) {
        const error = caught as ApiError;
        setDirty(true);
        setSaveStatus(error.code === "LISTING_VERSION_CONFLICT" ? "conflict" : "error");
        setMessage(error.code === "LISTING_VERSION_CONFLICT"
          ? "This draft changed in another session. Reload before saving again."
          : error.message);
        return null;
      } finally {
        saveInFlight.current = null;
      }
    })();
    saveInFlight.current = operation;
    return operation;
  }, [applyListing, dirty, editable, listingId, requestBody, router, state.propertyType, state.reference, version]);

  useEffect(() => {
    if (!dirty || loading) return;
    const timer = window.setTimeout(() => { void save(); }, 700);
    return () => window.clearTimeout(timer);
  }, [dirty, loading, save]);

  async function refreshListing(id = listingId) {
    const agencyId = activeAgencyId();
    if (!agencyId || !id) return;
    const response = await apiQuery<{ data: ListingProjection }>(`/api/v1/listings/${id}`, agencyId);
    applyListing(response.data);
  }

  async function uploadFiles(files: FileList | null) {
    if (!files?.length) return;
    const saved = await save(true);
    const id = saved?.id ?? listingId;
    const agencyId = activeAgencyId();
    if (!id || !agencyId) return;
    setUploading(true);
    setMessage(null);
    try {
      for (const file of Array.from(files)) {
        const form = new FormData();
        form.append("file", file);
        form.append("alt_text", state.title ? `${state.title} property photo` : "Property photo");
        const response = await apiMutation<{ data: MediaProjection }>(`/api/v1/listings/${id}/media`, form, {
          agencyId,
          idempotencyKey: crypto.randomUUID(),
        });
        setMedia((current) => [...current, response.data].toSorted((a, b) => a.position - b.position));
      }
      await refreshListing(id);
      setSaveStatus("saved");
    } catch (caught) {
      setMessage((caught as ApiError).message);
      setSaveStatus("error");
    } finally {
      setUploading(false);
    }
  }

  async function submitForReview() {
    if (!canUpdate) return;
    const saved = await save(true);
    const id = saved?.id ?? listingId;
    const agencyId = activeAgencyId();
    if (!id || !agencyId) return;
    try {
      const response = await apiMutation<{ data: ListingProjection }>(`/api/v1/listings/${id}/submit`, {}, { agencyId });
      applyListing(response.data);
      setSaveStatus("saved");
      setMessage("Submitted for review. Your immutable draft history has been preserved.");
    } catch (caught) {
      const error = caught as ApiError;
      if (error.checklist) setQuality((current) => ({ ...current, ready_for_review: false, checklist: error.checklist as QualityCheck[] }));
      setMessage(error.message);
      setSaveStatus("error");
    }
  }

  async function transition(action: "publish" | "request-changes" | "withdraw") {
    const agencyId = activeAgencyId();
    if (!listingId || !agencyId || !canPublish) return;
    let body: Record<string, unknown> = {};
    if (action === "request-changes") {
      const note = window.prompt("Describe the changes required before publication:")?.trim();
      if (!note) return;
      body = { note };
    }
    if (action === "withdraw" && !window.confirm("Withdraw this listing from every public surface?")) return;
    try {
      const response = await apiMutation<{ data: ListingProjection }>(`/api/v1/listings/${listingId}/${action}`, body, { agencyId });
      applyListing(response.data);
      setMessage(action === "publish" ? "Listing published." : action === "withdraw" ? "Listing withdrawn from public discovery." : "Changes requested and recorded in history.");
      setSaveStatus("saved");
    } catch (caught) {
      setMessage((caught as ApiError).message);
      setSaveStatus("error");
    }
  }

  async function deleteListing() {
    const agencyId = activeAgencyId();
    if (!listingId || !agencyId || !canDelete || !window.confirm("Delete this listing and its private draft data? This cannot be undone.")) return;
    try {
      await apiMutation(`/api/v1/listings/${listingId}`, {}, { method: "DELETE", agencyId });
      router.replace("/agency/properties");
      router.refresh();
    } catch (caught) {
      setMessage((caught as ApiError).message);
      setSaveStatus("error");
    }
  }

  async function deleteMedia(item: MediaProjection) {
    const agencyId = activeAgencyId();
    if (!listingId || !agencyId || !canManageMedia || !window.confirm(`Delete ${item.original_name}?`)) return;
    try {
      await apiMutation(`/api/v1/listings/${listingId}/media/${item.id}`, {}, { method: "DELETE", agencyId });
      setMedia((current) => current.filter((mediaItem) => mediaItem.id !== item.id));
      await refreshListing();
      setMessage("Photo deleted.");
    } catch (caught) { setMessage((caught as ApiError).message); setSaveStatus("error"); }
  }

  async function moveMedia(item: MediaProjection, offset: -1 | 1) {
    const agencyId = activeAgencyId();
    if (!listingId || !agencyId || !canManageMedia) return;
    const currentIndex = media.findIndex((candidate) => candidate.id === item.id);
    const destination = currentIndex + offset;
    if (currentIndex < 0 || destination < 0 || destination >= media.length) return;
    const next = [...media];
    [next[currentIndex], next[destination]] = [next[destination], next[currentIndex]];
    try {
      const response = await apiMutation<{ data: MediaProjection[] }>(`/api/v1/listings/${listingId}/media/order`, { media_ids: next.map((candidate) => candidate.id) }, { method: "PATCH", agencyId });
      setMedia(response.data);
      await refreshListing();
      setMessage("Photo order updated.");
    } catch (caught) { setMessage((caught as ApiError).message); setSaveStatus("error"); }
  }

  const saveLabel = saveStatus === "saving" ? "Saving draft…"
    : saveStatus === "conflict" ? "Version conflict"
      : saveStatus === "error" ? "Draft needs attention"
        : saveStatus === "saved" ? "Draft saved just now"
          : "Draft not saved";
  const completedStep = useMemo(() => ({
    Basics: Boolean(state.reference && state.price && state.title && state.description.length >= 80),
    Location: Boolean(state.line1 && state.locality && state.region && state.postalCode),
    Details: Boolean(state.yearBuilt || state.parkingSpaces),
    Features: state.amenities.length > 0,
    Media: media.length >= 5,
    Review: listingStatus === "published" || listingStatus === "in_review",
  }), [listingStatus, media.length, state]);

  if (loading) return <main className="listing-editor-state" role="status">Loading the secure draft…</main>;

  return (
    <div className="listing-editor-shell">
      <header className="listing-editor-topbar">
        <BrandMark />
        <span className={`autosave-state autosave-state--${saveStatus}`} aria-live="polite"><Icon name={saveStatus === "error" || saveStatus === "conflict" ? "shield" : "check"} /> {saveLabel}</span>
        <div className="listing-lifecycle-actions">
          {editable ? <button className="button button--outline" type="button" onClick={() => void save(true)} disabled={saveStatus === "saving"}>Save draft</button> : null}
          {editable ? <button className="button button--primary" type="button" disabled={!quality.ready_for_review || !canUpdate} onClick={() => void submitForReview()}>Submit for review</button> : null}
          {listingStatus === "in_review" && canPublish ? <><button className="button button--outline" type="button" onClick={() => void transition("request-changes")}>Request changes</button><button className="button button--primary" type="button" onClick={() => void transition("publish")}>Publish</button></> : null}
          {listingStatus === "published" && canPublish ? <button className="button button--outline" type="button" onClick={() => void transition("withdraw")}>Withdraw</button> : null}
          {listingId && listingStatus !== "published" && canDelete ? <button className="button button--danger" type="button" onClick={() => void deleteListing()}>Delete</button> : null}
          <Link href="/agency/properties" className="editor-close" aria-label="Close listing editor">×</Link>
        </div>
      </header>

      <div className="listing-editor-layout">
        <nav className="editor-steps" aria-label="Listing editor steps"><ol>{steps.map((label, index) => <li className={step === index ? "is-active" : completedStep[label] ? "is-complete" : undefined} key={label}><button type="button" onClick={() => setStep(index)}><span>{completedStep[label] ? <Icon name="check" /> : index + 1}</span>{label}</button></li>)}</ol></nav>

        <main className="editor-canvas">
          {message ? <div className={saveStatus === "saved" ? "editor-notice editor-notice--success" : "editor-notice"} role={saveStatus === "saved" ? "status" : "alert"}>{message}</div> : null}
          {!editable ? <div className="editor-notice editor-notice--success" role="status">This listing is read-only in its current <strong>{listingStatus.replaceAll("_", " ")}</strong> state. Use an available lifecycle action above.</div> : null}
          <fieldset className="editor-state-fieldset" disabled={!editable}>
            {step === 0 ? <BasicsStep state={state} update={update} catalog={catalog} onContinue={() => setStep(1)} /> : null}
            {step === 1 ? <LocationStep state={state} update={update} onBack={() => setStep(0)} onContinue={() => setStep(2)} /> : null}
            {step === 2 ? <DetailsStep state={state} update={update} onBack={() => setStep(1)} onContinue={() => setStep(3)} /> : null}
            {step === 3 ? <FeaturesStep state={state} update={update} catalog={catalog} onBack={() => setStep(2)} onContinue={() => setStep(4)} /> : null}
            {step === 4 ? <MediaStep media={media} uploading={uploading} canManage={canManageMedia} onUpload={uploadFiles} onDelete={deleteMedia} onMove={moveMedia} onBack={() => setStep(3)} onContinue={() => setStep(5)} /> : null}
            {step === 5 ? <ReviewStep state={state} quality={quality} status={listingStatus} onBack={() => setStep(4)} onSubmit={submitForReview} /> : null}
          </fieldset>
        </main>

        <QualityInspector quality={quality} />
      </div>

      {editable ? <footer className="editor-mobile-actions"><button className="button button--outline" type="button" onClick={() => void save(true)}>Save draft</button>{step < steps.length - 1 ? <button className="button button--primary" type="button" onClick={() => setStep((current) => current + 1)}>Continue</button> : <button className="button button--primary" type="button" disabled={!quality.ready_for_review || !canUpdate} onClick={() => void submitForReview()}>Submit for review</button>}</footer> : <footer className="editor-mobile-actions">{listingStatus === "in_review" && canPublish ? <><button className="button button--outline" type="button" onClick={() => void transition("request-changes")}>Request changes</button><button className="button button--primary" type="button" onClick={() => void transition("publish")}>Publish</button></> : null}{listingStatus === "published" && canPublish ? <button className="button button--outline" type="button" onClick={() => void transition("withdraw")}>Withdraw</button> : null}{listingId && listingStatus !== "published" && canDelete ? <button className="button button--danger" type="button" onClick={() => void deleteListing()}>Delete</button> : null}</footer>}
    </div>
  );
}

type StepProps = { state: EditorState; update: <K extends keyof EditorState>(key: K, value: EditorState[K]) => void };

function BasicsStep({ state, update, catalog, onContinue }: StepProps & { catalog: PropertyCatalog | null; onContinue: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="basics-heading"><header><h1 id="basics-heading">Let’s start with the essentials</h1><p>You can save a draft and return at any time.</p></header>
    <fieldset className="intent-control"><legend>Listing intent</legend><label className={state.intent === "sale" ? "is-selected" : undefined}><input type="radio" name="intent" checked={state.intent === "sale"} onChange={() => update("intent", "sale")} /><Icon name="home" /> For sale</label><label className={state.intent === "rent" ? "is-selected" : undefined}><input type="radio" name="intent" checked={state.intent === "rent"} onChange={() => update("intent", "rent")} /><Icon name="building" /> For rent</label></fieldset>
    <div className="editor-form-grid">
      <label>Property type<select value={state.propertyType} onChange={(event) => update("propertyType", event.target.value)}>{catalog?.property_types.map((type) => <option value={type.slug} key={type.slug}>{type.name}</option>) ?? <option value="house">House</option>}</select></label>
      <div className="price-field"><label htmlFor="editor-price">Price</label><div><select aria-label="Currency" value={state.currency} onChange={(event) => update("currency", event.target.value)}><option>{state.currency}</option></select><input id="editor-price" inputMode="decimal" value={state.price} onChange={(event) => update("price", event.target.value)} placeholder="1,395,000" /></div></div>
      <label className="editor-span-2">Reference ID<input value={state.reference} onChange={(event) => update("reference", event.target.value.toUpperCase().replace(/[^A-Z0-9._-]/g, ""))} placeholder="GR-241-OKD" maxLength={100} /></label>
      <label>Bedrooms<input type="number" min="0" max="100" value={state.bedrooms} onChange={(event) => update("bedrooms", event.target.value)} /></label>
      <label>Bathrooms<input type="number" min="0" max="100" step="0.5" value={state.bathrooms} onChange={(event) => update("bathrooms", event.target.value)} /></label>
      <div className="area-field"><label htmlFor="editor-area">Interior area</label><div><input id="editor-area" type="number" min="0" value={state.interiorArea} onChange={(event) => update("interiorArea", event.target.value)} /><select aria-label="Area unit" value={state.areaUnit} onChange={(event) => update("areaUnit", event.target.value as EditorState["areaUnit"])}><option value={state.areaUnit}>{state.areaUnit === "sqm" ? "m²" : "sq ft"}</option></select></div></div>
      <label className="editor-span-3">Short title<input maxLength={160} value={state.title} onChange={(event) => update("title", event.target.value)} /><small>{state.title.length} / 160</small></label>
      <label className="editor-span-3">Description<textarea rows={6} maxLength={10000} value={state.description} onChange={(event) => update("description", event.target.value)} /><small>{state.description.length} characters · minimum 80 for review</small></label>
    </div>
    <StepActions onContinue={onContinue} continueLabel="Continue to location" />
  </section>;
}

function LocationStep({ state, update, onBack, onContinue }: StepProps & { onBack: () => void; onContinue: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="location-heading"><header><h1 id="location-heading">Where is the property?</h1><p>The exact address stays private until the listing is published.</p></header><div className="editor-form-grid">
    <label className="editor-span-3">Address line 1<input autoComplete="address-line1" value={state.line1} onChange={(event) => update("line1", event.target.value)} /></label>
    <label className="editor-span-3">Address line 2 <span>Optional</span><input autoComplete="address-line2" value={state.line2} onChange={(event) => update("line2", event.target.value)} /></label>
    <label>City or locality<input autoComplete="address-level2" value={state.locality} onChange={(event) => update("locality", event.target.value)} /></label>
    <label>State or region<input autoComplete="address-level1" value={state.region} onChange={(event) => update("region", event.target.value)} /></label>
    <label>Postal code<input autoComplete="postal-code" value={state.postalCode} onChange={(event) => update("postalCode", event.target.value)} /></label>
    <label>Country code<select value={state.countryCode} onChange={(event) => update("countryCode", event.target.value)}><option value={state.countryCode}>{state.countryCode}</option></select></label>
  </div><StepActions onBack={onBack} onContinue={onContinue} continueLabel="Continue to details" /></section>;
}

function DetailsStep({ state, update, onBack, onContinue }: StepProps & { onBack: () => void; onContinue: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="details-heading"><header><h1 id="details-heading">Add the property details</h1><p>Structured facts improve search quality and help buyers compare accurately.</p></header><div className="editor-form-grid">
    <label>Year built<input type="number" min="1700" max="2100" value={state.yearBuilt} onChange={(event) => update("yearBuilt", event.target.value)} /></label>
    <label>Parking spaces<input type="number" min="0" max="100" value={state.parkingSpaces} onChange={(event) => update("parkingSpaces", event.target.value)} /></label>
    <label>Energy rating<select value={state.energyRating} onChange={(event) => update("energyRating", event.target.value)}><option value="">Not provided</option>{["A", "B", "C", "D", "E", "F", "G"].map((rating) => <option key={rating}>{rating}</option>)}</select></label>
    <label className="editor-check editor-span-3"><input type="checkbox" checked={state.furnished} onChange={(event) => update("furnished", event.target.checked)} /><span><strong>Furnished</strong><small>Include furniture as part of the offer.</small></span></label>
  </div><StepActions onBack={onBack} onContinue={onContinue} continueLabel="Continue to features" /></section>;
}

function FeaturesStep({ state, update, catalog, onBack, onContinue }: StepProps & { catalog: PropertyCatalog | null; onBack: () => void; onContinue: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="features-heading"><header><h1 id="features-heading">Choose amenities</h1><p>Select only features that are present and can be verified.</p></header><fieldset className="amenity-grid"><legend>Property amenities</legend>{catalog?.amenities.map((amenity) => <label className={state.amenities.includes(amenity.slug) ? "is-selected" : undefined} key={amenity.slug}><input type="checkbox" checked={state.amenities.includes(amenity.slug)} onChange={(event) => update("amenities", event.target.checked ? [...state.amenities, amenity.slug] : state.amenities.filter((slug) => slug !== amenity.slug))} /><Icon name="check" /><span>{amenity.name}<small>{amenity.group}</small></span></label>)}</fieldset><StepActions onBack={onBack} onContinue={onContinue} continueLabel="Continue to media" /></section>;
}

function MediaStep({ media, uploading, canManage, onUpload, onDelete, onMove, onBack, onContinue }: { media: MediaProjection[]; uploading: boolean; canManage: boolean; onUpload: (files: FileList | null) => void; onDelete: (item: MediaProjection) => void; onMove: (item: MediaProjection, offset: -1 | 1) => void; onBack: () => void; onContinue: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="media-heading"><header><h1 id="media-heading">Show the property clearly</h1><p>Upload accurate, well-lit photos. Files are validated before private storage.</p></header><label className="media-drop"><input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={!canManage || uploading || media.length >= 30} onChange={(event) => onUpload(event.target.files)} /><span className="metric-icon"><Icon name="building" /></span><strong>{uploading ? "Validating and creating derivatives…" : canManage ? "Upload photos" : "Media management permission required"}</strong><small>JPG, PNG or WebP · up to 15 MB · {media.length} of 30 photos</small></label>{media.length ? <ol className="media-file-list">{media.map((item, index) => <li key={item.id}><span><Icon name="check" /></span><b>{item.original_name}<small>{item.width} × {item.height} · {(item.byte_size / 1024).toFixed(0)} KB</small></b><div className="media-file-actions"><button type="button" disabled={!canManage || index === 0} onClick={() => void onMove(item, -1)} aria-label={`Move ${item.original_name} earlier`}>↑</button><button type="button" disabled={!canManage || index === media.length - 1} onClick={() => void onMove(item, 1)} aria-label={`Move ${item.original_name} later`}>↓</button><button type="button" disabled={!canManage} onClick={() => void onDelete(item)}>Delete</button></div></li>)}</ol> : <p className="editor-empty">No photos uploaded yet. Five are required before review.</p>}<StepActions onBack={onBack} onContinue={onContinue} continueLabel="Review listing" /></section>;
}

function ReviewStep({ state, quality, status, onBack, onSubmit }: { state: EditorState; quality: ListingProjection["quality"]; status: ListingProjection["status"]; onBack: () => void; onSubmit: () => void }) {
  return <section className="editor-step-panel" aria-labelledby="review-heading"><header><h1 id="review-heading">Review before submission</h1><p>Confirm the marketing facts and resolve every readiness item.</p></header><dl className="review-summary"><div><dt>Reference</dt><dd>{state.reference || "Not set"}</dd></div><div><dt>Offer</dt><dd>{state.intent === "sale" ? "For sale" : "For rent"}</dd></div><div><dt>Property</dt><dd>{state.propertyType || "Not set"}</dd></div><div><dt>Location</dt><dd>{[state.line1, state.locality, state.region].filter(Boolean).join(", ") || "Not set"}</dd></div><div className="editor-span-2"><dt>Title</dt><dd>{state.title || "Not set"}</dd></div></dl><div className="review-checklist">{quality.checklist.map((check) => <p className={check.complete ? "is-complete" : undefined} key={check.key}><span>{check.complete ? <Icon name="check" /> : "!"}</span>{check.message}</p>)}</div><div className="step-actions"><button className="button button--outline" type="button" onClick={onBack}>Back</button><button className="button button--primary" type="button" disabled={!quality.ready_for_review || status === "in_review" || status === "published"} onClick={() => void onSubmit()}>{status === "in_review" ? "Already in review" : status === "published" ? "Published" : "Submit for review"}</button></div></section>;
}

function QualityInspector({ quality }: { quality: ListingProjection["quality"] }) {
  return <aside className="quality-inspector" aria-labelledby="quality-title"><h2 id="quality-title">Listing quality — {quality.score}%</h2><progress max="100" value={quality.score}>{quality.score}%</progress><ul>{quality.checklist.length ? quality.checklist.map((check) => <li className={check.complete ? "is-complete" : undefined} key={check.key}><span>{check.complete ? <Icon name="check" /> : null}</span>{check.message}</li>) : <li><span />Add a reference to begin the quality check.</li>}</ul><p><Icon name="shield" /> Complete required details before review.</p></aside>;
}

function StepActions({ onBack, onContinue, continueLabel }: { onBack?: () => void; onContinue: () => void; continueLabel: string }) {
  return <div className="step-actions">{onBack ? <button className="button button--outline" type="button" onClick={onBack}>Back</button> : <span />}<button className="button button--primary" type="button" onClick={onContinue}>{continueLabel} <Icon name="chevron-right" /></button></div>;
}
