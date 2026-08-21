import type { Metadata } from "next";

import LoginView from "./login-view";

export const metadata: Metadata = { title: "Sign in" };

export default function LoginPage() {
  return <LoginView />;
}