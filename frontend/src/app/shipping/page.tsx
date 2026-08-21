import type { Metadata } from "next";

/**
 * /shipping — supported regions, costs, timelines and the free-shipping
 * threshold (spec section 6).
 *
 * TODO (phase 15): these values are REASONABLE PLACEHOLDERS. The spec puts
 * shipping settings in /admin/settings (supported regions, cost per region,
 * free-shipping threshold), which is not built yet — once it exists, this
 * page must read the live settings table instead of this hardcoded list.
 */
const SHIPPING_OPTIONS: {
  region: string;
  cost: string;
  time: string;
}[] = [
  { region: "Casablanca", cost: "25 MAD", time: "1–2 business days" },
  { region: "Other Moroccan cities", cost: "45 MAD", time: "2–4 business days" },
  { region: "International", cost: "200 MAD", time: "7–14 business days" },
];

export const metadata: Metadata = { title: "Shipping" };

export default function ShippingPage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Shipping</h1>
      <p className="mt-3 text-sm text-neutral-500">
        Where we deliver, what it costs, and when it arrives.
      </p>

      <div className="mt-8 rounded-lg border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-700">
        <p className="font-medium">Free shipping on orders over 500 MAD</p>
        <p className="mt-1">
          Orders within Morocco qualify for free delivery when your total
          reaches the threshold.
        </p>
      </div>

      <div className="mt-8 overflow-x-auto rounded-lg border border-neutral-200">
        <table className="w-full min-w-[440px] border-collapse text-sm">
          <thead>
            <tr className="bg-neutral-50 text-left text-xs uppercase tracking-wider text-neutral-500">
              <th scope="col" className="px-4 py-3 font-medium">
                Region
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Cost
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Delivery time
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-100">
            {SHIPPING_OPTIONS.map((option) => (
              <tr key={option.region}>
                <th scope="row" className="px-4 py-3 font-medium text-neutral-900">
                  {option.region}
                </th>
                <td className="px-4 py-3 tabular-nums text-neutral-700">{option.cost}</td>
                <td className="px-4 py-3 text-neutral-700">{option.time}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-8 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">Order processing</h2>
          <p className="mt-3">
            Orders are packed and dispatched within 1–2 business days of being
            confirmed. Cash-on-delivery orders are confirmed by phone before
            dispatch, which can add a day to the timeline.
          </p>
        </section>
        <section>
          <h2 className="font-serif text-xl text-neutral-900">Tracking</h2>
          <p className="mt-3">
            Once your order ships, the store contacts you on the phone number
            provided to confirm the delivery details and timing. International
            orders include a tracking number shared by email or WhatsApp.
          </p>
        </section>
      </div>
    </main>
  );
}