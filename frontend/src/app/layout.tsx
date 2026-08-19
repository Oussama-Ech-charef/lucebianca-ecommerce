import type { Metadata } from "next";
import { Inter, Playfair_Display } from "next/font/google";
import StorefrontShell from "@/components/storefront-shell";
import "./globals.css";

// Typography plan (spec 3.6.1.1):
//   serif (Playfair Display) -> headings / hero, matching the ornate logo
//   sans  (Inter)            -> body text, buttons, UI
const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
});

const playfair = Playfair_Display({
  variable: "--font-playfair",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  applicationName: "Luce Bianca",
  title: {
    default: "Luce Bianca — Casual Luxury Tees",
    template: "%s | Luce Bianca",
  },
  description:
    "Luce Bianca — premium custom-designed t-shirts. Classic fits, original artwork, casual luxury.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${inter.variable} ${playfair.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">
        <StorefrontShell>{children}</StorefrontShell>
      </body>
    </html>
  );
}