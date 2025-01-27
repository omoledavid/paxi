<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
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
            'phone_number' => 'required|exists:subscribers,sPhone'
        ],[
            'phone_number.exists' => 'The phone number you entered does not exist in our records.',
        ]);
        if(empty($request->network)){
            $network = '';
        }else{
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
        $tranRef =  generateTransactionRef();

        if($request->pin != $user->sPin){
            return $this->error('Incorrect pin');
        }
        $generalSetting = GeneralSetting::first();
        $agentUpgragePrice = $generalSetting->agentupgrade;
        $newBal = $user->sWallet - $agentUpgragePrice;
        if($user->sWallet < $agentUpgragePrice){
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
        $tranRef =  generateTransactionRef();

        if($request->pin != $user->sPin){
            return $this->error('Incorrect pin');
        }
        $generalSetting = GeneralSetting::first();
        $vendorUpgragePrice = $generalSetting->vendorupgrade;
        $newBal = $user->sWallet - $vendorUpgragePrice;
        if($user->sWallet < $vendorUpgragePrice){
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
}
