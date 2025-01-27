<?php

namespace App\Http\Controllers;

use App\Services\ApiAccess as MonnifyService;
use Illuminate\Http\Request;

class MonnifyController extends Controller
{
    protected $monnifyService;

    public function __construct(MonnifyService $monnifyService)
    {
        $this->monnifyService = $monnifyService;
    }
    public function handleWebhook(Request $request)
    {
        $headers = $request->headers->all();
        $input = $request->getContent();
        $res = json_decode($input, true);

        if (is_array($res)) {
            $hash = $headers["monnify-signature"][0] ?? $headers["Monnify-Signature"][0] ?? null;
            $mnfyEmail = $res["eventData"]["customer"]["email"];
            $amountPaid = $res["eventData"]["amountPaid"];
            $mnfyTransRef = $res["eventData"]["transactionReference"];
            $paymentStatus = $res["eventData"]["paymentStatus"];
            $paidOn = $res["eventData"]["paidOn"];
            $paymentRef = $res["eventData"]["paymentReference"];
            $email = $res["eventData"]['customer']['email'];


            // Verify the transaction
            $check = $this->monnifyService->verifyMonnifyRef($mnfyEmail, $hash, $input);

            if ($check["status"] === "success") {
                $userId = $check["userid"];
                $userBalance = $check["balance"];
                $charges = (float) $check["charges"];

                if ($res["eventType"] === 'SUCCESSFUL_TRANSACTION') {
                    if ($paymentStatus === "PAID") {
                        $chargesText = ($charges == 50 || $charges == "50") ? "N50" : "{$charges}%";
                        $serviceName = "Wallet Topup";
                        $serviceDesc = "Wallet funding of N{$amountPaid} via Monnify bank transfer with a service charge of {$chargesText}";
                        $amountToSave = (float)$amountPaid;

                        if ($charges == 50 || $charges == "50") {
                            $amountToSave -= 50;
                        } else {
                            $amountToSave -= ($amountToSave * ($charges / 100));
                        }
                        $serviceDesc .= ". Your wallet has been credited with {$amountToSave}";

                        $result = $this->monnifyService->recordMonnifyTransaction(
                            $userId,
                            $serviceName,
                            $serviceDesc,
                            $amountToSave,
                            $userBalance,
                            $mnfyTransRef,
                            "0"
                        );

                        // Send email notification
                        $message = $serviceDesc . ". Your transaction reference is {$mnfyTransRef}";
                        $mail = $this->monnifyService->sendEmailNotification($serviceName, $message, $email);

                        return response()->json(['message' => 'Success'], 200);
                    } else {
                        $serviceName = "Wallet Topup";
                        $serviceDesc = "Failed wallet funding of N{$amountPaid} via bank transfer.";
                        $result = $this->monnifyService->recordMonnifyTransaction(
                            $userId,
                            $serviceName,
                            $serviceDesc,
                            $amountPaid,
                            $userBalance,
                            $mnfyTransRef,
                            "1"
                        );

                        return response()->json(['message' => 'Fail'], 400);
                    }
                }
            }

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json(['message' => 'Invalid Request'], 401);
    }
}
