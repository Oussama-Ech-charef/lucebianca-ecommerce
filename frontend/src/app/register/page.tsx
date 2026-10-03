import type { Metadata } from "next";

import RegisterView from "./register-view";

export const metadata: Metadata = { title: "Create an account" , robots: { index: false, follow: false } };

export default function RegisterPage() {
  return <RegisterView />;
}