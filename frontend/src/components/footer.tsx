import Image from "next/image";
import Link from "next/link";

import NewsletterForm from "@/components/newsletter-form";

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
                className="h-12 w-auto md:h-14"
              />
              <span className="font-serif text-lg font-medium text-neutral-900 hidden sm:block">
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

        <p className="mt-10 border-t border-neutral-100 pt-6 text-center text-xs text-neutral-400">
          © {new Date().getFullYear()} Luce Bianca. All rights reserved.
        </p>
      </div>
    </footer>
  );
}