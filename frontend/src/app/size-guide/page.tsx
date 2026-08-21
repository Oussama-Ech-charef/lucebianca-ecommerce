import type { Metadata } from "next";

/**
 * /size-guide — fit chart in centimeters per size (spec section 6).
 *
 * TODO (owner): these measurements are REASONABLE PLACEHOLDERS. Replace them
 * with the real garment measurements (measured flat across the chest, length
 * from shoulder to hem, shoulder width, sleeve length) once the sample sizes
 * are measured. Fits in this store are intentionally oversized.
 */
const SIZE_CHART: { size: string; chest: string; length: string; shoulder: string; sleeve: string }[] = [
  { size: "XS",  chest: "94", length: "68", shoulder: "44", sleeve: "20" },
  { size: "S",   chest: "102", length: "71", shoulder: "47", sleeve: "21" },
  { size: "M",   chest: "110", length: "74", shoulder: "50", sleeve: "22" },
  { size: "L",   chest: "118", length: "77", shoulder: "53", sleeve: "23" },
  { size: "XL",  chest: "126", length: "80", shoulder: "56", sleeve: "24" },
  { size: "XXL", chest: "134", length: "83", shoulder: "59", sleeve: "25" },
];

export const metadata: Metadata = { title: "Size guide" };

export default function SizeGuidePage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Size guide</h1>
      <p className="mt-3 text-sm text-neutral-500">
        Measurements are in centimeters, taken flat on a laid-out garment.
      </p>

      <div className="mt-8 overflow-x-auto rounded-lg border border-neutral-200">
        <table className="w-full min-w-[480px] border-collapse text-sm">
          <thead>
            <tr className="bg-neutral-50 text-left text-xs uppercase tracking-wider text-neutral-500">
              <th scope="col" className="px-4 py-3 font-medium">
                Size
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Chest
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Length
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Shoulder
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Sleeve
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-100">
            {SIZE_CHART.map((row) => (
              <tr key={row.size}>
                <th scope="row" className="px-4 py-3 font-medium text-neutral-900">
                  {row.size}
                </th>
                <td className="px-4 py-3 tabular-nums text-neutral-700">{row.chest} cm</td>
                <td className="px-4 py-3 tabular-nums text-neutral-700">{row.length} cm</td>
                <td className="px-4 py-3 tabular-nums text-neutral-700">{row.shoulder} cm</td>
                <td className="px-4 py-3 tabular-nums text-neutral-700">{row.sleeve} cm</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-8 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">How to choose your size</h2>
          <p className="mt-3">
            Our fits are deliberately <strong className="font-medium text-neutral-900">oversized</strong> — roomier
            through the chest and shoulder, with a relaxed drape. If you prefer
            a classic, closer fit, we suggest taking <strong className="font-medium text-neutral-900">one size down</strong>;
            if you like the loose, casual-luxury silhouette, take your usual size.
          </p>
          <p className="mt-4">
            Unsure between two sizes? Go with the larger one — the oversized cut
            is the point of the design, and an easy fit always looks more
            considered than one that pulls at the shoulders.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">Measuring tips</h2>
          <ul className="mt-3 list-disc space-y-2 pl-5">
            <li>
              <strong className="font-medium text-neutral-900">Chest:</strong> measure around the fullest part of your
              chest, keeping the tape level under the arms.
            </li>
            <li>
              <strong className="font-medium text-neutral-900">Length:</strong> from the top of the shoulder at the
              collar down to the hem.
            </li>
            <li>
              <strong className="font-medium text-neutral-900">Shoulder:</strong> straight across from shoulder seam to
              shoulder seam.
            </li>
            <li>
              <strong className="font-medium text-neutral-900">Sleeve:</strong> from the shoulder seam to the cuff edge.
            </li>
          </ul>
          <p className="mt-4 text-xs text-neutral-400">
            Note: measurements are approximate guides. Final garment
            measurements will be confirmed with the real samples.
          </p>
        </section>
      </div>
    </main>
  );
}