import type { Metadata } from "next";

import LoginView from "./login-view";

export const metadata: Metadata = { title: "Sign in" , robots: { index: false, follow: false } };

export default function LoginPage() {
  return <LoginView />;
}