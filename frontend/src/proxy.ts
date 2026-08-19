import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Proxy — server-side gate for the admin area (Next 16: middleware was
 * renamed to proxy.ts).
 *
 * The admin session lives in localStorage (needed for the Bearer header on
 * API calls), which the server can't read, so login ALSO writes a dedicated
 * `lb_admin_session` cookie (see src/lib/admin.ts). This proxy checks that
 * cookie before any /admin/* page renders and redirects to /admin/login when
 * it's missing — a direct navigation to /admin/orders never flashes protected
 * content. It checks presence only (the actual JWT is verified by the API);
 * the protected shell's client guard covers cookie/localStorage desync and
 * expired tokens by clearing the session and redirecting.
 *
 * /admin/login is deliberately public.
 */

const SESSION_COOKIE = "lb_admin_session";
const LOGIN_PATH = "/admin/login";
const ORDERS_PATH = "/admin/orders";

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  if (pathname === LOGIN_PATH) {
    return NextResponse.next();
  }

  const hasSession = Boolean(request.cookies.get(SESSION_COOKIE)?.value);

  if (pathname === "/admin") {
    return NextResponse.redirect(
      new URL(hasSession ? ORDERS_PATH : LOGIN_PATH, request.url),
    );
  }

  if (!hasSession) {
    return NextResponse.redirect(new URL(LOGIN_PATH, request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/admin/:path*"],
};