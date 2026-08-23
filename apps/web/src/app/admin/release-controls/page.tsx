import type { Metadata } from "next";
import { AdminReleaseControls } from "@/components/admin/release-controls";

export const metadata: Metadata = { title: "AI safety & promotion controls", robots: { index: false, follow: false } };

export default function AdminReleaseControlsPage() { return <AdminReleaseControls />; }
