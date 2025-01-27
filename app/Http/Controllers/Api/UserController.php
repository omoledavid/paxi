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
            'fname' => 'required|string',
        ]);

        // Handle file uploads
        if ($request->hasFile('profile_image')) {
            $path = $request->file('profile_image')->store('profile-images', 'public');
            $validatedData['profile_image'] = url('storage/' . $path);
        }

        if ($request->hasFile('resume')) {
            $path = $request->file('resume')->store('resumes', 'public');
            $validatedData['resume'] = url('storage/' . $path);
        }

        // Add user ID to data
        $validatedData['user_id'] = $user->id;

        // Update or create profile
        if ($user->profile == null) {
            $user->profile()->create($validatedData);
        } else {
            $user->profile()->update($validatedData);
        }

        // Update user's name
        $user->name = $request->name;
        $user->save();

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
