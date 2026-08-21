import type { Metadata } from "next";

export const metadata: Metadata = { title: "About" };

/**
 * /about — the Luce Bianca brand story (spec section 6).
 *
 * Brand story + the "Casual Luxury" philosophy + brand vision. Draft copy —
 * the exact founding narrative and details are placeholders for the owner to
 * refine with the real story.
 */
export default function AboutPage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">About Luce Bianca</h1>
      <p className="mt-3 text-sm text-neutral-500">Casual Luxury · Made in Morocco</p>

      <div className="mt-10 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">Our story</h2>
          <p className="mt-3">
            Luce Bianca was born from a simple observation: the most-loved
            t-shirt is the one you reach for without thinking. We started with
            a single idea — that everyday basics deserve the same care,
            fabric and finishing as anything else in your wardrobe — and built
            a small collection of pieces designed to be worn often, washed
            well, and kept for years.
          </p>
          <p className="mt-4">
            Every design is created in-house, printed on carefully selected
            cotton, and finished with an eye for detail. We keep our
            collection small on purpose: fewer pieces, made properly, at a
            price that stays honest.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">The Casual Luxury philosophy</h2>
          <p className="mt-3">
            &ldquo;Casual luxury&rdquo; is the space between throwaway fast
            fashion and formal dressing. It means relaxed fits and quiet
            design, but with premium fabrics, clean construction, and details
            you notice the longer you own the piece. Nothing loud, nothing
            disposable — just confidence in the everyday.
          </p>
          <p className="mt-4">
            It also means an oversized fit that drapes rather than hangs, soft
            hands of fabric against the skin, and a wardrobe staple that
            elevates the simplest outfit.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">Our vision</h2>
          <p className="mt-3">
            We want Luce Bianca to be the t-shirt you remember: the one that
            survived a hundred washes, went everywhere with you, and still
            looks considered. We&rsquo;re building a brand around quality over
            quantity — and a shopping experience that feels as calm and
            considered as the clothes themselves.
          </p>
          <p className="mt-4">
            Based in Morocco, we ship across the country and beyond, and we
            are committed to honest pricing, clear communication, and a
            personal touch in every order.
          </p>
        </section>
      </div>
    </main>
  );
}