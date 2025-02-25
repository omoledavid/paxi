<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponses;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        return $this->ok('success', new UserResource($user));
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        //
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        // Validate request data
        $validatedData = $request->validate([
            'fname' => 'nullable|string',
            'lname' => 'nullable|string',
            'phone' => 'nullable|string',
            'state' => 'nullable|string',
        ]);

        // Filter out null values before updating
        $updateData = array_filter([
            'sFname' => $validatedData['fname'] ?? $user->sFname,
            'sLname' => $validatedData['lname'] ?? $user->sLname,
            'sPhone' => $validatedData['phone'] ?? $user->sPhone,
            'sState' => $validatedData['state'] ?? $user->sState,
        ], fn($value) => !is_null($value)); // This prevents null values from overriding existing data

        $user->update($updateData);

        // Return updated user data
        return $this->ok('User profile updated successfully', new UserResource($user));
    }

    public function changePassword(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|confirmed',
        ]);
        $oldPassword = passwordHash($validatedData['old_password']);
        if ($oldPassword == $user->sPass) {
            $user->sPass = passwordHash($validatedData['new_password']);
            $user->save();
            return $this->ok('Password changed successfully');
        } else {
            return $this->error('Incorrect password');
        }
    }
    public function changePin(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'old_pin' => 'required',
            'new_pin' => 'required|confirmed|digits:4|int',
        ]);
        if ($validatedData['old_pin'] == $user->sPin) {
            $user->sPin = $validatedData['new_pin'];
            $user->save();
            return $this->ok('Pin changed successfully');
        } else {
            return $this->error('Incorrect Pin');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
    }
}
