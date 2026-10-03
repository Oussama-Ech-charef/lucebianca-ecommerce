"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Settings = Record<string, string>;

export default function SettingsClient() {
  const router = useRouter();
  const [settings, setSettings] = useState<Settings>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [activeTab, setActiveTab] = useState<"store" | "shipping" | "payments" | "notifications">("store");

  useEffect(() => {
    async function fetchSettings() {
      setLoading(true);
      setError(null);

      try {
        const session = getAdminSession();
        if (session === null) {
          setError("Your session has expired. Please sign in again.");
          clearAdminSession();
          router.replace("/admin/login");
          setLoading(false);
          return;
        }

        const response = await adminRequest("/api/admin/settings", session);

        if (response.status === 401) {
          setError("Your session has expired. Please sign in again.");
          clearAdminSession();
          router.replace("/admin/login");
          return;
        }

        if (!response.ok) {
          throw new Error("Failed to fetch settings");
        }

        const result = await response.json();
        setSettings(result.data || {});
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load settings");
      } finally {
        setLoading(false);
      }
    }

    fetchSettings();
  }, [router]);

  function updateSetting(key: string, value: string) {
    setSettings((prev) => ({ ...prev, [key]: value }));
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    setSuccess(false);

    try {
      const session = getAdminSession();
      if (session === null) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        setSaving(false);
        return;
      }

      const response = await adminRequest("/api/admin/settings", session, {
        method: "PUT",
        body: settings,
      });

      if (response.status === 401) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      if (response.ok) {
        setSuccess(true);
        setTimeout(() => setSuccess(false), 3000);
      } else {
        throw new Error("Failed to save settings");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save settings");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading settings...</p>
      </div>
    );
  }

  const inputClass =
    "w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";
  const labelClass = "mb-1.5 block text-sm font-medium text-neutral-700";

  return (
    <div className="p-6">
      <h1 className="mb-6 text-2xl font-semibold">Settings</h1>

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {success && (
        <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
          Settings saved successfully!
        </div>
      )}

      <div className="mb-6 -mx-6 overflow-x-auto px-6">
        <div className="flex gap-2 border-b border-neutral-200">
          <button
            onClick={() => setActiveTab("store")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "store"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Store Info
          </button>
          <button
            onClick={() => setActiveTab("shipping")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "shipping"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Shipping
          </button>
          <button
            onClick={() => setActiveTab("payments")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "payments"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Payments
          </button>
          <button
            onClick={() => setActiveTab("notifications")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "notifications"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Notifications
          </button>
        </div>
      </div>

      <div className="max-w-3xl space-y-6">
        {activeTab === "store" && (
          <>
            <h2 className="text-lg font-medium">Store Information</h2>
            <div>
              <label htmlFor="admin-store-name" className={labelClass}>Store Name</label>
              <input
                id="admin-store-name"
                type="text"
                value={settings.store_name || ""}
                onChange={(e) => updateSetting("store_name", e.target.value)}
                className={inputClass}
              />
            </div>
            <div>
              <label htmlFor="admin-store-email" className={labelClass}>Store Email</label>
              <input
                id="admin-store-email"
                type="email"
                value={settings.store_email || ""}
                onChange={(e) => updateSetting("store_email", e.target.value)}
                className={inputClass}
              />
            </div>
            <div>
              <label htmlFor="admin-store-phone" className={labelClass}>Store Phone</label>
              <input
                id="admin-store-phone"
                type="text"
                value={settings.store_phone || ""}
                onChange={(e) => updateSetting("store_phone", e.target.value)}
                className={inputClass}
              />
            </div>
            <div>
              <label htmlFor="admin-whatsapp" className={labelClass}>WhatsApp Number</label>
              <input
                id="admin-whatsapp"
                type="text"
                value={settings.whatsapp_number || ""}
                onChange={(e) => updateSetting("whatsapp_number", e.target.value)}
                className={inputClass}
                placeholder="212612345678"
              />
            </div>
            <div>
              <label htmlFor="admin-store-address" className={labelClass}>Store Address</label>
              <textarea
                id="admin-store-address"
                rows={3}
                value={settings.store_address || ""}
                onChange={(e) => updateSetting("store_address", e.target.value)}
                className={inputClass}
              />
            </div>
          </>
        )}

        {activeTab === "shipping" && (
          <>
            <h2 className="text-lg font-medium">Shipping Settings</h2>
            <div>
              <label className={labelClass}>Default Shipping Cost (MAD)</label>
              <input
                type="text"
                value={settings.shipping_cost || ""}
                onChange={(e) => updateSetting("shipping_cost", e.target.value)}
                className={inputClass}
                placeholder="30.00"
              />
            </div>
            <div>
              <label className={labelClass}>Free Shipping Threshold (MAD)</label>
              <input
                type="text"
                value={settings.free_shipping_threshold || ""}
                onChange={(e) => updateSetting("free_shipping_threshold", e.target.value)}
                className={inputClass}
                placeholder="500.00"
              />
              <p className="mt-1 text-xs text-neutral-600">
                Orders above this amount get free shipping
              </p>
            </div>
          </>
        )}

        {activeTab === "payments" && (
          <>
            <h2 className="text-lg font-medium">Payment Methods</h2>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.payment_cod_enabled === "1"}
                  onChange={(e) =>
                    updateSetting("payment_cod_enabled", e.target.checked ? "1" : "0")
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Cash on Delivery</span>
              </label>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.payment_whatsapp_enabled === "1"}
                  onChange={(e) =>
                    updateSetting("payment_whatsapp_enabled", e.target.checked ? "1" : "0")
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Order via WhatsApp</span>
              </label>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.payment_card_enabled === "1"}
                  onChange={(e) =>
                    updateSetting("payment_card_enabled", e.target.checked ? "1" : "0")
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Credit/Debit Card (CMI)</span>
              </label>
            </div>
          </>
        )}

        {activeTab === "notifications" && (
          <>
            <h2 className="text-lg font-medium">Email Notifications</h2>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.notify_admin_new_order === "1"}
                  onChange={(e) =>
                    updateSetting("notify_admin_new_order", e.target.checked ? "1" : "0")
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Admin: New Order</span>
              </label>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.notify_admin_low_stock === "1"}
                  onChange={(e) =>
                    updateSetting("notify_admin_low_stock", e.target.checked ? "1" : "0")
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Admin: Low Stock Alert</span>
              </label>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.notify_customer_order_confirmation === "1"}
                  onChange={(e) =>
                    updateSetting(
                      "notify_customer_order_confirmation",
                      e.target.checked ? "1" : "0"
                    )
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Customer: Order Confirmation</span>
              </label>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.notify_customer_shipping_update === "1"}
                  onChange={(e) =>
                    updateSetting(
                      "notify_customer_shipping_update",
                      e.target.checked ? "1" : "0"
                    )
                  }
                  className="h-4 w-4"
                />
                <span className="text-sm font-medium">Customer: Shipping Status Updates</span>
              </label>
            </div>
          </>
        )}

        <div className="border-t border-neutral-200 pt-6">
          <button
            onClick={handleSave}
            disabled={saving}
            className="rounded-lg bg-neutral-900 px-6 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
          >
            {saving ? "Saving..." : "Save Settings"}
          </button>
        </div>
      </div>
    </div>
  );
}
