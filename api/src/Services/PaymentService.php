<?php

namespace App\Services;

use App\Core\Env;

/**
 * PaymentService — CMI/Payzone payment gateway integration.
 *
 * Handles card payment initiation and callback verification for the CMI/Payzone
 * gateway. All sensitive credentials (client ID, store key, hash key) are read
 * from environment variables only.
 *
 * Flow:
 * 1. Client initiates payment → backend generates payment request → redirect to CMI
 * 2. Customer completes payment on CMI hosted page
 * 3. CMI calls back to our callback URL → we verify signature and update order
 *
 * Security:
 * - All requests signed with HMAC hash for integrity
 * - Callback verification prevents payment status tampering
 * - No credentials stored in database or logs
 */
final class PaymentService
{
    private string $clientId;
    private string $storeKey;
    private string $hashKey;
    private string $gatewayUrl;
    private string $callbackUrl;

    public function __construct()
    {
        $this->clientId = Env::get('CMI_CLIENT_ID') ?? '';
        $this->storeKey = Env::get('CMI_STORE_KEY') ?? '';
        $this->hashKey = Env::get('CMI_HASH_KEY') ?? '';
        $this->gatewayUrl = Env::get('CMI_GATEWAY_URL') ?? 'https://payment.cmi.co.ma/fim/est3Dgate';
        $this->callbackUrl = Env::get('CMI_CALLBACK_URL') ?? '';

        if ($this->clientId === '' || $this->storeKey === '' || $this->hashKey === '') {
            throw new \RuntimeException('CMI payment credentials not configured. Please set CMI_CLIENT_ID, CMI_STORE_KEY, and CMI_HASH_KEY environment variables.');
        }
        if ($this->callbackUrl === '') {
            throw new \RuntimeException('CMI_CALLBACK_URL not configured.');
        }
    }

    /**
     * Initiates a payment request to CMI gateway.
     *
     * @param int    $orderId      Order ID (unique transaction reference)
     * @param string $amount       Total amount in MAD (e.g., "199.00")
     * @param string $currency     Currency code (default: "504" for MAD)
     * @param string $customerEmail Customer email for receipt
     *
     * @return array{gateway_url: string, form_fields: array<string, string>}
     *         Gateway URL and form fields to POST (redirect customer to payment page)
     */
    public function initiatePayment(
        int $orderId,
        string $amount,
        string $currency = '504',
        string $customerEmail = ''
    ): array {
        // Transaction reference must be unique
        $oid = 'LB-' . $orderId . '-' . time();

        // Build payment request parameters
        $params = [
            'clientid' => $this->clientId,
            'amount' => $amount,
            'currency' => $currency,
            'oid' => $oid,
            'okUrl' => $this->callbackUrl . '?status=success',
            'failUrl' => $this->callbackUrl . '?status=failure',
            'callbackUrl' => $this->callbackUrl,
            'shopurl' => Env::get('NEXT_PUBLIC_SITE_URL') ?? '',
            'trantype' => 'PreAuth', // PreAuth or Auth
            'storetype' => '3d_pay_hosting',
            'lang' => 'fr',
            'email' => $customerEmail,
            'BillToName' => '',
            'encoding' => 'UTF-8',
        ];

        // Generate hash for request integrity
        $params['hash'] = $this->generateRequestHash($params);
        $params['hashAlgorithm'] = 'ver3';

        return [
            'gateway_url' => $this->gatewayUrl,
            'form_fields' => $params,
        ];
    }

    /**
     * Verifies a payment callback from CMI gateway.
     *
     * Validates the signature to ensure the callback is authentic and hasn't
     * been tampered with.
     *
     * @param array<string, mixed> $callbackData POST data from CMI callback
     *
     * @return array{
     *     valid: bool,
     *     order_id: int|null,
     *     status: string,
     *     transaction_id: string|null,
     *     amount: string|null,
     *     message: string|null
     * }
     */
    public function verifyCallback(array $callbackData): array
    {
        $result = [
            'valid' => false,
            'order_id' => null,
            'status' => 'failed',
            'transaction_id' => null,
            'amount' => null,
            'message' => null,
        ];

        // Extract order ID from OID (format: LB-{orderId}-{timestamp})
        $oid = $callbackData['oid'] ?? '';
        if (preg_match('/^LB-(\d+)-\d+$/', $oid, $matches)) {
            $result['order_id'] = (int) $matches[1];
        }

        // Verify hash signature
        $receivedHash = $callbackData['HASH'] ?? '';
        $expectedHash = $this->generateCallbackHash($callbackData);

        if (!hash_equals($expectedHash, $receivedHash)) {
            $result['message'] = 'Invalid payment signature.';
            return $result;
        }

        // Parse payment status
        $procReturnCode = $callbackData['ProcReturnCode'] ?? '';
        $response = $callbackData['Response'] ?? '';

        $result['valid'] = true;
        $result['transaction_id'] = $callbackData['TransId'] ?? null;
        $result['amount'] = $callbackData['amount'] ?? null;

        // Success: ProcReturnCode = "00" and Response = "Approved"
        if ($procReturnCode === '00' && $response === 'Approved') {
            $result['status'] = 'paid';
            $result['message'] = 'Payment successful.';
        } else {
            $result['status'] = 'failed';
            $result['message'] = $callbackData['ErrMsg'] ?? 'Payment failed.';
        }

        return $result;
    }

    /**
     * Generates HMAC hash for payment request.
     *
     * @param array<string, string> $params Request parameters
     * @return string Base64-encoded hash
     */
    private function generateRequestHash(array $params): string
    {
        // CMI hash format: clientid + oid + amount + okUrl + failUrl + trantype + currency + storekey + hashAlgorithm
        $hashString = $params['clientid']
            . '|' . $params['oid']
            . '|' . $params['amount']
            . '|' . $params['okUrl']
            . '|' . $params['failUrl']
            . '|' . $params['trantype']
            . '|' . ''  // instalment (not used)
            . '|' . $params['currency']
            . '|' . $this->storeKey
            . '|' . 'ver3';

        return base64_encode(hash_hmac('sha512', $hashString, $this->hashKey, true));
    }

    /**
     * Generates HMAC hash for callback verification.
     *
     * @param array<string, mixed> $data Callback data
     * @return string Expected hash
     */
    private function generateCallbackHash(array $data): string
    {
        // CMI callback hash format: clientid + oid + AuthCode + ProcReturnCode + Response + mdStatus + cavv + eci + md + rnd + storekey + hashAlgorithm
        $hashString = ($data['clientid'] ?? '')
            . '|' . ($data['oid'] ?? '')
            . '|' . ($data['AuthCode'] ?? '')
            . '|' . ($data['ProcReturnCode'] ?? '')
            . '|' . ($data['Response'] ?? '')
            . '|' . ($data['mdStatus'] ?? '')
            . '|' . ($data['cavv'] ?? '')
            . '|' . ($data['eci'] ?? '')
            . '|' . ($data['md'] ?? '')
            . '|' . ($data['rnd'] ?? '')
            . '|' . $this->storeKey
            . '|' . 'ver3';

        return base64_encode(hash_hmac('sha512', $hashString, $this->hashKey, true));
    }

    /**
     * Check if CMI payment is configured and available.
     *
     * @return bool True if all required credentials are set
     */
    public static function isConfigured(): bool
    {
        return Env::get('CMI_CLIENT_ID') !== null
            && Env::get('CMI_STORE_KEY') !== null
            && Env::get('CMI_HASH_KEY') !== null
            && Env::get('CMI_CALLBACK_URL') !== null;
    }
}
