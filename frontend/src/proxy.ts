import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Proxy — UX gate for the admin area (Next 16: middleware was
 * renamed to proxy.ts).
 *
 * This cookie-presence check is NOT the security boundary: every
 * /api/admin/* endpoint verifies the admin JWT server-side via
 * AuthMiddleware('admin'), and the protected shell re-verifies it against
 * GET /api/admin/auth/me on mount (clearing + redirecting on 401/404).
 * The proxy only prevents a flash of protected UI on direct navigation;
 * forging the cookie alone grants zero API access.
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