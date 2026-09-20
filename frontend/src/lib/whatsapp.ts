/**
 * WhatsApp deep links for the storefront.
 *
 * WHATSAPP_NUMBER is a fallback placeholder — the live number is stored
 * in `settings.whatsapp_number` (admin: Store Info → WhatsApp Number) and
 * exposed via GET /api/settings/public. International format: 212XXXXXXXXX.
 * Centralized fallback lives in `lib/config.ts` (`WHATSAPP_FALLBACK`) to keep
 * TODOs in one launch checklist; this re-export keeps existing imports working.
 * Runtime consumers should prefer `whatsapp_number` from public settings.
 */
import { WHATSAPP_FALLBACK } from "./config";

export const WHATSAPP_NUMBER = WHATSAPP_FALLBACK;

export function buildWhatsAppLink(message: string, number?: string): string {
  const normalized = (number ?? WHATSAPP_FALLBACK).replace(/[^0-9]/g, "");
  return `https://wa.me/${normalized}?text=${encodeURIComponent(message)}`;
}

export const WHATSAPP_GENERIC_MESSAGE =
  "Hello Luce Bianca! I'm interested in your collection.";