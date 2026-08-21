import type { Metadata } from "next";

/**
 * /returns — return & exchange policy (spec section 6).
 *
 * Generic draft for a small Morocco-based online store. TODO: a real business
 * and/or legal professional must review this text before launch (Moroccan
 * consumer-protection law and the actual returns process may require changes).
 */
export const metadata: Metadata = { title: "Returns & exchanges" };

export default function ReturnsPage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Returns & exchanges</h1>
      <p className="mt-3 text-xs text-neutral-400">
        Review note: this policy is a template draft and should be reviewed by
        a legal professional before launch.
      </p>

      <div className="mt-8 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">Our policy</h2>
          <p className="mt-3">
            We want you to love your order. If something is not right, you can
            request a return or exchange within <strong className="font-medium text-neutral-900">14 days</strong> of
            receiving your item.
          </p>
          <ul className="mt-3 list-disc space-y-2 pl-5">
            <li>The item must be unworn, unwashed and in its original condition.</li>
            <li>All original tags and packaging should be intact.</li>
            <li>Returns are not available on sale or final-sale items.</li>
          </ul>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">How to start a return</h2>
          <p className="mt-3">
            Contact us through the{" "}
            <a href="/contact" className="font-medium text-neutral-900 underline underline-offset-4">
              contact page
            </a>{" "}
            with your order number and the reason for the return. We will
            confirm the details and arrange the pickup or return address.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">Refunds</h2>
          <p className="mt-3">
            Once we receive and inspect the returned item, we process the
            refund to your original payment method within <strong className="font-medium text-neutral-900">5–7 business
            days</strong>. For cash-on-delivery orders, refunds are made by bank
            transfer to the account you provide.
          </p>
          <p className="mt-4">
            Return shipping costs are covered by the customer unless the item
            arrived damaged or incorrect, in which case we cover the cost.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">Exchanges</h2>
          <p className="mt-3">
            If you ordered the wrong size, we will happily exchange it for the
            correct one, subject to availability. Exchanges are processed
            once the original item is returned and inspected.
          </p>
        </section>
      </div>
    </main>
  );
}