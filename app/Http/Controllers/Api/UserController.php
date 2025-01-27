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
            'user_title' => 'required|min:4',
            'skills' => 'required|array|min:1',
            'languages' => 'required|array|min:1',
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:2048',
            'cover_letter' => 'required|string|max:255',
            'location' => 'required|string|max:50',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'bio' => 'required|string|max:255',
            'extra_info' => 'nullable|string|max:255',
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


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
    }
}
