import type { Metadata } from "next";
import { AdminConsole } from "@/components/admin/admin-console";

export const metadata: Metadata = { title: "Platform operations", robots: { index: false, follow: false } };

export default function AdminPage() { return <AdminConsole />; }
