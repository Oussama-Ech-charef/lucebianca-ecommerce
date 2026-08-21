"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { googleLogin, saveCustomerSession } from "@/lib/customer-auth";

/**
 * "Sign in with Google" (Google Identity Services, phase 13, fixed phase 15).
 *
 * Only rendered as an interactive button when NEXT_PUBLIC_GOOGLE_CLIENT_ID is
 * set (it must match the API's GOOGLE_CLIENT_ID, which also validates `aud`).
 *
 * The GIS library (https://accounts.google.com/gsi/client) is NOT loaded
 * globally anywhere in the app, so it is injected here on demand — async,
 * defer, once, cached at module level. The component does not assume the
 * script is ready on mount: it awaits loadGoogleIdentityServices(), so a
 * mount that happens before the script finishes loading still renders the
 * button as soon as GIS is available (rather than silently giving up).
 *
 * The id_token callback hands the JWT to POST /api/auth/google, which verifies
 * it server-side and creates-or-links the account by verified email before
 * issuing a customer session.
 *
 * When the client id is not configured, the button renders disabled with a
 * short note instead of shipping a dead control. A GIS script load failure
 * renders an error message rather than an invisible, hung control.
 */

const GOOGLE_CLIENT_ID = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID ?? "";

const GSI_SCRIPT_URL = "https://accounts.google.com/gsi/client";

type GoogleAccounts = {
  accounts: {
    id: {
      initialize: (config: {
        client_id: string;
        callback: (response: { credential?: string }) => void;
      }) => void;
      renderButton: (
        element: HTMLElement,
        options: { type: string; shape: string; width: string },
      ) => void;
    };
  };
};

type GoogleCredentialResponse = { credential?: string };

function getGoogleAccounts(): GoogleAccounts | null {
  return (
    (window as unknown as { google?: GoogleAccounts }).google?.accounts?.id
      ? (window as unknown as { google?: GoogleAccounts }).google!
      : null
  );
}

// Module-level, so the script is injected at most once per page load and any
// component mounting late reuses the same in-flight promise.
let gsiLoadPromise: Promise<GoogleAccounts> | null = null;

function loadGoogleIdentityServices(): Promise<GoogleAccounts> {
  if (gsiLoadPromise !== null) {
    return gsiLoadPromise;
  }

  gsiLoadPromise = new Promise<GoogleAccounts>((resolve, reject) => {
    if (typeof window === "undefined") {
      reject(new Error("Google Identity Services cannot load on the server."));
      return;
    }

    const existing = getGoogleAccounts();
    if (existing !== null) {
      resolve(existing);
      return;
    }

    const script = document.createElement("script");
    script.src = GSI_SCRIPT_URL;
    script.async = true;
    script.defer = true;

    script.onload = () => {
      const loaded = getGoogleAccounts();
      if (loaded !== null) {
        resolve(loaded);
      } else {
        reject(new Error("Google Identity Services loaded but window.google is unavailable."));
      }
    };
    script.onerror = () => {
      reject(new Error("Failed to load Google Identity Services."));
      gsiLoadPromise = null; // allow a retry on the next mount
    };

    document.head.appendChild(script);
  });

  return gsiLoadPromise;
}

export default function GoogleSignInButton() {
  const router = useRouter();
  const hostRef = useRef<HTMLDivElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const renderedRef = useRef(false);

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID) {
      return;
    }
    let cancelled = false;

    loadGoogleIdentityServices()
      .then((google) => {
        if (cancelled || renderedRef.current || !hostRef.current) {
          return;
        }

        google.accounts.id.initialize({
          client_id: GOOGLE_CLIENT_ID,
          callback: async (response: GoogleCredentialResponse) => {
            if (!response.credential) {
              setError("Google sign-in was cancelled.");
              return;
            }
            setBusy(true);
            setError(null);
            try {
              const payload = await googleLogin(response.credential);
              saveCustomerSession(payload);
              router.push("/account");
            } catch (err) {
              setError(
                err instanceof Error
                  ? err.message
                  : "Google sign-in failed. Try again.",
              );
              setBusy(false);
            }
          },
        });

        google.accounts.id.renderButton(hostRef.current, {
          type: "standard",
          shape: "rectangular",
          width: "100%",
        });
        renderedRef.current = true;
      })
      .catch((err: unknown) => {
        if (cancelled) {
          return;
        }
        setError(
          err instanceof Error
            ? err.message
            : "Google sign-in is temporarily unavailable.",
        );
      });

    return () => {
      cancelled = true;
    };
  }, [router]);

  if (!GOOGLE_CLIENT_ID) {
    return (
      <div>
        <button
          type="button"
          disabled
          className="w-full cursor-not-allowed rounded-lg border border-neutral-300 bg-white px-4 py-3 text-sm font-medium text-neutral-400"
        >
          Sign in with Google
        </button>
        <p className="mt-2 text-center text-xs text-neutral-400">
          Google sign-in is not configured yet.
        </p>
      </div>
    );
  }

  return (
    <div>
      <div
        ref={hostRef}
        aria-busy={busy}
        aria-hidden={busy}
        className={busy ? "pointer-events-none opacity-60" : ""}
      />
      {error !== null ? (
        <p
          role="alert"
          className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {error}
        </p>
      ) : null}
    </div>
  );
}