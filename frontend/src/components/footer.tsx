import Image from "next/image";
import Link from "next/link";

export default function Footer() {
  return (
    <footer className="border-t border-neutral-200 bg-white">
      <div className="mx-auto flex max-w-6xl flex-col items-center gap-6 px-4 py-10 text-center sm:px-6 md:flex-row md:justify-between md:text-left lg:px-8">
        <div>
          <Image
            src="/logo.png"
            alt="Luce Bianca"
            width={126}
            height={84}
            className="mx-auto h-12 w-auto md:mx-0 md:h-14"
          />
          <p className="mt-2 text-xs uppercase tracking-[0.3em] text-neutral-400">
            Casual Luxury
          </p>
        </div>
        <nav aria-label="Footer" className="flex gap-6 text-sm">
          <Link
            href="/shop"
            className="text-neutral-600 transition-colors hover:text-neutral-900"
          >
            Shop
          </Link>
        </nav>
        <p className="text-xs text-neutral-400">
          © {new Date().getFullYear()} Luce Bianca. All rights reserved.
        </p>
      </div>
    </footer>
  );
}