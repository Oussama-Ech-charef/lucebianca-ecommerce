"use client";

import { useState, type FormEvent } from "react";

import { submitContactMessage } from "@/lib/storefront";

/**
 * /contact — working contact form with anti-spam protection.
 *
 * Posts to POST /api/contact (server-side validated; stored in
 * contact_messages). Success and error states use the storefront's alert
 * language — green for success, red for errors — so the message type is
 * distinct at a glance. On success the form clears and the confirmation stays
 * visible. Anti-spam: honeypot field (invisible to humans) + rate limiting.
 */

const inputClass =
  "mt-1.5 w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none";

export default function ContactView() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [website, setWebsite] = useState(""); // Honeypot field — bots fill it, humans never see it
  const [status, setStatus] = useState<
    "idle" | "submitting" | "success" | "error"
  >("idle");
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatus("submitting");
    setError(null);

    const trimmedName = name.trim();
    const trimmedEmail = email.trim();
    const trimmedMessage = message.trim();

    if (trimmedName === "" || trimmedEmail === "" || trimmedMessage === "") {
      setStatus("error");
      setError("Please fill in your name, email and message.");
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
      setStatus("error");
      setError("Please enter a valid email address.");
      return;
    }
    if (trimmedMessage.length < 10) {
      setStatus("error");
      setError("Message must be at least 10 characters.");
      return;
    }

    try {
      await submitContactMessage({
        name: trimmedName,
        email: trimmedEmail,
        message: trimmedMessage,
        website, // Honeypot — should always be empty for real users
      });
      setName("");
      setEmail("");
      setMessage("");
      setWebsite("");
      setStatus("success");
    } catch (err) {
      setStatus("error");
      setError(
        err instanceof Error ? err.message : "Your message could not be sent. Please try again.",
      );
    }
  }

  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-14">
      <h1 className="font-serif text-3xl">Contact us</h1>
      <p className="mt-3 text-sm text-neutral-500">
        Questions about an order, a product, or anything else? Send us a
        message and we&rsquo;ll get back to you.
      </p>

      {status === "success" ? (
        <div
          role="status"
          className="mt-8 rounded-lg border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-700"
        >
          <p className="font-medium">Message sent</p>
          <p className="mt-1">
            Thanks for reaching out — we&rsquo;ll reply to your email shortly.
          </p>
        </div>
      ) : null}

      <form onSubmit={handleSubmit} className="mt-8" noValidate>
        {/* Honeypot field — hidden from real users, but bots will fill it */}
        <div style={{ position: "absolute", left: "-9999px" }} aria-hidden="true">
          <label htmlFor="contact-website">Website</label>
          <input
            id="contact-website"
            type="text"
            name="website"
            tabIndex={-1}
            autoComplete="off"
            value={website}
            onChange={(event) => setWebsite(event.target.value)}
          />
        </div>

        <label
          htmlFor="contact-name"
          className="block text-sm font-medium text-neutral-700"
        >
          Name
        </label>
        <input
          id="contact-name"
          type="text"
          autoComplete="name"
          required
          value={name}
          onChange={(event) => setName(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="contact-email"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Email
        </label>
        <input
          id="contact-email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          className={inputClass}
        />

        <label
          htmlFor="contact-message"
          className="mt-5 block text-sm font-medium text-neutral-700"
        >
          Message
        </label>
        <textarea
          id="contact-message"
          rows={6}
          required
          value={message}
          onChange={(event) => setMessage(event.target.value)}
          className={inputClass}
        />

        {status === "error" ? (
          <p
            role="alert"
            className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
          >
            {error}
          </p>
        ) : null}

        <button
          type="submit"
          disabled={status === "submitting"}
          className="mt-6 rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {status === "submitting" ? "Sending…" : "Send message"}
        </button>
      </form>
    </main>
  );
}