import type { Metadata } from "next";

/**
 * /privacy — privacy policy (spec section 6).
 *
 * Generic draft for a small Morocco-based online store. TODO: a real business
 * and/or legal professional must review this text before launch (local
 * data-protection law and the store's actual data practices may require
 * changes).
 */
export const metadata: Metadata = { title: "Privacy policy" };

export default function PrivacyPage() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Privacy policy</h1>
      <p className="mt-3 text-xs text-neutral-400">
        Review note: this policy is a template draft and should be reviewed by a
        legal professional before launch.
      </p>

      <div className="mt-8 space-y-6 text-sm leading-7 text-neutral-700">
        <section>
          <h2 className="font-serif text-xl text-neutral-900">1. What we collect</h2>
          <p className="mt-3">
            We collect only the information needed to process and deliver your
            orders: your name, email address, phone number and shipping
            address. When you create an account, we store your account details
            so you can view your order history. Contact-form messages include
            your name, email and the message content.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">2. How we use it</h2>
          <ul className="mt-3 list-disc space-y-2 pl-5">
            <li>Processing, confirming and delivering your orders.</li>
            <li>Communicating with you about your order.</li>
            <li>Providing customer support through the contact form.</li>
            <li>Meeting legal and accounting requirements.</li>
          </ul>
          <p className="mt-4">
            We do not sell your personal information to third parties.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">3. How we store it</h2>
          <p className="mt-3">
            Your data is stored securely in our systems and is accessible only
            to staff who need it to serve you. Passwords are stored as secure
            hashes and are never readable by anyone, including us. Payment
            card numbers are not stored on our servers.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">4. How long we keep it</h2>
          <p className="mt-3">
            Order records are kept for accounting and legal purposes as required
            by law. Account information is kept for as long as your account is
            active; you may ask us to delete it at any time, subject to legal
            retention requirements.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">5. Your rights</h2>
          <p className="mt-3">
            You may request a copy of the personal information we hold about
            you, ask us to correct inaccurate information, or ask us to delete
            your data (where the law allows). To exercise any of these rights,
            contact us through the{" "}
            <a href="/contact" className="font-medium text-neutral-900 underline underline-offset-4">
              contact page
            </a>
            .
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">6. Cookies</h2>
          <p className="mt-3">
            The site uses local browser storage to remember your cart and login
            session on your own device. We do not use these for advertising
            tracking. You can clear this data at any time through your browser
            settings.
          </p>
        </section>

        <section>
          <h2 className="font-serif text-xl text-neutral-900">7. Changes to this policy</h2>
          <p className="mt-3">
            We may update this policy from time to time. Significant changes
            will be reflected on this page with the updated date.
          </p>
        </section>
      </div>
    </main>
  );
}