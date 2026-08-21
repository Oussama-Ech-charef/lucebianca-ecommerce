import type { Metadata } from "next";

/**
 * /terms — terms and conditions (spec section 6).
 *
 * Generic draft for a small Morocco-based online store. TODO: a real business
 * and/or legal professional must review this text before launch (Moroccan
 * e-commerce / consumer law may require different or additional clauses).
 */
export const metadata: Metadata = { title: "Terms & conditions" };

export default function TermsPage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Terms & conditions</h1>
      <p className="mt-3 text-xs text-neutral-400">
        Review note: these terms are a template draft and should be reviewed by
        a legal professional before launch.
      </p>

      <div className="mt-8 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">1. Agreement</h2>
          <p className="mt-3">
            By placing an order on Luce Bianca, you agree to these terms. Please
            read them carefully before completing your purchase. If you do not
            agree with any part of these terms, please do not use the store.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">2. Products & pricing</h2>
          <p className="mt-3">
            All prices are shown in Moroccan Dirham (MAD) and include applicable
            taxes unless stated otherwise. We make every effort to display
            products and prices accurately, but we reserve the right to correct
            pricing or product errors and to cancel or refuse orders affected by
            such errors.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">3. Orders</h2>
          <p className="mt-3">
            An order is placed when you submit it through the checkout and is
            confirmed once we confirm it by phone or email. We reserve the right
            to decline or cancel an order — for example, when stock is
            unavailable or payment cannot be confirmed. If your order is
            cancelled after payment, you will be refunded.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">4. Payments</h2>
          <p className="mt-3">
            We accept cash on delivery, payment by WhatsApp arrangement, and
            card payments where offered. Payment details are processed
            securely, and we never store your full card information.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">5. Delivery</h2>
          <p className="mt-3">
            Delivery times and costs are described on the{" "}
            <a href="/shipping" className="font-medium text-neutral-900 underline underline-offset-4">
              shipping page
            </a>
            . Risk of loss passes to you once the order is delivered, though we
            retain ownership of the goods until payment is completed.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">6. Returns & exchanges</h2>
          <p className="mt-3">
            Our return and exchange policy is described on the{" "}
            <a href="/returns" className="font-medium text-neutral-900 underline underline-offset-4">
              returns page
            </a>
            . Your right to return goods may also be protected by applicable
            consumer law, which takes precedence where it grants you more.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">7. Intellectual property</h2>
          <p className="mt-3">
            All content on this site — including designs, artwork, text and
            graphics — is the property of Luce Bianca and may not be reproduced
            or used commercially without permission.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">8. Limitation of liability</h2>
          <p className="mt-3">
            To the fullest extent permitted by law, Luce Bianca&rsquo;s liability
            for any claim related to your order is limited to the amount you
            paid for the affected products. We are not liable for indirect or
            consequential losses.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">9. Contact</h2>
          <p className="mt-3">
            Questions about these terms can be sent through the{" "}
            <a href="/contact" className="font-medium text-neutral-900 underline underline-offset-4">
              contact page
            </a>
            .
          </p>
        </section>
      </div>
    </main>
  );
}