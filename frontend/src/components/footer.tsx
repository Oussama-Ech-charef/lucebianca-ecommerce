import Image from "next/image";
import Link from "next/link";

import NewsletterForm from "@/components/newsletter-form";
import { SOCIAL_LINKS } from "@/lib/config";

// Footer link groups. Help & Info pages are phase 14 (static/semi-static
// info + legal pages); the storefront is deliberately limited to real routes.
const SHOP_LINKS = [{ label: "Shop", href: "/shop" }];

const INFO_LINKS = [
  { label: "About", href: "/about" },
  { label: "Contact", href: "/contact" },
  { label: "Size guide", href: "/size-guide" },
  { label: "Shipping", href: "/shipping" },
  { label: "Returns", href: "/returns" },
  { label: "Terms", href: "/terms" },
  { label: "Privacy", href: "/privacy" },
];

function InstagramIcon() {
  return (
    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
      <rect x={3} y={3} width={18} height={18} rx={5} />
      <circle cx={12} cy={12} r={4} />
      <circle cx={17.5} cy={6.5} r={1} fill="currentColor" stroke="none" />
    </svg>
  );
}

function FacebookIcon() {
  return (
    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M14 8h3V4h-3c-2.5 0-4 1.5-4 4v3H7v4h3v5h4v-5h3l1-4h-4V8c0-.5.5-1 1-1Z" />
    </svg>
  );
}

function XIcon() {
  return (
    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
      <path d="M4 4L20 20M20 4L4 20" strokeLinecap="round" />
    </svg>
  );
}

export default function Footer() {
  return (
    <footer className="border-t border-neutral-200 bg-white">
      <div className="mx-auto w-full max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
        <div className="flex flex-col items-center gap-10 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
          <div className="flex flex-col items-center md:items-start">
            <div className="flex items-center gap-2">
              <Image
                src="/logo.png"
                alt=""
                width={126}
                height={84}
                className="h-10 w-auto md:h-12"
              />
              <span className="font-serif text-base font-medium text-neutral-900 truncate max-w-[140px] sm:max-w-[180px] md:max-w-none">
                Luce Bianca
              </span>
            </div>
            <p className="mt-2 text-xs uppercase tracking-[0.3em] text-neutral-400">
              Casual Luxury
            </p>
          </div>

          <nav
            aria-label="Footer"
            className="flex flex-col gap-10 text-center text-sm sm:flex-row sm:gap-16 sm:text-left"
          >
            <div>
              <p className="text-xs uppercase tracking-[0.2em] text-neutral-400">
                Shop
              </p>
              <ul className="mt-3 space-y-2">
                {SHOP_LINKS.map((link) => (
                  <li key={link.href}>
                    <Link
                      href={link.href}
                      className="text-neutral-600 transition-colors hover:text-neutral-900"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <p className="text-xs uppercase tracking-[0.2em] text-neutral-400">
                Help &amp; info
              </p>
              <ul className="mt-3 space-y-2">
                {INFO_LINKS.map((link) => (
                  <li key={link.href}>
                    <Link
                      href={link.href}
                      className="text-neutral-600 transition-colors hover:text-neutral-900"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          </nav>

          <div className="w-full max-w-md text-center sm:max-w-md lg:max-w-xs lg:text-left">
            <NewsletterForm />
          </div>
        </div>

        <div className="mt-10 flex justify-center gap-3 border-t border-neutral-100 pt-6">
          <a
            href={SOCIAL_LINKS.instagram}
            aria-label="Instagram"
            target="_blank"
            rel="noopener noreferrer"
            className="flex h-9 w-9 items-center justify-center rounded-full border border-neutral-200 text-neutral-500 transition-colors hover:border-neutral-900 hover:text-neutral-900"
          >
            <InstagramIcon />
          </a>
          <a
            href={SOCIAL_LINKS.facebook}
            aria-label="Facebook"
            target="_blank"
            rel="noopener noreferrer"
            className="flex h-9 w-9 items-center justify-center rounded-full border border-neutral-200 text-neutral-500 transition-colors hover:border-neutral-900 hover:text-neutral-900"
          >
            <FacebookIcon />
          </a>
          <a
            href={SOCIAL_LINKS.x}
            aria-label="X (formerly Twitter)"
            target="_blank"
            rel="noopener noreferrer"
            className="flex h-9 w-9 items-center justify-center rounded-full border border-neutral-200 text-neutral-500 transition-colors hover:border-neutral-900 hover:text-neutral-900"
          >
            <XIcon />
          </a>
        </div>

        <p className="mt-6 text-center text-xs text-neutral-400">
          © {new Date().getFullYear()} Luce Bianca. All rights reserved.
        </p>
      </div>
    </footer>
  );
}