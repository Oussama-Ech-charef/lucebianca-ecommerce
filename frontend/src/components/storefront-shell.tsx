"use client";

import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

import Footer from "@/components/footer";
import Header from "@/components/header";
import WhatsAppButton from "@/components/whatsapp-button";
import { CartProvider } from "@/lib/cart-context";

/**
 * Storefront shell — renders the customer brand chrome (header, footer,
 * WhatsApp button, cart provider) for storefront pages only.
 *
 * Admin routes render with no brand shell (spec section 7 describes no admin
 * visual identity): the root layout still provides fonts + body, but /admin/*
 * gets a clean, bare viewport. Decided over restructuring the app into route
 * groups so no existing storefront URL/path is touched this phase.
 */
export default function StorefrontShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();

  if (pathname.startsWith("/admin")) {
    return <>{children}</>;
  }

  return (
    <CartProvider>
      <Header />
      <div className="flex flex-1 flex-col">{children}</div>
      <Footer />
      <WhatsAppButton />
    </CartProvider>
  );
}