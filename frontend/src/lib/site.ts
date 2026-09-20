/**
 * Canonical site URL (phase 11 — SEO).
 *
 * Re-exported from centralized `config.ts` so the launch checklist has one
 * place to verify `NEXT_PUBLIC_SITE_URL`. The fallback stays
 * `http://localhost:3000` for `next dev` so the storefront still renders if
 * the env is missing — use `warnIfPlaceholderConfig()` in production to
 * surface the placeholder instead of inventing a domain.
 */
export {
  SITE_URL,
  OG_IMAGE_FALLBACK as OG_IMAGE,
  isProductionSite,
  warnIfPlaceholderConfig,
} from "./config";