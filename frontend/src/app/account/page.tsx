import type { Metadata } from "next";

import AccountView from "./account-view";

export const metadata: Metadata = { title: "My account" };

export default function AccountPage() {
  return <AccountView />;
}