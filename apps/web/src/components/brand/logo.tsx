import Link from "next/link";

export function BrandMark({ compact = false }: { compact?: boolean }) {
  return (
    <Link className="brand" href="/" aria-label="Casaura home">
      <svg className="brand__mark" viewBox="0 0 38 44" aria-hidden="true">
        <path d="M4 40V18C4 9.7 10.7 3 19 3s15 6.7 15 15v22H4Z" fill="currentColor" />
        <path d="M12 40V19a7 7 0 1 1 14 0v21H12Z" fill="white" />
        <circle cx="19" cy="19" r="2.6" fill="currentColor" />
        <path d="M17.8 21h2.4v8h-2.4z" fill="currentColor" />
      </svg>
      {compact ? null : <span>Casaura</span>}
    </Link>
  );
}
