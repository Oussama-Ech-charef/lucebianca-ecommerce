/**
 * WhatsApp deep links for the storefront.
 *
 * WHATSAPP_NUMBER is a placeholder — replace with the store's real number
 * before launch. International format: 212XXXXXXXXX.
 *
 * Used by the floating chat button and by the checkout's "Order via
 * WhatsApp" payment method (spec 3.7).
 */
export const WHATSAPP_NUMBER = "212612345678";

export function buildWhatsAppLink(message: string): string {
  return `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`;
}

export const WHATSAPP_GENERIC_MESSAGE =
  "Hello Luce Bianca! I'm interested in your collection.";