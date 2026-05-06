<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\AdBanner;
use App\Models\ApiConfig;
use App\Models\GeneralSetting;
use App\Models\Transaction;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class GeneralController extends Controller
{
    use ApiResponses;

    public function verifyNetwork(Request $request)
    {
        $request->validate([
            'network' => 'nullable|string',
            'phone_number' => 'required|exists:subscribers,sPhone',
        ], [
            'phone_number.exists' => 'The phone number you entered does not exist in our records.',
        ]);
        if (empty($request->network)) {
            $network = '';
        } else {
            $network = $request->network;
        }
        $data = verifyNetwork($request->phone_number, $network);

        return response()->json($data);
    }

    public function agent(Request $request)
    {
        $request->validate([
            'pin' => 'required',
        ]);
        $user = $request->user();
        $tranRef = generateTransactionRef();

        if ($request->pin != $user->sPin) {
            return $this->error('Incorrect pin');
        }
        if ($user->sType == AccountType::AGENT) {
            return $this->error('You are already an agent');
        }
        $generalSetting = GeneralSetting::first();
        $agentUpgragePrice = $generalSetting->agentupgrade;
        $newBal = $user->sWallet - $agentUpgragePrice;
        if ($user->sWallet < $agentUpgragePrice) {
            return $this->error('Insufficient wallet balance');
        }
        try {
            $transaction = Transaction::create([
                'sId' => $user->sId,
                'transref' => $tranRef,
                'servicename' => 'Account Upgrade',
                'servicedesc' => 'Upgrades account to agent account',
                'amount' => $agentUpgragePrice,
                'status' => 0,
                'oldbal' => $user->sWallet,
                'newbal' => $newBal,
                'profit' => 0,
                'date' => now(),
            ]);
            $user->sWallet = $user->sWallet - $agentUpgragePrice;
            $user->sType = AccountType::AGENT;
            $user->save();
        } catch (\Throwable $th) {
            return $this->error($th->getMessage());
        }

        return $this->ok('Account upgraded successfully', $transaction);
    }

    public function vendor(Request $request)
    {
        $request->validate([
            'pin' => 'required',
        ]);
        $user = $request->user();
        $tranRef = generateTransactionRef();

        if ($request->pin != $user->sPin) {
            return $this->error('Incorrect pin');
        }
        if ($user->sType == AccountType::VENDOR) {
            return $this->error('You are already a vendor');
        }
        $generalSetting = GeneralSetting::first();
        $vendorUpgragePrice = $generalSetting->vendorupgrade;
        $newBal = $user->sWallet - $vendorUpgragePrice;
        if ($user->sWallet < $vendorUpgragePrice) {
            return $this->error('Insufficient wallet balance');
        }
        try {
            $transaction = Transaction::create([
                'sId' => $user->sId,
                'transref' => $tranRef,
                'servicename' => 'Account Upgrade',
                'servicedesc' => 'Upgrades account to vendor account',
                'amount' => $vendorUpgragePrice,
                'status' => 0,
                'oldbal' => $user->sWallet,
                'newbal' => $newBal,
                'profit' => 0,
                'date' => now(),
            ]);
            $user->sWallet = $user->sWallet - $vendorUpgragePrice;
            $user->sType = AccountType::VENDOR;
            $user->save();
        } catch (\Throwable $th) {
            return $this->error($th->getMessage());
        }

        return $this->ok('Account upgraded successfully', $transaction);
    }

    public function supportInfo()
    {
        $generalSetting = GeneralSetting::first();

        return $this->ok('success', [
            'phone_number' => $generalSetting->phone,
            'email' => $generalSetting->email,
            'whatsapp' => $generalSetting->whatsapp,
            'whatsapp_group' => $generalSetting->whatsappgroup,
            'facebook' => $generalSetting->facebook,
        ]);
    }

    public function support()
    {
        return $this->ok('success', []);
    }

    public function settings()
    {
        $config = ApiConfig::all();

        return $this->ok('success', [
            'facebook' => gs('facebook'),
            'whatsapp' => gs('whatsapp'),
            'twitter' => gs('twitter'),
            'telegram' => gs('telegram'),
            'instagram' => gs('instagram'),
            'whatsapp_group' => gs('whatsappgroup'),
            'email' => gs('email'),
            'phone' => gs('phone'),
            'terms' => gs('terms'),
            'privacy' => gs('privacy'),
            'about' => gs('about'),
            'google_play_url' => gs('google_play_url'),
            'apple_app_url' => gs('apple_app_url'),
            // Checkout Account (Bank Transfer) deposit settings
            'checkout_deposit_message'    => getConfigValue($config, 'checkoutDepositMessage'),
            'checkout_deposit_cap'        => (float) (getConfigValue($config, 'checkoutDepositCap') ?? 0),
            'checkout_below_cap_fee_type' => getConfigValue($config, 'checkoutBelowCapFeeType') ?? 'fixed',
            'checkout_below_cap_fee'      => (float) (getConfigValue($config, 'checkoutBelowCapFee') ?? 0),
            'checkout_above_cap_fee_type' => getConfigValue($config, 'checkoutAboveCapFeeType') ?? 'fixed',
            'checkout_above_cap_fee'      => (float) (getConfigValue($config, 'checkoutAboveCapFee') ?? 0),
            // Wallet Account (Virtual Account) deposit settings
            'wallet_deposit_message'      => getConfigValue($config, 'walletDepositMessage'),
            'wallet_deposit_cap'          => (float) (getConfigValue($config, 'walletDepositCap') ?? 0),
            'wallet_below_cap_fee_type'   => getConfigValue($config, 'walletBelowCapFeeType') ?? 'fixed',
            'wallet_below_cap_fee'        => (float) (getConfigValue($config, 'walletBelowCapFee') ?? 0),
            'wallet_above_cap_fee_type'   => getConfigValue($config, 'walletAboveCapFeeType') ?? 'fixed',
            'wallet_above_cap_fee'        => (float) (getConfigValue($config, 'walletAboveCapFee') ?? 0),
        ]);
    }

    public function adBanner()
    {
        $banner = AdBanner::find(1);
        $base = rtrim((string) env('ADMIN_PUBLIC_URL', ''), '/');

        return $this->ok('success', [
            'active'    => (bool) ($banner->active ?? false),
            'image_url' => $banner && $banner->image ? $base.'/assets/img/ads/'.$banner->image : null,
            'link_url'  => $banner->link_url ?? null,
        ]);
    }
}
