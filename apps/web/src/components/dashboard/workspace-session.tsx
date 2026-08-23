"use client";

import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { apiQuery, type ApiError } from "@/lib/api-client";

export type WorkspaceMembership = {
  id: string;
  status: "invited" | "active" | "inactive";
  agency: { id: string; name: string; slug: string };
  roles: string[];
  permissions: string[];
};

type Principal = {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  memberships: WorkspaceMembership[];
};

type WorkspaceSession = {
  principal: Principal;
  membership: WorkspaceMembership;
  selectAgency: (agencyId: string) => void;
};

const WorkspaceSessionContext = createContext<WorkspaceSession | null>(null);

export function WorkspaceSessionProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [principal, setPrincipal] = useState<Principal | null>(null);
  const [activeAgency, setActiveAgency] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    let mounted = true;
    async function load() {
      try {
        const response = await apiQuery<{ data: Principal }>("/api/v1/me");
        if (!mounted) return;
        if (!response.data.email_verified_at) {
          router.replace("/verify-email");
          return;
        }
        const active = response.data.memberships.filter((membership) => membership.status === "active");
        const stored = window.localStorage.getItem("casaura.activeAgencyId");
        const selected = active.find((membership) => membership.agency.id === stored) ?? active[0];
        if (selected) window.localStorage.setItem("casaura.activeAgencyId", selected.agency.id);
        else window.localStorage.removeItem("casaura.activeAgencyId");
        setPrincipal({ ...response.data, memberships: active });
        setActiveAgency(selected?.agency.id ?? null);
        setError(null);
      } catch (caught) {
        if (!mounted) return;
        const next = caught as ApiError;
        if (["UNAUTHENTICATED", "SESSION_REVOKED"].includes(next.code)) {
          router.replace(`/sign-in?next=${encodeURIComponent(pathname)}`);
          return;
        }
        setError(next);
      }
    }
    void load();
    return () => { mounted = false; };
  }, [pathname, router]);

  const membership = principal?.memberships.find((item) => item.agency.id === activeAgency) ?? null;
  const value = useMemo<WorkspaceSession | null>(() => principal && membership ? {
    principal,
    membership,
    selectAgency: (agencyId: string) => {
      if (!principal.memberships.some((item) => item.agency.id === agencyId)) return;
      window.localStorage.setItem("casaura.activeAgencyId", agencyId);
      setActiveAgency(agencyId);
      window.location.reload();
    },
  } : null, [membership, principal]);

  if (error) return <main className="operations-state" role="alert"><h1>Workspace unavailable</h1><p>{error.message}</p><button className="button button--outline" type="button" onClick={() => window.location.reload()}>Try again</button></main>;
  if (!principal) return <main className="operations-state" role="status"><span className="inline-spinner" /><h1>Verifying your workspace</h1><p>Checking your current identity and agency access.</p></main>;
  if (!value) return <main className="operations-state" role="alert"><h1>No active agency workspace</h1><p>Your account does not currently have an active agency membership. Contact an agency owner or support.</p></main>;

  return <WorkspaceSessionContext.Provider value={value}>{children}</WorkspaceSessionContext.Provider>;
}

export function useWorkspaceSession(): WorkspaceSession {
  const session = useContext(WorkspaceSessionContext);
  if (!session) throw new Error("useWorkspaceSession must be used inside WorkspaceSessionProvider.");
  return session;
}
